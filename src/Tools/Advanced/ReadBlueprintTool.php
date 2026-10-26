<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ToolContext;

/**
 * Full field-level view of one blueprint — the inspection step before
 * update_blueprint, and the way to answer "which fields does X have?".
 */
class ReadBlueprintTool extends AbstractAdvancedTool
{
    use ResolvesBlueprintNamespace;

    public function name(): string
    {
        return 'read_blueprint';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'read_blueprint',
                'description' => 'Read one blueprint\'s full field definitions (handle, type, display, required, config). '
                    .'Pass the blueprint handle plus the collection OR taxonomy it belongs to. '
                    .'Use this before update_blueprint so you modify the right fields.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'The blueprint handle (see list_blueprints).',
                        ],
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Collection handle the blueprint belongs to (for entry blueprints).',
                        ],
                        'taxonomy' => [
                            'type' => 'string',
                            'description' => 'Taxonomy handle the blueprint belongs to (for term blueprints).',
                        ],
                        'form' => [
                            'type' => 'string',
                            'description' => 'Form handle, for FORM blueprints (a form blueprint shares the form\'s handle).',
                        ],
                    ],
                    'required' => ['handle'],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $handle = $this->stringArg($args, 'handle');
        if ($handle === '') {
            return ['ok' => false, 'error' => 'Blueprint handle is required.'];
        }

        $ns = $this->resolveBlueprintNamespace($args);
        if (! $ns['ok']) {
            return $ns;
        }

        $blueprint = $this->findBlueprintIn($ns['namespace'], $handle);
        if (! $blueprint) {
            $available = \Statamic\Facades\Blueprint::in($ns['namespace'])->map(fn ($bp) => $bp->handle())->sort()->implode(', ');

            return ['ok' => false, 'error' => "Blueprint \"{$handle}\" not found in {$ns['owner_type']} \"{$ns['owner']}\". Available: ".($available !== '' ? $available : '(none)')];
        }

        $context->reportActivity((string) __('Reading blueprint :handle', ['handle' => $handle]));

        return [
            'ok' => true,
            'handle' => $blueprint->handle(),
            'title' => $blueprint->title(),
            $ns['owner_type'] => $ns['owner'],
            // Raw stored structure: shows the site's conventions — tab layout and
            // {"import": "<fieldset>"} rows — which the resolved field list hides.
            // Mirror this style (especially the imports) when creating blueprints.
            'structure' => $blueprint->contents(),
            'fields' => $blueprint->fields()->all()->map(fn ($field) => [
                'handle' => $field->handle(),
                'type' => $field->type(),
                'display' => $field->display(),
                'required' => $field->isRequired(),
                'config' => $field->config(),
            ])->values()->all(),
        ];
    }

    /** Read-only: uses the shared CMS-read budget, not the write budget. */
    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_cms_reads', 12));
    }
}
