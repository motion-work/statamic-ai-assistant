<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Support\PlanEntryDecorator;
use MotionWork\StatamicAiAssistant\Tools\ToolArguments;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * The agentic planner's own tools: create/update/localize entry jobs (each a
 * row in the batch session plus a queued GeneratePlannedEntryJob), entry
 * search, and the terminal answer_question / propose_plan.
 */
final class PlannerJobTools
{
    private EntryJobTools $entryJobs;

    private LocalizeEntryJobTool $localize;

    private PlannerJobToolDefinitions $definitions;

    public function __construct(
        private EntrySearch $search,
        ?EntryGenerationBatchService $batch,
        ?PlanEntryDecorator $decorator,
    ) {
        $this->entryJobs = new EntryJobTools($batch, $decorator);
        $this->localize = new LocalizeEntryJobTool($batch, $decorator);
        $this->definitions = new PlannerJobToolDefinitions;
    }

    /**
     * @return array<int, array{type: string, function: array<string, mixed>}>
     */
    public function definitions(int $cap): array
    {
        return $this->definitions->definitions($cap);
    }

    /**
     * The planner's stateful tools (job tools, searches, answer/plan); null for
     * any other name, which the runner reports as unknown.
     *
     * @return array<string, mixed>|null
     */
    public function dispatchAgenticJobTool(AgenticPlanRun $run, string $name, string $args): ?array
    {
        if ($name === 'propose_plan') {
            return $this->acceptProposedPlan($run, $args);
        }

        if ($name === 'answer_question') {
            return $this->acceptAnswer($run, $args);
        }

        if (in_array($name, ['create_entry_job', 'update_entry_job', 'localize_entry'], true)) {
            // AI agent UPDATE and localize tools are independent of create_entry_job.
            $result = match ($name) {
                'create_entry_job' => $this->entryJobs->handleCreateEntryJobToolCall($args, $run->sessionId, $run->catalog, $run->cap, $run->created, $run->addCap()),
                'update_entry_job' => $this->entryJobs->handleUpdateEntryJobToolCall($args, $run->sessionId, $run->catalog, $run->cap, $run->created, $run->addCap()),
                'localize_entry' => $this->localize->handleLocalizeEntryToolCall($args, $run->sessionId, $run->catalog, $run->cap, $run->created, $run->addCap()),
            };
            $run->recordDispatchedJob($result);

            return $result;
        }

        if ($name === 'find_entries') {
            $run->searchCalls++;

            return $this->handleFindEntriesToolCall($args);
        }

        if ($name === 'search_entry_content') {
            $run->searchCalls++;

            return $this->handleSearchEntryContentToolCall($args);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function acceptProposedPlan(AgenticPlanRun $run, string $args): array
    {
        $decoded = ToolArguments::decode($args, 'propose_plan');
        if ($decoded === null) {
            return ToolArguments::invalid();
        }

        $steps = [];
        foreach ((is_array($decoded['steps'] ?? null) ? $decoded['steps'] : []) as $step) {
            if (is_string($step) && trim($step) !== '') {
                $steps[] = trim($step);
            }
        }

        if ($steps === []) {
            return ['ok' => false, 'error' => 'missing_steps'];
        }

        $question = isset($decoded['question']) && is_string($decoded['question']) ? trim($decoded['question']) : '';
        $label = isset($decoded['approve_label']) && is_string($decoded['approve_label']) ? trim($decoded['approve_label']) : '';
        $run->plan = ['steps' => array_slice($steps, 0, 12), 'question' => $question, 'approve_label' => mb_substr($label, 0, 60)];

        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function acceptAnswer(AgenticPlanRun $run, string $args): array
    {
        $decoded = ToolArguments::decode($args, 'answer_question');
        if ($decoded === null) {
            return ToolArguments::invalid();
        }
        $answer = isset($decoded['answer']) && is_string($decoded['answer'])
            ? trim($decoded['answer'])
            : '';
        if ($answer === '') {
            return ['ok' => false, 'error' => 'missing_answer'];
        }
        $run->answer = $answer;

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, results?: array<int, array{id: string, title: string, slug: string, collection: string, snippet: string}>, error?: string}
     */
    private function handleSearchEntryContentToolCall(string $argumentsJson): array
    {
        $args = ToolArguments::decode($argumentsJson, 'search_entry_content');
        if ($args === null) {
            return ToolArguments::invalid();
        }

        $query = isset($args['query']) && is_string($args['query']) ? trim($args['query']) : '';
        if ($query === '') {
            return ['ok' => false, 'error' => 'missing_query'];
        }

        $collection = isset($args['collection']) && is_string($args['collection']) ? trim($args['collection']) : null;
        if ($collection === '') {
            $collection = null;
        }

        $limit = isset($args['limit']) && is_numeric($args['limit']) ? (int) $args['limit'] : 10;

        return ['ok' => true, 'results' => $this->search->searchEntryContent($collection, $query, $limit)];
    }

    /**
     * @return array{ok: bool, results?: array<int, array<string, mixed>>, pagination?: array<string, int|bool>, note?: string, error?: string}
     */
    private function handleFindEntriesToolCall(string $argumentsJson): array
    {
        $args = ToolArguments::decode($argumentsJson, 'find_entries');
        if ($args === null) {
            return ToolArguments::invalid();
        }

        $query = isset($args['query']) && is_string($args['query']) ? trim($args['query']) : '';
        if ($query === '') {
            return ['ok' => false, 'error' => 'missing_query'];
        }

        $collection = isset($args['collection']) && is_string($args['collection']) ? trim($args['collection']) : null;
        if ($collection === '') {
            $collection = null;
        }

        $limit = isset($args['limit']) && is_numeric($args['limit']) ? (int) $args['limit'] : 10;
        $offset = isset($args['offset']) && is_numeric($args['offset']) ? (int) $args['offset'] : 0;

        return array_merge(['ok' => true], $this->search->findEntries($collection, $query, $limit, $offset));
    }
}
