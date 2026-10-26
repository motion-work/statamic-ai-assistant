<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Redis-backed session for queued BOLD agent entry generation (post-planner).
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
    private const CACHE_TTL_HOURS = 24;

    private const LOCK_TTL_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    /**
     * Create a session up-front for the agentic planner job: status=running, planning_status=planning, no entries yet.
     * Entries are appended later via addPlannedEntry as the planner LLM calls create_entry_job.
     *
     * @param  array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts?: bool}  $urlAugmentation
     */
    public function initPlanningSession(
        string $locale,
        ?string $attachmentContent,
        string $prompt,
        bool $autoResolve,
        array $urlAugmentation,
        ?string $collectionHandle = null,
        ?string $blueprintHandle = null,
        ?int $maxPlanEntries = null,
    ): string {
        $id = (string) Str::uuid();
        $preferred = $urlAugmentation['preferred'] ?? new PreferredAssetPaths;
        $preferredPaths = $preferred instanceof PreferredAssetPaths
            ? $preferred->remainingEntries()
            : [];

        $session = [
            'id' => $id,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'status' => 'running',
            'planning_status' => 'planning',
            'planner_error' => null,
            'auto_resolve' => $autoResolve,
            'prompt' => $prompt,
            'collection_handle' => $collectionHandle ?? '',
            'blueprint_handle' => $blueprintHandle ?? '',
            // Effective per-request entry cap, resolved from the CP user's role
            // at request time (see EntryCreationPolicy). Null → planner falls
            // back to the configured cap. Non-super users get 1 when limited.
            'max_plan_entries' => $maxPlanEntries !== null ? max(1, $maxPlanEntries) : null,
            'locale' => $locale,
            'attachment_content' => $attachmentContent,
            'plan_warnings' => [],
            'url_augmentation' => [
                'appendix' => (string) ($urlAugmentation['appendix'] ?? ''),
                'warnings' => array_values(array_filter($urlAugmentation['warnings'] ?? [], 'is_string')),
                'preferred_paths' => $preferredPaths,
                'appended_to_prompts' => (bool) ($urlAugmentation['appended_to_prompts'] ?? false),
            ],
            'entry_order' => [],
            'entries' => [],
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
     * Append a freshly-decorated plan entry. Returns true if the row was added
     * (false when the cap is reached, the session is missing/cancelled, or the
     * id is already known — duplicate-safe so the planner can retry safely).
     *
     * `entry_id` (when set) signals an UPDATE row: the worker patches that
     * existing Statamic entry instead of creating a new one.
     *
     * @param  array{id: string, collection: string, blueprint: string, prompt: string, label: string, collection_title: string, blueprint_title: string, entry_id?: ?string}  $decoratedRow
     */
    public function addPlannedEntry(string $sessionId, array $decoratedRow, int $cap = 0): bool
    {
        $added = false;

        $this->update($sessionId, function (array $session) use ($decoratedRow, $cap, &$added): array {
            if (($session['status'] ?? '') === 'cancelled') {
                return $session;
            }

            $eid = (string) ($decoratedRow['id'] ?? '');
            if ($eid === '') {
                return $session;
            }
            if (isset($session['entries'][$eid])) {
                return $session;
            }
            $current = is_array($session['entry_order'] ?? null) ? count($session['entry_order']) : 0;
            if ($cap > 0 && $current >= $cap) {
                return $session;
            }

            $entryId = isset($decoratedRow['entry_id']) && is_string($decoratedRow['entry_id']) && $decoratedRow['entry_id'] !== ''
                ? $decoratedRow['entry_id']
                : null;

            $session['entry_order'][] = $eid;
            $session['entries'][$eid] = [
                'id' => $eid,
                'index' => $current,
                'collection' => (string) ($decoratedRow['collection'] ?? ''),
                'blueprint' => (string) ($decoratedRow['blueprint'] ?? ''),
                'label' => (string) ($decoratedRow['label'] ?? ''),
                'prompt' => (string) ($decoratedRow['prompt'] ?? ''),
                'collection_title' => (string) ($decoratedRow['collection_title'] ?? ''),
                'blueprint_title' => (string) ($decoratedRow['blueprint_title'] ?? ''),
                'entry_id' => $entryId,
                'operation' => $entryId !== null ? 'update' : 'create',
                'status' => 'pending',
                'token_length' => 0,
                'stream_buffer' => '',
                'data' => null,
                'displayData' => null,
                'warnings' => [],
                'error' => null,
                'started_at' => null,
                'completed_at' => null,
            ];
            $session['counts'] = $this->recount($session);
            $added = true;

            return $session;
        });

        return $added;
    }

    public function markPlanningComplete(string $sessionId): void
    {
        $this->update($sessionId, function (array $session): array {
            if (($session['planning_status'] ?? '') === 'planning_failed') {
                return $session;
            }
            $session['planning_status'] = 'planned';

            return $session;
        });

        $this->markCompletedIfDone($sessionId);
    }

    public function markPlanningFailed(string $sessionId, string $message): void
    {
        $this->update($sessionId, function (array $session) use ($message): array {
            $session['planning_status'] = 'planning_failed';
            $session['planner_error'] = $message;
            // No more entries will be added; the session can complete based on existing entries (or just none).
            return $session;
        });

        $this->markCompletedIfDone($sessionId);
    }

    public function appendPlannerWarning(string $sessionId, string $warning): void
    {
        if ($warning === '') {
            return;
        }
        $this->update($sessionId, function (array $session) use ($warning): array {
            $existing = is_array($session['plan_warnings'] ?? null) ? $session['plan_warnings'] : [];
            $existing[] = $warning;
            $session['plan_warnings'] = array_values($existing);

            return $session;
        });
    }

    /**
     * Append one human-readable planner step ("Reading …") to a bounded buffer that
     * snapshotForProgress() drains — so the CP shows a live activity feed while the
     * planner works, instead of a static "0 entries" message.
     */
    public function appendPlannerActivity(string $sessionId, string $line): void
    {
        if (trim($line) === '') {
            return;
        }
        $this->update($sessionId, function (array $session) use ($line): array {
            $buffer = is_array($session['planner_activity_buffer'] ?? null) ? $session['planner_activity_buffer'] : [];
            $buffer[] = $line;
            // Bound the buffer in case the frontend is not polling (e.g. drawer closed).
            $session['planner_activity_buffer'] = array_slice($buffer, -100);

            return $session;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSession(string $sessionId): ?array
    {
        $session = Cache::get($this->cacheKey($sessionId));

        return is_array($session) ? $session : null;
    }

    public function isCancelled(string $sessionId): bool
    {
        $session = $this->getSession($sessionId);

        return ! is_array($session) || ($session['status'] ?? '') === 'cancelled';
    }

    public function cancelSession(string $sessionId): void
    {
        $this->update($sessionId, function (array $session): array {
            $session['status'] = 'cancelled';

            return $session;
        });
    }

    /**
     * @return array{appendix: string, warnings: array<int, string>, preferred: PreferredAssetPaths, appended_to_prompts: bool}
     */
    public function buildPrefetchedUrlAugmentation(string $sessionId): array
    {
        $session = $this->getSession($sessionId);
        if (! is_array($session)) {
            return [
                'appendix' => '',
                'warnings' => [],
                'preferred' => new PreferredAssetPaths,
                'appended_to_prompts' => false,
            ];
        }

        $ua = $session['url_augmentation'] ?? [];
        $paths = is_array($ua['preferred_paths'] ?? null) ? $ua['preferred_paths'] : [];

        return [
            'appendix' => (string) ($ua['appendix'] ?? ''),
            'warnings' => array_values(array_filter($ua['warnings'] ?? [], 'is_string')),
            'preferred' => new PreferredAssetPaths($paths),
            'appended_to_prompts' => (bool) ($ua['appended_to_prompts'] ?? false),
        ];
    }

    /**
     * Persist remaining preferred paths after a job mutates the shared queue.
     *
     * @param  array<int, array{container: string, path: string}>  $preferredPaths
     */
    public function setPreferredPaths(string $sessionId, array $preferredPaths): void
    {
        $this->update($sessionId, function (array $session) use ($preferredPaths): array {
            if (! isset($session['url_augmentation']) || ! is_array($session['url_augmentation'])) {
                $session['url_augmentation'] = [
                    'appendix' => '',
                    'warnings' => [],
                    'preferred_paths' => [],
                    'appended_to_prompts' => false,
                ];
            }
            $session['url_augmentation']['preferred_paths'] = array_values($preferredPaths);

            return $session;
        });
    }

    public function markEntryGenerating(string $sessionId, string $entryId): void
    {
        $this->update($sessionId, function (array $session) use ($entryId): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $session['entries'][$entryId]['status'] = 'generating';
            $session['entries'][$entryId]['stream_buffer'] = '';
            $session['entries'][$entryId]['started_at'] = $session['entries'][$entryId]['started_at']
                ?? now()->toIso8601String();
            $session['counts'] = $this->recount($session);

            return $session;
        });
    }

    /**
     * Accumulate stream deltas for CP polling (token + field-key UX). Buffer is
     * flushed into stream_delta on each progress snapshot.
     */
    public function recordStreamDelta(string $sessionId, string $entryId, string $delta): void
    {
        if ($delta === '') {
            return;
        }

        $this->update($sessionId, function (array $session) use ($entryId, $delta): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $row = &$session['entries'][$entryId];
            $row['token_length'] = (int) ($row['token_length'] ?? 0) + strlen($delta);
            $buf = (string) ($row['stream_buffer'] ?? '');
            $buf .= $delta;
            if (strlen($buf) > 12000) {
                $buf = substr($buf, -12000);
            }
            $row['stream_buffer'] = $buf;

            return $session;
        });
    }

    /**
     * @param  array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: array<int, string>}  $result
     */
    public function markEntrySuccess(string $sessionId, string $entryId, array $result): void
    {
        $this->update($sessionId, function (array $session) use ($entryId, $result): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $session['entries'][$entryId]['status'] = 'ready';
            $session['entries'][$entryId]['data'] = $result['data'];
            $session['entries'][$entryId]['displayData'] = $result['displayData'];
            $session['entries'][$entryId]['warnings'] = $result['warnings'];
            $session['entries'][$entryId]['error'] = null;
            $session['entries'][$entryId]['completed_at'] = now()->toIso8601String();
            $session['entries'][$entryId]['stream_buffer'] = '';
            $session['counts'] = $this->recount($session);

            return $session;
        });

        $this->markCompletedIfDone($sessionId);
    }

    public function markEntryFailed(string $sessionId, string $entryId, string $message): void
    {
        $this->update($sessionId, function (array $session) use ($entryId, $message): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $session['entries'][$entryId]['status'] = 'failed';
            $session['entries'][$entryId]['error'] = $message;
            $session['entries'][$entryId]['completed_at'] = now()->toIso8601String();
            $session['entries'][$entryId]['stream_buffer'] = '';
            $session['counts'] = $this->recount($session);

            return $session;
        });

        $this->markCompletedIfDone($sessionId);
    }

    public function markCompletedIfDone(string $sessionId): void
    {
        $this->update($sessionId, function (array $session): array {
            if (($session['status'] ?? '') !== 'running') {
                return $session;
            }
            $planningStatus = (string) ($session['planning_status'] ?? 'planning');
            if ($planningStatus === 'planning') {
                // Planner still running — never mark complete even if no entries are pending yet.
                return $session;
            }
            $counts = $this->recount($session);
            $session['counts'] = $counts;
            if ($counts['pending'] === 0 && $counts['running'] === 0) {
                $session['status'] = 'completed';
            }

            return $session;
        });
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
                'auto_resolve' => (bool) ($session['auto_resolve'] ?? true),
                'prompt' => (string) ($session['prompt'] ?? ''),
                'entries' => $entriesOut,
                'warnings' => $session['plan_warnings'] ?? [],
                'planner_activity' => array_values($activity),
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
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            Log::error('Entry generation batch session lock timeout', [
                'session_id' => $sessionId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{pending: int, running: int, completed: int, failed: int}
     */
    private function recount(array $session): array
    {
        $counts = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($session['entries'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $st = (string) ($row['status'] ?? 'pending');
            match ($st) {
                'pending' => $counts['pending']++,
                'generating' => $counts['running']++,
                'ready' => $counts['completed']++,
                'failed' => $counts['failed']++,
                default => $counts['pending']++,
            };
        }

        return $counts;
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
