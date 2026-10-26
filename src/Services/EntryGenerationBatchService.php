<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        bool $advancedTools = false,
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
            // Set when the planner answered a read-only question (no create/update).
            // A successful informational result, distinct from planner_error.
            'planner_answer' => null,
            // Conversation history for the multi-turn chat. One entry per visible
            // turn: {role, text, entry_ids, kind}. Seeded with the first user turn
            // so the planner has uniform input from turn 1.
            'transcript' => [
                ['role' => 'user', 'text' => $prompt, 'entry_ids' => [], 'kind' => null],
            ],
            'auto_resolve' => $autoResolve,
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
                'appendix' => (string) ($urlAugmentation['appendix'] ?? ''),
                'warnings' => array_values(array_filter($urlAugmentation['warnings'] ?? [], 'is_string')),
                'preferred_paths' => $preferredPaths,
                'appended_to_prompts' => (bool) ($urlAugmentation['appended_to_prompts'] ?? false),
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

            $operation = (string) ($decoratedRow['operation'] ?? '');
            if (! in_array($operation, ['create', 'update', 'localize'], true)) {
                $operation = $entryId !== null ? 'update' : 'create';
            }

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
                'operation' => $operation,
                'target_site' => isset($decoratedRow['target_site']) && is_string($decoratedRow['target_site']) ? $decoratedRow['target_site'] : null,
                'target_locale' => isset($decoratedRow['target_locale']) && is_string($decoratedRow['target_locale']) ? $decoratedRow['target_locale'] : null,
                'target_site_label' => isset($decoratedRow['target_site_label']) && is_string($decoratedRow['target_site_label']) ? $decoratedRow['target_site_label'] : null,
                'saved_entry' => null,
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

    /**
     * Append a visible user turn to the conversation transcript.
     */
    public function appendUserTurn(string $sessionId, string $text): void
    {
        $this->update($sessionId, function (array $session) use ($text): array {
            $transcript = is_array($session['transcript'] ?? null) ? $session['transcript'] : [];
            $transcript[] = ['role' => 'user', 'text' => $text, 'entry_ids' => [], 'kind' => null];
            $session['transcript'] = array_values($transcript);

            return $session;
        });
    }

    /**
     * Append a visible assistant turn (a summary of created/updated entries, a
     * read-only answer, or an error) to the conversation transcript.
     *
     * @param  array<int, string>  $entryIds  Session plan-row ids acted on this turn.
     */
    public function appendAssistantTurn(string $sessionId, string $text, array $entryIds = [], string $kind = 'summary'): void
    {
        $this->update($sessionId, function (array $session) use ($text, $entryIds, $kind): array {
            $transcript = is_array($session['transcript'] ?? null) ? $session['transcript'] : [];
            $transcript[] = [
                'role' => 'assistant',
                'text' => $text,
                'entry_ids' => array_values(array_filter($entryIds, 'is_string')),
                'kind' => $kind,
            ];
            $session['transcript'] = array_values($transcript);

            return $session;
        });
    }

    /**
     * Re-open a finished session for a follow-up chat turn: reset the per-turn
     * planner scratch and re-run planning, while KEEPING the transcript history
     * and all prior entries. Recovers a 'completed' or 'cancelled' session.
     * Returns false if the session no longer exists (expired/unknown).
     */
    public function reopenForFollowUp(string $sessionId, string $prompt, ?int $maxPlanEntries, bool $advancedTools = false, ?string $attachmentContent = null): bool
    {
        $found = false;

        $this->update($sessionId, function (array $session) use ($prompt, $maxPlanEntries, $advancedTools, $attachmentContent, &$found): array {
            $found = true;

            $session['status'] = 'running';
            $session['planning_status'] = 'planning';
            $session['planner_error'] = null;
            $session['planner_answer'] = null;
            $session['operations'] = [];
            $session['prompt'] = $prompt;
            $session['max_plan_entries'] = $maxPlanEntries !== null ? max(1, $maxPlanEntries) : null;
            $session['advanced_tools'] = $advancedTools;
            // The attachment is scoped to a single turn: a follow-up replaces
            // (or clears) whatever was attached on the previous turn, so the
            // planner injects it only into the newest user message.
            $session['attachment_content'] = $attachmentContent;

            $transcript = is_array($session['transcript'] ?? null) ? $session['transcript'] : [];
            $transcript[] = ['role' => 'user', 'text' => $prompt, 'entry_ids' => [], 'kind' => null];
            $session['transcript'] = array_values($transcript);

            return $session;
        });

        return $found;
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

    /**
     * Record a read-only informational answer (e.g. "which entry contains X?") and
     * complete planning successfully. Distinct from markPlanningFailed: this is a
     * legitimate result, not an error, even though no entries were created.
     */
    public function recordPlannerAnswer(string $sessionId, string $answer): void
    {
        $this->update($sessionId, function (array $session) use ($answer): array {
            if (($session['planning_status'] ?? '') === 'planning_failed') {
                return $session;
            }
            $session['planner_answer'] = $answer;
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
     * Merge preferred asset paths (existing assets the planner selected via
     * use_assets) into the session, deduplicated — GeneratePlannedEntryJob
     * seeds each entry's PreferredAssetPaths from this list.
     *
     * @param  array<int, array{container: string, path: string}>  $entries
     */
    public function appendPreferredAssetPaths(string $sessionId, array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $this->update($sessionId, function (array $session) use ($entries): array {
            $existing = $session['url_augmentation']['preferred_paths'] ?? [];
            $existing = is_array($existing) ? $existing : [];

            $seen = [];
            foreach ($existing as $row) {
                $seen[($row['container'] ?? '').'::'.($row['path'] ?? '')] = true;
            }

            foreach ($entries as $row) {
                $key = ($row['container'] ?? '').'::'.($row['path'] ?? '');
                if (! isset($seen[$key]) && ($row['container'] ?? '') !== '' && ($row['path'] ?? '') !== '') {
                    $existing[] = ['container' => $row['container'], 'path' => $row['path']];
                    $seen[$key] = true;
                }
            }

            $session['url_augmentation']['preferred_paths'] = $existing;

            return $session;
        });
    }

    /**
     * Record one completed non-entry operation (structural changes like
     * collections/blueprints/taxonomies today; any tool can report one). Unlike
     * the drained activity feed, operations persist for the whole turn so the
     * CP can render a stable "what has been done" checklist while the run
     * continues. Reset on every new turn.
     */
    public function appendOperation(string $sessionId, string $kind, string $label): void
    {
        if (trim($label) === '') {
            return;
        }
        $this->update($sessionId, function (array $session) use ($kind, $label): array {
            $ops = is_array($session['operations'] ?? null) ? $session['operations'] : [];
            $ops[] = [
                'id' => 'op-'.(count($ops) + 1).'-'.substr(md5($kind.$label.count($ops)), 0, 8),
                'kind' => $kind,
                'label' => $label,
            ];
            $session['operations'] = array_slice($ops, -50);

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
     * The localization was saved on the target site. The card must not offer
     * "Save as draft", which would create a new entry on the current site.
     *
     * @param  array{entry_id: string, edit_url: ?string, title: string, warnings?: array<int, string>}  $saved
     */
    public function markEntrySaved(string $sessionId, string $entryId, array $saved): void
    {
        $this->update($sessionId, function (array $session) use ($entryId, $saved): array {
            if (! isset($session['entries'][$entryId])) {
                return $session;
            }
            $session['entries'][$entryId]['status'] = 'saved';
            $session['entries'][$entryId]['saved_entry'] = [
                'entry_id' => (string) ($saved['entry_id'] ?? ''),
                'edit_url' => isset($saved['edit_url']) && is_string($saved['edit_url']) ? $saved['edit_url'] : null,
                'title' => (string) ($saved['title'] ?? ''),
            ];
            $session['entries'][$entryId]['warnings'] = is_array($saved['warnings'] ?? null) ? array_values($saved['warnings']) : [];
            $session['entries'][$entryId]['error'] = null;
            $session['entries'][$entryId]['completed_at'] = now()->toIso8601String();
            $session['entries'][$entryId]['stream_buffer'] = '';
            $session['counts'] = $this->recount($session);

            return $session;
        });

        $this->markCompletedIfDone($sessionId);
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
                'ready', 'saved' => $counts['completed']++,
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
