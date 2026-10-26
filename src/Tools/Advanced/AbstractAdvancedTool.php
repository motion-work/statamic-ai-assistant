<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ChatTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;

/**
 * Base for the structural "advanced" tools (collections, blueprints,
 * taxonomies). These are only registered when the batch session carries the
 * requesting user's `advanced_tools` grant (see AgentAccess) — an ungranted
 * user's model never even sees their definitions.
 *
 * Unlike entry generation, structural writes are applied IMMEDIATELY (there is
 * no draft/review step for blueprints or collections), which is exactly why
 * they sit behind their own access feature and a small per-request budget.
 */
abstract class AbstractAdvancedTool implements ChatTool
{
    final public function handle(string $argumentsJson, ToolContext $context): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        if (! is_array($args)) {
            return ['ok' => false, 'error' => 'invalid_arguments_shape'];
        }

        return $this->run($args, $context);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    abstract protected function run(array $args, ToolContext $context): array;

    /** Write tools share one small structural-writes budget per request. */
    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_structural_writes', 6));
    }

    protected function stringArg(array $args, string $key): string
    {
        return isset($args[$key]) && is_string($args[$key]) ? trim($args[$key]) : '';
    }

    /**
     * Validate a collection/taxonomy/blueprint handle shape, returning an
     * actionable error string or null when valid.
     */
    protected function invalidHandleError(string $handle, string $what): ?string
    {
        if ($handle === '') {
            return ucfirst($what).' handle is required.';
        }

        if (preg_match('/^[a-z][a-z0-9_]*$/', $handle) !== 1) {
            return ucfirst($what)." handle \"{$handle}\" is invalid. Handles are snake_case: lowercase letters, digits and underscores, starting with a letter.";
        }

        return null;
    }
}
