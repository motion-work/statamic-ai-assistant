<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Semantic\SemanticEntrySearch;
use MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use MotionWork\StatamicAiAssistant\Support\WebSearch;
use MotionWork\StatamicAiAssistant\Tools\Advanced\AdvancedToolset;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Decompose a free-text user request into a list of independent entry plans.
 *
 * Each plan targets one collection + blueprint and carries a focused brief
 * so the existing single-entry generator can be reused per item.
 */
class EntryGenerationPlanner
{
    private AbstractAiService $aiService;

    private EntryGeneratorService $generator;

    private ?EntryGenerationBatchService $batch;

    private ?PlanEntryDecorator $decorator;

    private AgenticPlannerPrompt $prompt;

    private PlannerTranscript $transcript;

    private AgenticPlannerLoop $loop;

    public function __construct(
        AbstractAiService $aiService,
        EntryGeneratorService $generator,
        PromptUrlFetcher $promptUrlFetcher,
        ?EntryGenerationBatchService $batch = null,
        ?PlanEntryDecorator $decorator = null,
        ?EntrySearch $search = null,
    ) {
        $this->aiService = $aiService;
        $this->generator = $generator;
        $this->batch = $batch;
        $this->decorator = $decorator;
        $this->prompt = new AgenticPlannerPrompt;
        $this->transcript = new PlannerTranscript($batch);

        $replies = new PlannerReplyParser;
        // Discovery (find_entries, search_entry_content, read lookups) adds
        // semantic matches; it falls back to the keyword search on its own.
        $search ??= new SemanticEntrySearch;
        $this->loop = new AgenticPlannerLoop(
            $aiService,
            $batch,
            new PlannerJobTools($search, $batch, $decorator),
            $replies,
            new PlannerToolbox($aiService, $search, $promptUrlFetcher, $batch),
            new AgenticReplyHandler($replies, $this->transcript),
            $this->transcript,
        );
    }

    /**
     * Agentic planner: runs inside PlanEntriesJob. Reads prompt/locale/attachment
     * from the batch session, lets the LLM call fetch_page_content + create_entry_job,
     * and dispatches one GeneratePlannedEntryJob per planned article in parallel.
     *
     * Throws so PlanEntriesJob can mark planning_failed; success path calls markPlanningComplete.
     */
    public function planAgentic(string $sessionId): void
    {
        $session = $this->planningSession($sessionId);

        $prompt = (string) ($session['prompt'] ?? '');
        $locale = (string) ($session['locale'] ?? '');
        $attachment = isset($session['attachment_content']) && is_string($session['attachment_content'])
            ? $session['attachment_content']
            : null;

        $shortlistLimit = max(0, (int) config('statamic-ai-assistant.agent_catalog_entries_shortlist', 25));
        $catalog = $this->generator->getCollectionsCatalog($shortlistLimit);
        if ($catalog === []) {
            throw new \RuntimeException(__('No collections with blueprints are available.'));
        }

        $catalogJson = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // The session carries the effective cap resolved from the requesting CP
        // user's role (EntryCreationPolicy). Non-super users are limited to a
        // single entry; fall back to the configured cap for legacy sessions.
        $cap = $this->resolvePlanCap($session);

        // Structural tools: registered only when the requesting user held the
        // 'advanced_tools' grant at request time (stored on the session) and the
        // pack isn't disabled site-wide. When off, the model never sees them.
        $advancedTools = AdvancedToolset::enabledForSession($session);
        $webSearch = WebSearch::enabledForSession($session);

        $system = $this->prompt->system($cap, $advancedTools, $locale, $attachment, $this->aiService->supportsVision(), $webSearch);

        $attachmentPart = $attachment
            ? "\n\nAdditional context from an attached document (excerpt):\n".Str::limit($attachment, 6000)
            : '';

        $messages = $this->transcript->transcriptMessages($session, $prompt, $system, "Available collections and blueprints (JSON):\n{$catalogJson}\n\nUser request:\n", $attachmentPart);

        // Absolute cap for addPlannedEntry: prior turns' entries accumulate in the
        // session, so a follow-up gets its own per-turn allowance on top of them.
        $priorEntryCount = is_array($session['entry_order'] ?? null) ? count($session['entry_order']) : 0;

        $run = new AgenticPlanRun($sessionId, $catalog, $cap, $priorEntryCount, $advancedTools, $this->cancellationPoll($sessionId), $webSearch);
        $this->loop->planAgenticToolLoop($messages, $prompt, $run);
        $this->transcript->recordOutcome($run);

        $this->batch->markPlanningComplete($sessionId);
    }

    /**
     * The session to plan, once the planner can run at all.
     *
     * @return array<string, mixed>
     */
    private function planningSession(string $sessionId): array
    {
        if ($this->batch === null || $this->decorator === null) {
            throw new \RuntimeException('Planner agentic dependencies not bound (EntryGenerationBatchService / PlanEntryDecorator).');
        }

        if (! $this->aiService->supportsChatTools()) {
            throw new \RuntimeException(__('The configured AI provider does not support tool calls; cannot run AI agent planner.'));
        }

        $session = $this->batch->getSession($sessionId);
        if (! is_array($session)) {
            throw new \RuntimeException(__('Planner session not found.'));
        }

        return $session;
    }

    private function cancellationPoll(string $sessionId): \Closure
    {
        return function () use ($sessionId): void {
            // Cancellation poll between LLM rounds + tool calls; throwing exits the loop.
            if ($this->batch !== null && $this->batch->isCancelled($sessionId)) {
                throw new \RuntimeException((string) __('Cancelled.'));
            }
        };
    }

    /**
     * Effective per-request cap for this planning session. Prefers the value the
     * controller resolved from the CP user's role (persisted on the session);
     * falls back to the configured ceiling for older sessions without it.
     *
     * @param  array<string, mixed>  $session
     */
    private function resolvePlanCap(array $session): int
    {
        $configured = EntryCreationPolicy::configuredMaxPlanEntries();

        $sessionCap = $session['max_plan_entries'] ?? null;
        if (is_int($sessionCap) || (is_string($sessionCap) && is_numeric($sessionCap))) {
            return max(1, min($configured, (int) $sessionCap));
        }

        return $configured;
    }
}
