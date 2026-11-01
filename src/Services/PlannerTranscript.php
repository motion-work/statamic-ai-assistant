<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * The planner's side of the conversation: earlier turns as chat messages, and
 * the assistant turn each run records (an answer, a plan, or a summary of
 * the entries and structural changes it made).
 */
final class PlannerTranscript
{
    public function __construct(private ?EntryGenerationBatchService $batch) {}

    /**
     * The run's warnings, and — for a run that dispatched entries — its summary turn.
     */
    public function recordOutcome(AgenticPlanRun $run): void
    {
        $createdRowIds = $run->createdRowIds;
        $structuralChanges = $run->structuralChanges;

        foreach ($run->toolWarnings as $w) {
            if (is_string($w) && $w !== '') {
                $this->batch->appendPlannerWarning($run->sessionId, $w);
            }
        }

        // Record this turn's outcome in the transcript so the next turn has context.
        // The answer_question / propose_plan / structural-only paths already
        // recorded their own assistant turn in the loop. A MIXED run (entries +
        // structural changes) lands here — the summary must name both, or the
        // collection/blueprint work silently disappears from the visible record.
        if ($createdRowIds !== []) {
            $summary = $this->summarizeCreatedEntries($createdRowIds, $run->sessionId);

            if ($structuralChanges !== []) {
                $summary .= "\n".__('Also applied: :list.', ['list' => implode(', ', $structuralChanges)]);
            }

            $this->batch->appendAssistantTurn($run->sessionId, $summary, $createdRowIds, 'summary');
        }
    }

    /**
     * The conversation so far as chat messages, so follow-up turns carry
     * context. Only the newest user turn carries the catalog and attachment.
     *
     * @param  array<string, mixed>  $session
     * @return array<int, array{role: string, content: string}>
     */
    public function transcriptMessages(array $session, string $prompt, string $system, string $catalogPreamble, string $attachmentPart): array
    {
        // Build the message array from the conversation transcript so follow-up
        // turns carry context. Only the newest user turn carries the catalog +
        // attachment. On turn 1 the transcript is exactly [user], so this yields
        // the same [system, user] shape as a single-shot request (regression-tested).
        $transcript = is_array($session['transcript'] ?? null) && $session['transcript'] !== []
            ? $session['transcript']
            : [['role' => 'user', 'text' => $prompt, 'entry_ids' => [], 'kind' => null]];

        $lastUserIdx = null;
        $userTexts = [];
        foreach ($transcript as $i => $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $lastUserIdx = $i;
                $userTexts[] = (string) ($turn['text'] ?? '');
            }
        }

        // Pre-resolve "@asset:"/"@folder:" mentions from EVERY user turn (refs are
        // often dropped in one message and asked about later) into an authoritative
        // block on the newest user message — models otherwise answer folder
        // questions from the entries catalog when a collection shares the name.
        $assetMentionPart = (new PromptAssetMentionResolver)->resolve($userTexts)['appendix'];

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($transcript as $i => $turn) {
            $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $text = (string) ($turn['text'] ?? '');
            if ($role === 'assistant') {
                $text .= $this->renderEntryRefs(
                    is_array($turn['entry_ids'] ?? null) ? $turn['entry_ids'] : [],
                    $session,
                );
            }
            if ($i === $lastUserIdx) {
                $text = $catalogPreamble.$text.$attachmentPart.$assetMentionPart;
            }
            if (trim($text) === '') {
                continue; // never send an empty message (some providers reject them)
            }
            $messages[] = ['role' => $role, 'content' => $text];
        }

        return $messages;
    }

    /**
     * A short human-readable suffix naming the entries an assistant turn acted on,
     * so a later "add a section to this entry" can resolve the target.
     *
     * @param  array<int, string>  $entryRowIds  Session plan-row ids from a prior turn.
     * @param  array<string, mixed>  $session
     */
    private function renderEntryRefs(array $entryRowIds, array $session): string
    {
        $entries = is_array($session['entries'] ?? null) ? $session['entries'] : [];
        $parts = [];
        foreach ($entryRowIds as $rid) {
            $row = $entries[(string) $rid] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            $op = (string) ($row['operation'] ?? 'create');
            $statamicId = isset($row['entry_id']) && is_string($row['entry_id']) ? $row['entry_id'] : '';
            $desc = $label !== '' ? "“{$label}”" : (string) ($row['collection_title'] ?? ($row['collection'] ?? 'entry'));
            if ($op === 'update' && $statamicId !== '') {
                $desc .= " (entry_id {$statamicId})";
            }
            if ($op === 'localize' && $statamicId !== '') {
                $targetSite = isset($row['target_site']) && is_string($row['target_site']) ? $row['target_site'] : '';
                $desc .= " (source entry_id {$statamicId}".($targetSite !== '' ? ", site {$targetSite}" : '').')';
            }
            $desc .= " [{$op}]";
            $parts[] = $desc;
        }

        return $parts === [] ? '' : "\n(Entries this turn: ".implode('; ', $parts).')';
    }

    /**
     * Deterministic summary of the entries dispatched this turn (ids/labels are
     * guaranteed correct, unlike the model's free-text tail).
     *
     * @param  array<int, string>  $createdRowIds
     */
    private function summarizeCreatedEntries(array $createdRowIds, string $sessionId): string
    {
        $session = $this->batch?->getSession($sessionId);
        $entries = is_array($session['entries'] ?? null) ? $session['entries'] : [];

        $created = [];
        $updated = [];
        $localized = [];
        foreach ($createdRowIds as $rid) {
            $row = $entries[(string) $rid] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            $name = $label !== '' ? "“{$label}”" : (string) ($row['collection_title'] ?? ($row['collection'] ?? 'entry'));
            $operation = (string) ($row['operation'] ?? 'create');
            if ($operation === 'update') {
                $updated[] = $name;
            } elseif ($operation === 'localize') {
                $site = (string) ($row['target_site_label'] ?? $row['target_site'] ?? '');
                $localized[$site][] = $name;
            } else {
                $created[] = $name;
            }
        }

        $clauses = [];
        if ($created !== []) {
            $clauses[] = (string) __('created :names', ['names' => implode(', ', $created)]);
        }
        if ($updated !== []) {
            $clauses[] = (string) __('updated :names', ['names' => implode(', ', $updated)]);
        }
        foreach ($localized as $site => $names) {
            $clauses[] = (string) __('localized :names into :site', [
                'names' => implode(', ', $names),
                'site' => $site !== '' ? $site : (string) __('another site'),
            ]);
        }

        if ($clauses === []) {
            $n = count($createdRowIds);

            return (string) __('Done — :n actions dispatched.', ['n' => $n]);
        }

        // "Done — created “A”, “B” and updated “C”."
        return (string) __('Done — :summary.', ['summary' => implode(' '.__('and').' ', $clauses)]);
    }

    /**
     * Append the precise applied-changes list to a completion summary, so even
     * a terse model summary tells the user exactly what was changed.
     *
     * @param  array<int, string>  $changes
     */
    public function withAppliedList(string $text, array $changes): string
    {
        if ($changes === []) {
            return $text;
        }

        return trim($text)."\n".__('Applied: :list.', ['list' => implode(', ', $changes)]);
    }

    /**
     * Terminal propose_plan outcome: record the plan (and optional question) as
     * a 'plan' assistant turn and pause the run. The CP renders it with an
     * approve action; the user's approval/answer arrives as a follow-up turn.
     *
     * @param  array<int, string>  $steps
     */
    public function completePlannerPlan(string $sessionId, array $steps, string $question, int $round): void
    {
        $lines = [];
        foreach (array_values($steps) as $i => $step) {
            $lines[] = ($i + 1).'. '.$step;
        }

        $text = __('Proposed plan:')."\n".implode("\n", $lines);

        if ($question !== '') {
            $text .= "\n\n".__('Question:').' '.$question;
        }

        $this->recordPlanTurn($sessionId, $text, $round);
    }

    /**
     * Record a proposed plan as the run's successful outcome (kind 'plan', so
     * the CP renders the approve action) — shared by the tool path and the
     * printed-as-text recovery path.
     */
    public function recordPlanTurn(string $sessionId, string $text, int $round): void
    {
        Log::info('[entry-gen-tool] agentic planner proposed a plan', [
            'rounds' => $round + 1,
        ]);

        $this->batch?->recordPlannerAnswer($sessionId, $text);
        $this->batch?->appendAssistantTurn($sessionId, $text, [], 'plan');
    }

    public function completePlannerAnswer(string $sessionId, string $answer, int $round, int $created): void
    {
        Log::info('[entry-gen-tool] agentic planner answered a question', [
            'rounds' => $round + 1,
            'entries_created' => $created,
        ]);
        $this->batch?->recordPlannerAnswer($sessionId, $answer);
        $this->batch?->appendAssistantTurn($sessionId, $answer, [], 'answer');
    }
}
