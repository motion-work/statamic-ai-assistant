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

    /**
     * Resolve a blueprint's fields (expanding fieldset imports) so Statamic's
     * unique-handle check runs BEFORE the blueprint is persisted. Call this on
     * the fully-populated in-memory blueprint just before ->save().
     *
     * The field-list validator only sees the EXPLICIT handles the model sent;
     * it cannot look inside imported fieldsets. An import that already defines a
     * field the model also declared (classically "title", which component sets
     * like a teaser slider carry) would otherwise persist a blueprint that
     * throws DuplicateFieldException on every later read — breaking the CP
     * blueprint endpoint and all entry generation for that collection.
     *
     * Returns an actionable error string, or null when the blueprint resolves.
     * setContents() forgets the fields Blink cache, so this always reflects the
     * blueprint's current contents (verified for both create and update paths).
     */
    protected function blueprintResolutionError(\Statamic\Fields\Blueprint $blueprint): ?string
    {
        try {
            $blueprint->fields()->all();
        } catch (\Throwable $e) {
            return 'The blueprint was NOT saved because its fields could not be resolved: '.$e->getMessage()
                .' This usually means an imported fieldset already defines a field you also declared explicitly — most often "title", which component sets such as a news/teaser slider include. Fix it by removing the duplicate explicit field, dropping the conflicting import, or (for a component like news_teaser_slider) using it as a set inside main_components rather than importing it at blueprint level.';
        }

        return null;
    }
}
