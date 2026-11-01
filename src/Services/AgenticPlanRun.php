<?php

namespace MotionWork\StatamicAiAssistant\Services;

/**
 * One agentic planning turn: what the planner was given, and what the model
 * has done so far. EntryGenerationPlanner's tool loop and its handlers share it
 * instead of passing a dozen counters and out-parameters between them.
 */
final class AgenticPlanRun
{
    /** Entry jobs dispatched (create, update, localize). */
    public int $created = 0;

    /** Entry-locating searches (find_entries + search_entry_content). */
    public int $searchCalls = 0;

    /** Successful structural and asset-metadata writes. */
    public int $structuralWrites = 0;

    /** One-shot guard for the "gave up without searching" self-correction. */
    public bool $nudgedEmptyResult = false;

    /** Bounds recovery from empty model responses (no content + no tool calls). */
    public int $emptyResponses = 0;

    /** Bounds recovery from protocol drift (tool calls PRINTED as text). */
    public int $protocolCorrections = 0;

    /**
     * Some providers/models don't honour a forced specific-tool choice and
     * return an empty turn. Once the forced fetch is ignored, stop forcing and
     * let the model proceed with tool_choice=auto (it can still fetch on its
     * own) — otherwise it can get stuck emitting empty responses.
     */
    public bool $forceToolDisabled = false;

    /** Set when the model calls answer_question — a read-only answer, not a failure. */
    public ?string $answer = null;

    /**
     * Set when the model calls propose_plan — pauses the run for user approval.
     *
     * @var array{steps: array<int, string>, question: string}|null
     */
    public ?array $plan = null;

    /** @var array<int, string> session plan-row ids of the dispatched jobs */
    public array $createdRowIds = [];

    /** @var array<int, string> human-readable lines for the final summary */
    public array $structuralChanges = [];

    /** @var array<int, string> */
    public array $toolWarnings = [];

    /**
     * @param  array<int, array{handle: string, title: string, blueprints: array<int, array{handle: string, title: string}>}>  $catalog
     * @param  int  $cap  Entry jobs the model may dispatch this turn
     * @param  int  $priorEntryCount  Entries earlier turns already added to the session
     * @param  \Closure(): void|null  $heartbeat  Cancellation poll between rounds and tool calls
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly array $catalog,
        public readonly int $cap,
        public readonly int $priorEntryCount,
        public readonly bool $advancedTools,
        public readonly ?\Closure $heartbeat = null,
    ) {}

    /** Absolute cap for addPlannedEntry: prior turns' entries plus this turn's allowance. */
    public function addCap(): int
    {
        return $this->priorEntryCount + $this->cap;
    }

    /**
     * @param  array<string, mixed>  $result  A job tool's result
     */
    public function recordDispatchedJob(array $result): void
    {
        if (($result['ok'] ?? false) !== true) {
            return;
        }

        $this->created++;

        if (isset($result['id']) && is_string($result['id'])) {
            $this->createdRowIds[] = $result['id'];
        }
    }
}
