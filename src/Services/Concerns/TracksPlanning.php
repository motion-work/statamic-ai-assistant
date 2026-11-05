<?php

namespace MotionWork\StatamicAiAssistant\Services\Concerns;

use MotionWork\StatamicAiAssistant\Support\WebSearch;

/**
 * The planner's side of an AI agent session: plan rows, transcript turns,
 * planning status, the live activity feed, operations and cancellation.
 * Mutates the session only through update().
 */
trait TracksPlanning
{
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
     * Append a visible assistant turn (a summary of created/updated entries, a
     * read-only answer, or an error) to the conversation transcript.
     *
     * @param  array<int, string>  $entryIds  Session plan-row ids acted on this turn.
     */
    public function appendAssistantTurn(string $sessionId, string $text, array $entryIds = [], string $kind = 'summary'): void
    {
        $this->update($sessionId, function (array $session) use ($text, $entryIds, $kind): array {
            $transcript = is_array($session['transcript'] ?? null) ? $session['transcript'] : [];
            $turn = [
                'role' => 'assistant',
                'text' => $text,
                'entry_ids' => array_values(array_filter($entryIds, 'is_string')),
                'kind' => $kind,
            ];
            // Web search sources collected during this turn belong to its reply.
            $citations = is_array($session['turn_citations'] ?? null) ? $session['turn_citations'] : [];
            if ($citations !== []) {
                $turn['citations'] = $citations;
            }
            $transcript[] = $turn;
            $session['transcript'] = array_values($transcript);
            $session['turn_citations'] = [];

            return $session;
        });
    }

    /**
     * Re-open a finished session for a follow-up chat turn: reset the per-turn
     * planner scratch and re-run planning, while KEEPING the transcript history
     * and all prior entries. Recovers a 'completed' or 'cancelled' session.
     * Returns false if the session no longer exists (expired/unknown).
     */
    public function reopenForFollowUp(string $sessionId, string $prompt, ?int $maxPlanEntries, bool $advancedTools = false, ?string $attachmentContent = null, bool $webSearch = false): bool
    {
        $found = false;

        $this->update($sessionId, function (array $session) use ($prompt, $maxPlanEntries, $advancedTools, $attachmentContent, $webSearch, &$found): array {
            $found = true;

            $session['status'] = 'running';
            $session['planning_status'] = 'planning';
            $session['planner_error'] = null;
            $session['planner_answer'] = null;
            $session['operations'] = [];
            $session['prompt'] = $prompt;
            $session['max_plan_entries'] = $maxPlanEntries !== null ? max(1, $maxPlanEntries) : null;
            $session['advanced_tools'] = $advancedTools;
            // Web search is chosen per message, like the attachment below.
            $session['web_search'] = $webSearch;
            $session['turn_citations'] = [];
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

    /**
     * Switch web search on or off for the session's newest message (the
     * editor's composer toggle; follow-ups set it again via reopenForFollowUp()).
     */
    public function setWebSearch(string $sessionId, bool $on): void
    {
        $this->update($sessionId, function (array $session) use ($on): array {
            $session['web_search'] = $on;

            return $session;
        });
    }

    /**
     * Collect web search sources for the turn in progress; the next assistant
     * turn carries them (appendAssistantTurn()).
     *
     * @param  array<int, array{url: string, title: string}>  $citations
     */
    public function addTurnCitations(string $sessionId, array $citations): void
    {
        if ($citations === []) {
            return;
        }

        $this->update($sessionId, function (array $session) use ($citations): array {
            // While the newest turn is the user's message, the reply is still to
            // come: collect. Entry workers can finish after the planner wrote the
            // turn's reply (the summary): their sources then join that reply.
            $transcript = is_array($session['transcript'] ?? null) ? $session['transcript'] : [];
            $last = array_key_last($transcript);

            if ($last !== null && ($transcript[$last]['role'] ?? '') === 'assistant') {
                $existing = is_array($transcript[$last]['citations'] ?? null) ? $transcript[$last]['citations'] : [];
                $session['transcript'][$last]['citations'] = WebSearch::merge($existing, $citations);

                return $session;
            }

            $existing = is_array($session['turn_citations'] ?? null) ? $session['turn_citations'] : [];
            $session['turn_citations'] = WebSearch::merge($existing, $citations);

            return $session;
        });
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
}
