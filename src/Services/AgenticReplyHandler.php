<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Tools\ChatToolRunner;
use Statamic\Facades\Entry;

/**
 * What the planner does with a reply that is not a tool call: a terminal
 * answer or plan (also when printed as text), a printed tool call to bounce
 * back, a give-up to nudge once, the final summary, or an empty reply.
 */
final class AgenticReplyHandler
{
    /** Max consecutive empty model responses to nudge through before giving up. */
    private const MAX_EMPTY_RESPONSES = 2;

    public function __construct(
        private PlannerReplyParser $replies,
        private PlannerTranscript $transcript,
    ) {}

    /**
     * propose_plan and answer_question end the run; true when one of them did.
     */
    public function finishedByTerminalTool(AgenticPlanRun $run, int $round): bool
    {
        // propose_plan is terminal: the plan is shown to the user, who
        // approves or answers in their next message (a follow-up turn).
        if ($run->plan !== null) {
            $this->transcript->completePlannerPlan($run->sessionId, $run->plan['steps'], $run->plan['question'], $round, $run->plan['approve_label'] ?? '');

            return true;
        }

        // answer_question is terminal: the model answered a read-only
        // question. Record it as a successful result (not a failure) and stop.
        if ($run->answer !== null) {
            $this->transcript->completePlannerAnswer($run->sessionId, $run->answer, $round, $run->created);

            return true;
        }

        return false;
    }

    /**
     * Handles a reply with text and no tool calls: a printed answer or plan, a
     * printed tool call (corrected), a give-up without searching (nudged once),
     * or the final summary. True when the loop should run another round.
     *
     * @param  array<int, array<string, mixed>>  $working
     * @param  array<int, string>  $toolNames
     */
    public function continueAfterTextReply(AgenticPlanRun $run, ChatToolRunner $runner, array &$working, string $content, array $toolNames, int $round): bool
    {
        if ($this->finishedByPrintedTerminalTool($run, $content, $round)) {
            return false;
        }

        // GENERIC protocol-drift recovery: the model wrote a TOOL CALL
        // as reply text ("find_entries: …"). Works for every registered
        // tool — bounce it back with a format correction (bounded), and
        // never let such text through as an answer or an error message.
        $printedTool = $this->replies->printedToolCallName($content, $toolNames);
        if ($printedTool !== null) {
            $this->correctPrintedToolCall($run, $working, $content, $printedTool, $round);

            return true;
        }

        // Self-correction: mid-tier models sometimes bail with "Cannot
        // proceed / I can't determine which entry" WITHOUT ever calling a
        // search tool — even for a read-only question they could just answer,
        // or when the entry sits in the catalog shortlist. If nothing was
        // dispatched and NO tool at all was used (any read tool counts —
        // a model that just listed assets to answer a question must not be
        // told "you have not used any tools", or it discards its own good
        // answer and replies with meta like "I have already answered"),
        // nudge the model to either answer the question or resolve the
        // target before accepting the give-up. Fires at most once, so a
        // truly-impossible request still ends.
        if ($run->created === 0 && $run->structuralWrites === 0 && $run->searchCalls === 0
            && $runner->totalCalls() === 0 && ! $run->nudgedEmptyResult) {
            $this->nudgeGiveUp($run, $working, $content, $round);

            return true;
        }

        $this->finishWithText($run, $runner, $content, $round);

        return false;
    }

    /**
     * A terminal tool the model wrote as text instead of calling it.
     */
    private function finishedByPrintedTerminalTool(AgenticPlanRun $run, string $content, int $round): bool
    {
        // Some models emit answer_question as plain assistant text instead of
        // a structured tool_call (e.g. answer_question:{"answer":"…"}). Treat
        // that as a successful read-only answer, not a planning failure.
        $plainAnswer = $this->replies->extractAnswerQuestion($content);
        if ($plainAnswer !== null) {
            $this->transcript->completePlannerAnswer($run->sessionId, $plainAnswer, $round, $run->created);

            return true;
        }

        // Same recovery for propose_plan printed as text ("propose_plan:
        // …"): record it as a real plan turn so the CP shows the plan
        // panel with the approve action instead of a raw text blob.
        $plainPlan = $this->replies->extractProposedPlan($content);
        if ($plainPlan !== null) {
            $this->transcript->recordPlanTurn($run->sessionId, $plainPlan, $round);

            return true;
        }

        return false;
    }

    /**
     * Bounces a tool call printed as text back with a format correction, at
     * most twice per run.
     *
     * @param  array<int, array<string, mixed>>  $working
     */
    private function correctPrintedToolCall(AgenticPlanRun $run, array &$working, string $content, string $printedTool, int $round): void
    {
        if ($run->protocolCorrections < 2) {
            $run->protocolCorrections++;

            Log::warning('[entry-gen-tool] tool call printed as text; correcting', [
                'round' => $round + 1,
                'tool' => $printedTool,
                'attempt' => $run->protocolCorrections,
            ]);

            $working[] = ['role' => 'assistant', 'content' => $content];
            $working[] = [
                'role' => 'user',
                'content' => "FORMAT ERROR: you wrote `{$printedTool}` as plain text in your reply. Tools are invoked ONLY as native tool calls — never written into the reply text. Repeat your intended action NOW as a proper tool call to `{$printedTool}`. Reply text must never contain tool names.",
            ];

            return;
        }

        throw new \RuntimeException((string) __('The AI model kept responding in an invalid format. Please try again.'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $working
     */
    private function nudgeGiveUp(AgenticPlanRun $run, array &$working, string $content, int $round): void
    {
        $run->nudgedEmptyResult = true;

        Log::info('[entry-gen-tool] agentic planner bailed without searching; nudging retry', [
            'round' => $round + 1,
        ]);

        $working[] = [
            'role' => 'assistant',
            'content' => $content,
        ];
        $working[] = [
            'role' => 'user',
            'content' => 'Do not give up yet — you have not used any tools. First re-read the newest user message and decide the intent:'."\n"
                .'• IF IT IS A READ-ONLY QUESTION (e.g. "which is the biggest/longest entry?", "which entry contains X?", "do we have a page about Y?", "what is the id of Z?"): this is NOT a create/update request and needs no title from the user. Use find_entries (optionally scoped to a collection handle) and/or search_entry_content to gather what you need, then call answer_question with a concise answer. Never reply "Cannot proceed:" for a question you can look up.'."\n"
                .'• IF IT IS A QUESTION YOU CAN ANSWER WITHOUT ANY LOOKUP (e.g. about your own tools and capabilities): call answer_question NOW with the complete answer written out — do not describe what you are going to do, and do not refer to anything the user cannot see.'."\n"
                .'• IF IT IS A CHANGE REQUEST (create/update): a title alone is enough to identify an entry — do NOT claim the target is ambiguous or unknown before searching. Check the catalog shortlist above, matching by title while ignoring case, punctuation and connective words (so "Salt Stone", "Salt and Stone" and "Salt & Stone" are the same). If it is there, call update_entry_job with its id; if not, call find_entries with the title first.'."\n"
                .'Only reply "Cannot proceed:" if, after actually using the tools, the request genuinely cannot be fulfilled. '
                .'Whatever you reply must be the message for the user itself — never a note about the request (no "no action needed", no "I will answer now", no "I have already answered").',
        ];

    }

    /**
     * The model's final text: a summary of structural work, a plain answer,
     * or (with nothing done) the reason it gave up.
     */
    private function finishWithText(AgenticPlanRun $run, ChatToolRunner $runner, string $content, int $round): void
    {
        Log::info('[entry-gen-tool] agentic planner done', [
            'rounds' => $round + 1,
            'fetches' => $runner->callCount('fetch_page_content'),
            'entries_created' => $run->created,
            'structural_writes' => $run->structuralWrites,
        ]);

        // Work-without-entries run (structural changes and/or asset
        // metadata, no entry jobs): a successful outcome — record the
        // model's own summary, enriched with the precise applied-changes
        // list so terse model summaries still carry the detail.
        if ($run->created === 0 && $run->structuralWrites > 0) {
            $this->transcript->completePlannerAnswer(
                $run->sessionId,
                $this->transcript->withAppliedList(trim($content), $run->structuralChanges),
                $round,
                $run->created,
            );

            return;
        }

        // The model finished with plain text after doing real tool work,
        // or after the one-shot nudge: accept that text as its answer
        // instead of failing the run — turning "the model answered without
        // the answer_question tool" into an error only converts working
        // answers ("we have 467 assets") into error panels. Explicit
        // give-ups ("Cannot proceed:") keep failing loudly below. The
        // nudge above guarantees we can only get here once the model has
        // either used tools or already been pushed to act.
        if ($run->created === 0 && trim($content) !== ''
            && ! str_starts_with(trim($content), 'Cannot proceed')) {
            $this->transcript->completePlannerAnswer($run->sessionId, trim($content), $round, $run->created);

            return;
        }

        if ($run->created === 0) {
            throw new \RuntimeException(trim($content) !== ''
                ? trim($content)
                : (string) __('Planner ended without creating any entries.'));
        }

    }

    /**
     * Handles a reply with neither text nor tool calls. True when the loop
     * should run another round.
     *
     * @param  array<int, array<string, mixed>>  $working
     * @param  array<string, mixed>|null  $choice
     * @param  array<string, mixed>  $data
     */
    public function continueAfterEmptyReply(AgenticPlanRun $run, array &$working, int $round, ?array $choice, array $data): bool
    {
        // Empty response: the model returned neither content nor a tool call
        // (a provider hiccup, an ignored forced tool, or an empty completion
        // after a fetch). Recover generically rather than hard-failing:
        //  - if entries were already dispatched, finish successfully;
        //  - otherwise nudge the model to act, bounded so we can't loop forever.
        if ($run->created > 0) {
            Log::info('[entry-gen-tool] agentic planner returned empty after dispatching; finishing', [
                'rounds' => $round + 1,
                'entries_created' => $run->created,
            ]);

            return false;
        }

        if ($run->structuralWrites > 0) {
            // Work done but the model never produced a summary — finish
            // successfully with the applied-changes list rather than failing.
            $this->transcript->completePlannerAnswer(
                $run->sessionId,
                $this->transcript->withAppliedList((string) __('Done.'), $run->structuralChanges),
                $round,
                $run->created,
            );

            return false;
        }

        $run->emptyResponses++;
        if ($run->emptyResponses <= self::MAX_EMPTY_RESPONSES) {
            // Capture why the completion was empty (e.g. finish_reason "length"
            // = the model hit the output-token cap) so the cause is diagnosable.
            Log::warning('[entry-gen-tool] agentic planner returned an empty response; nudging retry', [
                'round' => $round + 1,
                'attempt' => $run->emptyResponses,
                'finish_reason' => is_array($choice) ? ($choice['finish_reason'] ?? null) : null,
                'usage' => is_array($data) ? ($data['usage'] ?? null) : null,
            ]);

            $working[] = [
                'role' => 'user',
                'content' => 'Your last response was empty. Continue the task: either call a tool now '
                    .'(fetch_page_content to read a URL, find_entries / search_entry_content to locate an entry, '
                    .'create_entry_job / update_entry_job to act, or answer_question for a read-only question), '
                    .'or, if the request truly cannot be fulfilled, reply with a short message starting with "Cannot proceed:".',
            ];

            return true;
        }

        throw new \RuntimeException(__('The AI model returned an empty response. Please try again.'));
    }
}
