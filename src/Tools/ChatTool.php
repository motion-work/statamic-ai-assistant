<?php

namespace BoldWeb\StatamicAiAssistant\Tools;

/**
 * A capability the LLM can invoke during a generation or planning run.
 *
 * One class per tool keeps each capability self-contained and lets both the
 * entry-generator loop and the agentic planner share the exact same dispatch
 * (see ChatToolRunner) instead of hand-rolling a per-tool if-chain in each.
 *
 * To add a new agent capability: implement this interface and register the
 * instance with a ChatToolRunner — no loop changes required.
 */
interface ChatTool
{
    /** Function name the model calls (must be unique within a run). */
    public function name(): string;

    /**
     * OpenAI-style tool/function definition sent to the provider.
     *
     * @return array{type: string, function: array<string, mixed>}
     */
    public function definition(): array;

    /**
     * Execute one tool call and return the result the model should see. The
     * runner JSON-encodes this into the `role: tool` message.
     *
     * @return array<string, mixed>
     */
    public function handle(string $argumentsJson, ToolContext $context): array;

    /**
     * Maximum times this tool may run per request (null = unlimited). The runner
     * enforces it and returns a standard limit-reached result once exceeded.
     */
    public function maxCalls(): ?int;
}
