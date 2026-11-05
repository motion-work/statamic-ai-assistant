<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Controllers\Concerns\ReadsAttachments;
use MotionWork\StatamicAiAssistant\Jobs\PlanEntriesJob;
use MotionWork\StatamicAiAssistant\Services\DocumentTextExtractor;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy;
use MotionWork\StatamicAiAssistant\Support\QueueReadiness;
use MotionWork\StatamicAiAssistant\Support\WebSearch;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AI agent sessions: starting one (streamed NDJSON), polling its progress,
 * follow-up turns, resuming from the browser's transcript, cancelling; and
 * the user's advanced-tools toggle.
 */
final class AgentSessionController
{
    use ReadsAttachments;

    public function __construct(
        private EntryGenerationBatchService $entryBatch,
        private DocumentTextExtractor $documents,
    ) {}

    /**
     * Streaming endpoint kicks off async planning + entry generation.
     *
     * The HTTP request itself does NO LLM work — it just creates the Redis-backed
     * batch session and dispatches PlanEntriesJob. The browser then polls
     * GET generate-progress/{sessionId} to surface incremental cards as the
     * agentic planner discovers articles and dispatches one GeneratePlannedEntryJob each.
     *
     * NDJSON event stream emitted by this endpoint:
     *   {type:"planning"}
     *   {type:"batch", session_id, async:true}    // start polling
     *   {type:"done", success:true}
     *   {type:"error", message}                    // fatal pre-dispatch error
     */
    public function generateStream(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'collection' => 'nullable|string',
            'blueprint' => 'nullable|string',
            'prompt' => 'required|string|min:3',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'file|mimes:pdf,txt|max:10240',
            'auto_resolve_target' => 'nullable|boolean',
            'web_search' => 'nullable|boolean',
        ]);

        $attachmentContent = $this->attachmentContentFromRequest($request);

        $autoResolve = $request->boolean('auto_resolve_target');

        if (! $autoResolve && ! $request->filled('collection')) {
            return response()->stream(
                fn () => self::emitNdjson(['type' => 'error', 'message' => __('Please select a collection.')]),
                422,
                $this->ndjsonStreamHeaders(),
            );
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();
        $collectionHandle = $autoResolve ? null : (string) $request->input('collection');
        $blueprintHandle = $autoResolve ? null : (string) ($request->input('blueprint') ?? '');

        // Resolve the per-request entry cap and the advanced-tools state HERE,
        // while the CP user is known — the planner runs in a queued job with no
        // authenticated user, so it reads these off the session instead of
        // re-checking permissions. Advanced tools require the access grant AND
        // the user's explicit opt-in toggle.
        $maxPlanEntries = EntryCreationPolicy::maxPlanEntries();
        $advancedTools = AgentAccess::advancedToolsActive();
        $webSearch = $this->webSearchRequested($request);

        $startSession = function () use ($locale, $attachmentContent, $data, $collectionHandle, $blueprintHandle, $maxPlanEntries, $advancedTools, $webSearch): string {
            $sessionId = $this->entryBatch->initPlanningSession(
                $locale,
                $attachmentContent,
                (string) $data['prompt'],
                $collectionHandle,
                $blueprintHandle,
                $maxPlanEntries,
                $advancedTools,
            );

            if ($webSearch) {
                $this->entryBatch->setWebSearch($sessionId, true);
            }

            return $sessionId;
        };

        return response()->stream(fn () => $this->streamPlanning($startSession), 200, $this->ndjsonStreamHeaders());
    }

    /**
     * Starts the planning session and queues the planner, reporting each step
     * as an NDJSON line the CP drawer reads.
     *
     * @param  \Closure(): string  $startSession
     */
    private function streamPlanning(\Closure $startSession): void
    {
        try {
            if ($err = (new QueueReadiness)->setupError()) {
                self::emitNdjson(['type' => 'error', 'message' => $err]);

                return;
            }

            self::emitNdjson(['type' => 'planning']);

            $sessionId = $startSession();
            PlanEntriesJob::dispatch($sessionId);

            self::emitNdjson(['type' => 'batch', 'session_id' => $sessionId, 'async' => true]);
            self::emitNdjson(['type' => 'done', 'success' => true]);
        } catch (\RuntimeException $e) {
            self::emitNdjson(['type' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Entry generation stream failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            self::emitNdjson(['type' => 'error', 'message' => __('Entry generation failed. Please try again.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function emitNdjson(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    /**
     * @return array<string, string>
     */
    private function ndjsonStreamHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * Poll queued batch generation (same entry card shape as stream, plus stream_delta per tick).
     */
    public function generateBatchProgress(string $sessionId): JsonResponse
    {
        $snap = $this->entryBatch->snapshotForProgress($sessionId);
        if ($snap === null) {
            return response()->json(['error' => __('Session not found.')], 404);
        }

        return response()->json($snap);
    }

    /**
     * Continue an existing chat session with a follow-up message. Re-opens the
     * session, appends the user turn, and re-runs the planner with the full
     * transcript as context. Returns immediately; the browser keeps polling
     * generate-progress. Plain JSON (no NDJSON) — the job does the async work.
     */
    public function generateContinue(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate([
            'prompt' => 'required|string|min:1',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'file|mimes:pdf,txt|max:10240',
            'web_search' => 'nullable|boolean',
        ]);

        $session = $this->entryBatch->getSession($sessionId);
        if (! is_array($session)) {
            return response()->json(['error' => __('This chat has expired. Start a new request.')], 404);
        }

        if (($session['planning_status'] ?? '') === 'planning') {
            return response()->json(['error' => __('The agent is still working on the previous message.')], 409);
        }

        if ($err = (new QueueReadiness)->setupError()) {
            return response()->json(['error' => $err], 422);
        }

        // Re-resolve the per-request cap and advanced-tools state here, where
        // the CP user is known (the queued planner has no authenticated user).
        $maxPlanEntries = EntryCreationPolicy::maxPlanEntries();
        $advancedTools = AgentAccess::advancedToolsActive();

        $attachmentContent = $this->attachmentContentFromRequest($request);

        if (! $this->entryBatch->reopenForFollowUp($sessionId, (string) $data['prompt'], $maxPlanEntries, $advancedTools, $attachmentContent, $this->webSearchRequested($request))) {
            return response()->json(['error' => __('This chat has expired. Start a new request.')], 404);
        }

        PlanEntriesJob::dispatch($sessionId);

        return response()->json(['ok' => true, 'session_id' => $sessionId]);
    }

    /**
     * Resume a chat whose server session expired. Chat history lives only in
     * the user's browser; this reseeds a fresh transient session from the
     * client-provided transcript, appends the new user turn, and re-runs the
     * planner — the browser then polls generate-progress as usual.
     */
    public function generateResume(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => 'required|string|min:1',
            'transcript' => 'required|array|min:1|max:80',
            'transcript.*.role' => 'required|string|in:user,assistant',
            'transcript.*.text' => 'required|string|max:20000',
            'transcript.*.kind' => 'nullable|string|max:32',
            'transcript.*.citations' => 'nullable|array|max:30',
            'transcript.*.citations.*.url' => 'required|string|max:2048',
            'transcript.*.citations.*.title' => 'nullable|string|max:500',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'file|mimes:pdf,txt|max:10240',
            'web_search' => 'nullable|boolean',
        ]);

        if ($err = (new QueueReadiness)->setupError()) {
            return response()->json(['error' => $err], 422);
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();
        $maxPlanEntries = EntryCreationPolicy::maxPlanEntries();
        $advancedTools = AgentAccess::advancedToolsActive();

        $attachmentContent = $this->attachmentContentFromRequest($request);

        $sessionId = $this->entryBatch->restoreSessionFromTranscript($locale, $data['transcript'], $advancedTools);
        $this->entryBatch->reopenForFollowUp($sessionId, (string) $data['prompt'], $maxPlanEntries, $advancedTools, $attachmentContent, $this->webSearchRequested($request));

        PlanEntriesJob::dispatch($sessionId);

        return response()->json(['ok' => true, 'session_id' => $sessionId]);
    }

    /**
     * The editor switched web search on for this message, and it can be
     * offered (OpenRouter, not regional, enabled in config).
     */
    private function webSearchRequested(Request $request): bool
    {
        return $request->boolean('web_search') && WebSearch::available();
    }

    /**
     * Cancel a running batch (jobs still in chain will mark entries cancelled when they start).
     */
    public function generateBatchCancel(string $sessionId): JsonResponse
    {
        $this->entryBatch->cancelSession($sessionId);

        return response()->json(['ok' => true]);
    }
}
