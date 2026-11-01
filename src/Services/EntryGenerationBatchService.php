<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Services\Concerns\TracksEntryJobs;
use MotionWork\StatamicAiAssistant\Services\Concerns\TracksPlanning;

/**
 * Redis-backed session for queued AI agent entry generation (post-planner).
 *
 * @phpstan-type EntryRow array{
 *   id: string,
 *   index: int,
 *   collection: string,
 *   blueprint: string,
 *   label: string,
 *   prompt: string,
 *   collection_title: string,
 *   blueprint_title: string,
 *   status: string,
 *   token_length: int,
 *   stream_buffer: string,
 *   data: mixed,
 *   displayData: mixed,
 *   warnings: array<int, string>,
 *   error: ?string,
 *   started_at: ?string,
 *   completed_at: ?string,
 * }
 */
class EntryGenerationBatchService
{
    use TracksEntryJobs;
    use TracksPlanning;

    private const CACHE_TTL_HOURS = 24;

    private const LOCK_TTL_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    /**
     * Create a session up-front for the agentic planner job: status=running, planning_status=planning, no entries yet.
     * Entries are appended later via addPlannedEntry as the planner LLM calls create_entry_job.
     *
     * With a collection the target is preselected; without one the planner
     * resolves it (auto_resolve). Preferred assets are added later through
     * appendPreferredAssetPaths().
     */
    public function initPlanningSession(
        string $locale,
        ?string $attachmentContent,
        string $prompt,
        ?string $collectionHandle = null,
        ?string $blueprintHandle = null,
        ?int $maxPlanEntries = null,
        bool $advancedTools = false,
    ): string {
        $id = (string) Str::uuid();

        $session = [
            'id' => $id,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'status' => 'running',
            'planning_status' => 'planning',
            'planner_error' => null,
            // Set when the planner answered a read-only question (no create/update).
            // A successful informational result, distinct from planner_error.
            'planner_answer' => null,
            // Conversation history for the multi-turn chat. One entry per visible
            // turn: {role, text, entry_ids, kind}. Seeded with the first user turn
            // so the planner has uniform input from turn 1.
            'transcript' => [
                ['role' => 'user', 'text' => $prompt, 'entry_ids' => [], 'kind' => null],
            ],
            'auto_resolve' => $collectionHandle === null,
            'prompt' => $prompt,
            'collection_handle' => $collectionHandle ?? '',
            'blueprint_handle' => $blueprintHandle ?? '',
            // Effective per-request entry cap, resolved from the CP user's role
            // at request time (see EntryCreationPolicy). Null → planner falls
            // back to the configured cap. Non-super users get 1 when limited.
            'max_plan_entries' => $maxPlanEntries !== null ? max(1, $maxPlanEntries) : null,
            // Whether the requesting CP user holds the 'advanced_tools' access
            // feature, resolved at request time like max_plan_entries — the
            // queued planner has no authenticated user to check against.
            'advanced_tools' => $advancedTools,
            'locale' => $locale,
            'attachment_content' => $attachmentContent,
            'plan_warnings' => [],
            'url_augmentation' => [
                'appendix' => '',
                'warnings' => [],
                'preferred_paths' => [],
                'appended_to_prompts' => false,
            ],
            'entry_order' => [],
            'entries' => [],
            // Non-entry operations (structural changes etc.) completed this turn.
            'operations' => [],
            'counts' => [
                'pending' => 0,
                'running' => 0,
                'completed' => 0,
                'failed' => 0,
            ],
        ];

        $this->persist($session);

        return $id;
    }

    /**
     * Recreate a transient session from a client-provided conversation
     * transcript, so a chat whose cached session expired can continue where it
     * left off. Chat history is stored only in the user's browser — this
     * reseeds the server's working copy from it, in the same terminal state
     * reopenForFollowUp() expects ('completed'/'planned', no running turn).
     *
     * @param  array<int, array{role: string, text: string, kind: ?string}>  $transcript
     */
    public function restoreSessionFromTranscript(string $locale, array $transcript, bool $advancedTools = false): string
    {
        $turns = [];
        foreach ($transcript as $turn) {
            if (! is_array($turn)) {
                continue;
            }
            $role = $turn['role'] ?? '';
            $text = trim((string) ($turn['text'] ?? ''));
            if (! in_array($role, ['user', 'assistant'], true) || $text === '') {
                continue;
            }
            $turns[] = [
                'role' => $role,
                'text' => $text,
                // Prior entry rows no longer exist in the reseeded session.
                'entry_ids' => [],
                'kind' => $role === 'assistant' ? (string) ($turn['kind'] ?? 'summary') : null,
            ];
        }

        $id = (string) Str::uuid();

        $session = [
            'id' => $id,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'status' => 'completed',
            'planning_status' => 'planned',
            'planner_error' => null,
            'planner_answer' => null,
            'transcript' => $turns,
            'auto_resolve' => true,
            'prompt' => '',
            'collection_handle' => '',
            'blueprint_handle' => '',
            'max_plan_entries' => null,
            'advanced_tools' => $advancedTools,
            'locale' => $locale,
            'attachment_content' => null,
            'plan_warnings' => [],
            'url_augmentation' => [
                'appendix' => '',
                'warnings' => [],
                'preferred_paths' => [],
                'appended_to_prompts' => false,
            ],
            'entry_order' => [],
            'entries' => [],
            'operations' => [],
            'counts' => [
                'pending' => 0,
                'running' => 0,
                'completed' => 0,
                'failed' => 0,
            ],
        ];

        $this->persist($session);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSession(string $sessionId): ?array
    {
        $session = Cache::get($this->cacheKey($sessionId));

        return is_array($session) ? $session : null;
    }

    /**
     * Snapshot for GET progress: moves per-entry stream_buffer into stream_delta for the client.
     *
     * @return array<string, mixed>|null
     */
    public function snapshotForProgress(string $sessionId): ?array
    {
        $out = null;

        $this->update($sessionId, function (array $session) use (&$out): array {
            $entriesOut = [];
            foreach ($session['entry_order'] ?? [] as $eid) {
                $row = $session['entries'][$eid] ?? null;
                if (! is_array($row)) {
                    continue;
                }
                $delta = (string) ($row['stream_buffer'] ?? '');
                $session['entries'][$eid]['stream_buffer'] = '';

                $entriesOut[] = [
                    'id' => (string) ($row['id'] ?? $eid),
                    'index' => (int) ($row['index'] ?? 0),
                    'collection' => (string) ($row['collection'] ?? ''),
                    'blueprint' => (string) ($row['blueprint'] ?? ''),
                    'label' => (string) ($row['label'] ?? ''),
                    'prompt' => (string) ($row['prompt'] ?? ''),
                    'collection_title' => (string) ($row['collection_title'] ?? ''),
                    'blueprint_title' => (string) ($row['blueprint_title'] ?? ''),
                    'entry_id' => $row['entry_id'] ?? null,
                    'operation' => (string) ($row['operation'] ?? 'create'),
                    'target_site' => $row['target_site'] ?? null,
                    'target_site_label' => $row['target_site_label'] ?? null,
                    'saved_entry' => $row['saved_entry'] ?? null,
                    'status' => (string) ($row['status'] ?? 'pending'),
                    'token_length' => (int) ($row['token_length'] ?? 0),
                    'stream_delta' => $delta,
                    'data' => $row['data'] ?? null,
                    'displayData' => $row['displayData'] ?? null,
                    'warnings' => is_array($row['warnings'] ?? null) ? $row['warnings'] : [],
                    'error' => $row['error'] ?? null,
                ];
            }

            // Drain the planner activity buffer (delta pattern, like entry stream_buffer).
            $activity = is_array($session['planner_activity_buffer'] ?? null) ? $session['planner_activity_buffer'] : [];
            $session['planner_activity_buffer'] = [];

            $out = [
                'session_id' => (string) ($session['id'] ?? ''),
                'status' => (string) ($session['status'] ?? 'running'),
                'planning_status' => (string) ($session['planning_status'] ?? 'planned'),
                'planner_error' => $session['planner_error'] ?? null,
                'planner_answer' => $session['planner_answer'] ?? null,
                'transcript' => array_values($session['transcript'] ?? []),
                'auto_resolve' => (bool) ($session['auto_resolve'] ?? true),
                'prompt' => (string) ($session['prompt'] ?? ''),
                'entries' => $entriesOut,
                'warnings' => $session['plan_warnings'] ?? [],
                'planner_activity' => array_values($activity),
                'operations' => array_values(is_array($session['operations'] ?? null) ? $session['operations'] : []),
            ];

            return $session;
        });

        return $out;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function update(string $sessionId, callable $mutator): void
    {
        $lock = Cache::lock($this->lockKey($sessionId), self::LOCK_TTL_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS, function () use ($sessionId, $mutator): void {
                $session = $this->getSession($sessionId);
                if (! is_array($session)) {
                    Log::notice('Entry generation batch session not found', ['session_id' => $sessionId]);

                    return;
                }

                $session = $mutator($session);
                $session['updated_at'] = now()->toIso8601String();

                $this->persist($session);
            });
        } catch (LockTimeoutException $e) {
            Log::error('Entry generation batch session lock timeout', [
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function persist(array $session): void
    {
        $id = (string) ($session['id'] ?? '');
        if ($id === '') {
            return;
        }
        Cache::put($this->cacheKey($id), $session, now()->addHours(self::CACHE_TTL_HOURS));
    }

    private function cacheKey(string $sessionId): string
    {
        return "ai_entry_batch:session:{$sessionId}";
    }

    private function lockKey(string $sessionId): string
    {
        return "ai_entry_batch:session:{$sessionId}:lock";
    }
}
