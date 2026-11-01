<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Tools\ChatToolRunner;

/**
 * The agentic planner's rounds: each completion's tool calls run through the
 * toolbox and the job tools, every other reply goes to the reply handler,
 * until the run is answered, planned, done or out of rounds.
 */
final class AgenticPlannerLoop
{
    public function __construct(
        private AbstractAiService $aiService,
        private ?EntryGenerationBatchService $batch,
        private PlannerJobTools $jobTools,
        private PlannerReplyParser $replies,
        private PlannerToolbox $toolbox,
        private AgenticReplyHandler $replyHandler,
        private PlannerTranscript $transcript,
    ) {}

    /**
     * Agentic tool loop: same shape as planWithToolLoop but exposes both
     * fetch_page_content and create_entry_job. Each create_entry_job call
     * appends a row to the batch session and dispatches GeneratePlannedEntryJob.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function planAgenticToolLoop(array $messages, string $userPrompt, AgenticPlanRun $run): void
    {
        $runner = $this->toolbox->agenticRunner($messages, $run);

        // Seed the activity feed so the CP never shows an empty "0 entries" panel
        // while the planner is thinking before its first tool call.
        $this->batch?->appendPlannerActivity($run->sessionId, (string) __('Analyzing your request…'));

        $tools = array_merge($runner->definitions(), $this->jobTools->definitions($run->cap));
        $maxRounds = (int) config('statamic-ai-assistant.entry_generator_tool_max_rounds', 120);
        $maxTokens = max(4096, (int) config('statamic-ai-assistant.entry_generator_planner_max_output_tokens', 12000));
        $toolNames = array_map(fn ($t) => strtolower((string) ($t['function']['name'] ?? '')), $tools);

        $promptHasUrl = (bool) preg_match('~\bhttps?://[^\s<>\]\}\)\"\'`]+~iu', $userPrompt);
        $working = $messages;

        for ($round = 0; $round < $maxRounds; $round++) {
            $run->heartbeat?->__invoke();

            $forceTool = ! $run->forceToolDisabled && $promptHasUrl && $runner->callCount('fetch_page_content') === 0 && $run->created === 0;
            $toolChoice = $forceTool
                ? ['type' => 'function', 'function' => ['name' => 'fetch_page_content']]
                : 'auto';

            ChatToolRunner::compactToolMessages($working);

            $callMessages = $this->withStatusReminder($working, $run, $round, $maxRounds);

            $data = $this->aiService->createChatCompletion($callMessages, $maxTokens, $tools, $toolChoice, $run->heartbeat);
            $choice = $data['choices'][0] ?? null;
            $msg = $this->assistantMessage($choice);

            $toolCalls = $msg['tool_calls'] ?? null;
            $hasToolCalls = is_array($toolCalls) && $toolCalls !== [];

            if ($forceTool && ! $hasToolCalls) {
                $this->stopForcingTool($run, $round, $toolChoice);
            }

            if ($hasToolCalls) {
                if ($this->continueAfterToolCalls($run, $runner, $working, $msg, $round)) {
                    continue;
                }

                return;
            }

            $content = $msg['content'] ?? '';
            if (is_string($content) && trim($content) !== '') {
                if ($this->replyHandler->continueAfterTextReply($run, $runner, $working, $content, $toolNames, $round)) {
                    continue;
                }

                return;
            }

            if ($this->replyHandler->continueAfterEmptyReply($run, $working, $round, $choice, $data)) {
                continue;
            }

            return;
        }

        $this->finishAtRoundLimit($run, $runner, $maxRounds);
    }

    /**
     * @return array<string, mixed>
     */
    private function assistantMessage(mixed $choice): array
    {
        $msg = is_array($choice) ? ($choice['message'] ?? null) : null;

        if (! is_array($msg)) {
            throw new \RuntimeException(__('Unexpected planner response shape.'));
        }

        return $this->replies->normalizeAssistantMessage($msg);
    }

    /**
     * The model ignored the forced tool — stop forcing so subsequent rounds
     * use tool_choice=auto and it can move forward.
     *
     * @param  string|array<string, mixed>  $toolChoice
     */
    private function stopForcingTool(AgenticPlanRun $run, int $round, string|array $toolChoice): void
    {
        $run->forceToolDisabled = true;

        Log::warning('[entry-gen-tool] agentic planner forced tool call IGNORED; relaxing to auto', [
            'round' => $round,
            'tool_choice_sent' => $toolChoice,
        ]);
    }

    /**
     * Runs the reply's tool calls. Read tools (fetch_page_content,
     * read_entry_structure) go through the shared runner; the stateful job
     * tools are handled in the fallback so their session/cap mutations stay in
     * the planner. False when a terminal tool ended the run.
     *
     * @param  array<int, array<string, mixed>>  $working
     * @param  array<string, mixed>  $msg
     */
    private function continueAfterToolCalls(AgenticPlanRun $run, ChatToolRunner $runner, array &$working, array $msg, int $round): bool
    {
        $working[] = [
            'role' => 'assistant',
            'content' => array_key_exists('content', $msg) ? $msg['content'] : null,
            'tool_calls' => $msg['tool_calls'],
        ];

        $runner->consume($msg['tool_calls'], $working, fn (string $name, string $args) => $this->jobTools->dispatchAgenticJobTool($run, $name, $args));

        return ! $this->replyHandler->finishedByTerminalTool($run, $round);
    }

    /**
     * Transient status reminder (Claude Code's system-reminder pattern):
     * appended to THIS call only, never stored in $working — so there is
     * always exactly one and it is always current. Small models drift over
     * long loops; a short recency-anchored status beats hoping they remember
     * the system prompt from round 1.
     *
     * @param  array<int, array<string, mixed>>  $working
     * @return array<int, array<string, mixed>>
     */
    private function withStatusReminder(array $working, AgenticPlanRun $run, int $round, int $maxRounds): array
    {
        if ($round > 0) {
            $working[] = [
                'role' => 'user',
                'content' => $this->buildLoopStatusReminder($run->created, $run->cap, $run->structuralWrites, $round, $maxRounds, $run->advancedTools),
            ];
        }

        return $working;
    }

    /**
     * The round cap was hit: fail when nothing was done, else finish with what was.
     */
    private function finishAtRoundLimit(AgenticPlanRun $run, ChatToolRunner $runner, int $maxRounds): void
    {
        Log::error('[entry-gen-tool] agentic planner tool loop exceeded max rounds', [
            'max_rounds' => $maxRounds,
            'fetches' => $runner->callCount('fetch_page_content'),
            'entries_created' => $run->created,
        ]);

        if ($run->created === 0 && $run->structuralWrites === 0) {
            throw new \RuntimeException(__('Planner stopped: too many tool rounds.'));
        }

        if ($run->created === 0) {
            // Work happened before the round cap hit — still a success.
            $this->transcript->completePlannerAnswer(
                $run->sessionId,
                $this->transcript->withAppliedList((string) __('Done (stopped at the tool-round limit).'), $run->structuralChanges),
                $maxRounds - 1,
                $run->created,
            );

            return;
        }

        // Some entries were dispatched — surface a warning instead of failing the whole batch.
        $run->toolWarnings[] = __('Planner stopped after :n entries (too many tool rounds). Re-run if you need more.', ['n' => $run->created]);
    }

    /**
     * The one-line status appended (transiently) to each loop round so the model
     * always sees CURRENT progress and limits next to the newest message.
     */
    private function buildLoopStatusReminder(int $created, int $cap, int $structuralWrites, int $round, int $maxRounds, bool $advancedTools = false): string
    {
        $parts = [sprintf('Entries dispatched so far: %d of max %d.', $created, $cap)];

        if ($advancedTools) {
            $parts[] = 'Structural tools (collections/blueprints/fieldsets) are ENABLED this turn — earlier refusals in the conversation are outdated.';
        }

        if ($structuralWrites > 0) {
            $parts[] = sprintf('Structural changes applied: %d.', $structuralWrites);
        }

        $parts[] = sprintf('Tool rounds used: %d of %d.', $round, $maxRounds);

        $parts[] = $created >= $cap
            ? 'The entry cap is REACHED — do not dispatch more entries. Finish now with a short plain-text summary.'
            : 'When everything the newest user message asked for is done, finish with a short plain-text summary (no JSON, no tool calls).';

        return '[automatic status — not a message from the user] '.implode(' ', $parts);
    }
}
