<?php

namespace BoldWeb\StatamicAiAssistant\Controllers;

use BoldWeb\StatamicAiAssistant\Jobs\PlanEntriesJob;
use BoldWeb\StatamicAiAssistant\Services\AbstractAiService;
use BoldWeb\StatamicAiAssistant\Services\EntryGenerationBatchService;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Support\EntryCreationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

class EntryGeneratorController
{
    private EntryGeneratorService $generator;

    private AbstractAiService $aiService;

    private EntryGenerationBatchService $entryBatch;

    public function __construct(
        EntryGeneratorService $generator,
        AbstractAiService $aiService,
        EntryGenerationBatchService $entryBatch,
    ) {
        $this->generator = $generator;
        $this->aiService = $aiService;
        $this->entryBatch = $entryBatch;
    }

    /**
     * Return available collections and their blueprints.
     */
    public function collections(): JsonResponse
    {
        return response()->json(['collections' => $this->generator->getCollectionsCatalog()]);
    }

    /**
     * Return the field schema for a given collection + blueprint.
     */
    public function blueprintFields(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'required|string',
            'blueprint' => 'nullable|string',
        ]);

        $collection = Collection::findByHandle($data['collection']);

        if (! $collection) {
            return response()->json(['error' => __('Collection not found.')], 404);
        }

        $visible = $collection->entryBlueprints()->reject->hidden();

        $blueprint = $data['blueprint']
            ? $visible->keyBy->handle()->get($data['blueprint'])
            : $visible->first();

        if (! $blueprint) {
            return response()->json(['error' => __('Blueprint not found.')], 404);
        }

        $schema = $this->generator->getFieldSchemaForPreview($blueprint);

        return response()->json([
            'blueprint' => $blueprint->handle(),
            'fields' => $schema,
        ]);
    }

    /**
     * Generate content from a prompt (does NOT create the entry yet).
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'nullable|string',
            'blueprint' => 'nullable|string',
            'prompt' => 'required|string|min:10',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'auto_resolve_target' => 'nullable|boolean',
        ]);

        $attachmentContent = null;

        if ($file = $request->file('attachment')) {
            $attachmentContent = $this->extractFileContent($file);
        }

        $autoResolve = $request->boolean('auto_resolve_target');

        if (! $autoResolve && ! $request->filled('collection')) {
            return response()->json(['error' => __('Please select a collection.')], 422);
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();

        try {
            if ($autoResolve) {
                $resolved = $this->generator->resolveTargetFromPrompt(
                    $data['prompt'],
                    $attachmentContent,
                );
                $collectionHandle = $resolved['collection'];
                $blueprintHandle = $resolved['blueprint'];
            } else {
                $collectionHandle = (string) $request->input('collection');
                $blueprintHandle = (string) ($request->input('blueprint') ?? '');
            }

            $result = $this->generator->generateContent(
                $collectionHandle,
                $blueprintHandle,
                $data['prompt'],
                $locale,
                $attachmentContent,
            );

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'displayData' => $result['displayData'],
                'warnings' => $result['warnings'],
                'resolved_collection' => $collectionHandle,
                'resolved_blueprint' => $blueprintHandle,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Entry generation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => __('Entry generation failed. Please try again.')], 500);
        }
    }

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
            'prompt' => 'required|string|min:10',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'auto_resolve_target' => 'nullable|boolean',
        ]);

        $attachmentContent = null;

        if ($file = $request->file('attachment')) {
            $attachmentContent = $this->extractFileContent($file);
        }

        $autoResolve = $request->boolean('auto_resolve_target');

        if (! $autoResolve && ! $request->filled('collection')) {
            return response()->stream(function () {
                echo json_encode(['type' => 'error', 'message' => __('Please select a collection.')], JSON_UNESCAPED_UNICODE)."\n";
                @ob_flush();
                flush();
            }, 422, $this->ndjsonStreamHeaders());
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();
        $collectionHandle = $autoResolve ? null : (string) $request->input('collection');
        $blueprintHandle = $autoResolve ? null : (string) ($request->input('blueprint') ?? '');

        // Resolve the per-request entry cap HERE, while the CP user is known —
        // the planner runs in a queued job with no authenticated user, so it
        // reads this value off the session instead of re-checking permissions.
        $maxPlanEntries = EntryCreationPolicy::maxPlanEntries();

        return response()->stream(function () use ($data, $attachmentContent, $autoResolve, $locale, $collectionHandle, $blueprintHandle, $maxPlanEntries) {
            $emit = static function (array $payload): void {
                echo json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                if ($err = $this->entryBatchQueueSetupError()) {
                    $emit(['type' => 'error', 'message' => $err]);

                    return;
                }

                $emit(['type' => 'planning']);

                $sessionId = $this->entryBatch->initPlanningSession(
                    $locale,
                    $attachmentContent,
                    (string) $data['prompt'],
                    $autoResolve,
                    [
                        'appendix' => '',
                        'warnings' => [],
                        'preferred' => new PreferredAssetPaths,
                        'appended_to_prompts' => false,
                    ],
                    $collectionHandle,
                    $blueprintHandle,
                    $maxPlanEntries,
                );

                PlanEntriesJob::dispatch($sessionId);

                $emit([
                    'type' => 'batch',
                    'session_id' => $sessionId,
                    'async' => true,
                ]);

                $emit(['type' => 'done', 'success' => true]);
            } catch (\RuntimeException $e) {
                $emit(['type' => 'error', 'message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                Log::error('Entry generation stream failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $emit(['type' => 'error', 'message' => __('Entry generation failed. Please try again.')]);
            }
        }, 200, $this->ndjsonStreamHeaders());
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
     * Persist generated data: creates a new entry, or — when `entry_id` is
     * supplied — patches that existing entry via the dedicated update path.
     */
    public function createEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'required_without:entry_id|string',
            'blueprint' => 'nullable|string',
            'data' => 'present|array',
            'entry_id' => 'nullable|string',
        ]);

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();
        $entryId = isset($data['entry_id']) && is_string($data['entry_id']) && $data['entry_id'] !== ''
            ? $data['entry_id']
            : null;

        if ($data['data'] === []) {
            return response()->json([
                'error' => $entryId !== null
                    ? __('The AI did not produce any field changes. Try a more specific prompt.')
                    : __('No content to save.'),
            ], 422);
        }

        try {
            if ($entryId !== null) {
                $entry = $this->generator->updateEntryFromData($entryId, $data['data']);
                $operation = 'updated';
            } else {
                $entry = $this->generator->saveEntry(
                    $data['collection'],
                    $data['blueprint'] ?? '',
                    $locale,
                    $data['data'],
                );
                $operation = 'created';
            }

            return response()->json([
                'success' => true,
                'operation' => $operation,
                'entry_id' => $entry->id(),
                'edit_url' => $entry->editUrl(),
                'title' => $entry->value('title') ?? '',
            ]);
        } catch (\Exception $e) {
            Log::error('Entry persist failed', ['error' => $e->getMessage(), 'entry_id' => $entryId]);

            return response()->json(['error' => __('Saving the entry failed. Please try again.')], 500);
        }
    }

    /**
     * Regenerate a single field value.
     */
    public function regenerateField(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field_type' => 'required|string',
            'current_value' => 'nullable|string',
            'prompt' => 'required|string',
        ]);

        try {
            if ($data['field_type'] === 'bard') {
                $content = $this->aiService->generateHtmlRefactorFromPrompt(
                    $data['current_value'] ?? '',
                    $data['prompt'],
                );
            } else {
                $content = $this->aiService->generateContentFromPrompt($data['prompt']);
            }

            return response()->json(['value' => $content]);
        } catch (\Exception $e) {
            Log::error('Field regeneration failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => __('Field regeneration failed. Please try again.')], 500);
        }
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
     * Cancel a running batch (jobs still in chain will mark entries cancelled when they start).
     */
    public function generateBatchCancel(string $sessionId): JsonResponse
    {
        $this->entryBatch->cancelSession($sessionId);

        return response()->json(['ok' => true]);
    }

    private function entryBatchQueueSetupError(): ?string
    {
        if (config('queue.default') !== 'sync') {
            return null;
        }

        return (string) __(
            'BOLD agent batch generation requires an asynchronous queue. Set QUEUE_CONNECTION=redis in your .env, then start php artisan horizon or php artisan queue:work. The sync driver cannot be used.',
        );
    }

    /**
     * Extract text content from an uploaded file.
     */
    private function extractFileContent(\Illuminate\Http\UploadedFile $file): ?string
    {
        try {
            $extension = strtolower($file->getClientOriginalExtension());

            if ($extension === 'pdf') {
                $parser = new \Smalot\PdfParser\Parser;
                $pdf = $parser->parseFile($file->getRealPath());
                $content = $pdf->getText();
            } else {
                $content = file_get_contents($file->getRealPath());
            }

            if (! $content) {
                return null;
            }

            // Truncate to ~8000 words to stay within LLM context window
            $words = explode(' ', $content);

            if (count($words) > 8000) {
                $content = implode(' ', array_slice($words, 0, 8000))."\n\n[Content truncated...]";
            }

            return $content;
        } catch (\Exception $e) {
            Log::warning('PDF extraction failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
