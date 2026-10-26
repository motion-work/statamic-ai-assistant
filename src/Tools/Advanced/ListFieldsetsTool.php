<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\Fieldset;

/**
 * Lists reusable fieldsets (handle, title, field handles/types) so blueprints
 * can REFERENCE shared field groups via {"import": "<fieldset>"} instead of
 * redefining them — the site convention for things like hero and SEO blocks.
 */
class ListFieldsetsTool extends AbstractAdvancedTool
{
    public function name(): string
    {
        return 'list_fieldsets';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_fieldsets',
                'description' => 'List the reusable fieldsets of this site with their fields. '
                    .'Blueprints should REFERENCE these shared groups via an {"import": "<fieldset_handle>"} row (optionally with "prefix") '
                    .'instead of redefining the same fields — always check here before defining hero/SEO/meta-like fields yourself.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'Optional fieldset handle to inspect just one fieldset.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $filter = $this->stringArg($args, 'handle');

        $fieldsets = [];
        foreach (Fieldset::all() as $fieldset) {
            if ($filter !== '' && $fieldset->handle() !== $filter) {
                continue;
            }

            $row = [
                'handle' => (string) $fieldset->handle(),
                'title' => (string) $fieldset->title(),
                'fields' => $fieldset->fields()->all()->map(fn ($field) => [
                    'handle' => $field->handle(),
                    'type' => $field->type(),
                    'display' => $field->display(),
                ])->values()->all(),
            ];

            // Container fieldsets (a field with "sets", e.g. a page-builder
            // components/replicator) also expose their set handles so the model
            // can spot them and avoid duplicate registrations.
            $setHandles = $this->collectSetHandles($fieldset->contents());
            if ($setHandles !== []) {
                $row['component_container'] = true;
                $row['set_handles'] = $setHandles;
            }

            $fieldsets[] = $row;
        }

        if ($filter !== '' && $fieldsets === []) {
            $available = Fieldset::all()->map(fn ($fs) => (string) $fs->handle())->sort()->values()->implode(', ');

            return ['ok' => false, 'error' => "Fieldset \"{$filter}\" not found. Available: ".($available !== '' ? $available : '(none)')];
        }

        return [
            'ok' => true,
            'fieldsets' => $fieldsets,
            'usage' => 'Reference a whole fieldset in blueprint fields as {"import": "<handle>"} (optional "prefix": "x_"), or one field as {"handle": "...", "field": "<fieldset>.<field>"}. '
                .'Fieldsets marked "component_container" hold the page-builder sets — register new components there with add_component_set.',
        ];
    }

    /**
     * Set handles of the first "sets"-bearing field, flattened across set
     * groups when the container uses the grouped format.
     *
     * @param  array<string, mixed>  $contents
     * @return array<int, string>
     */
    private function collectSetHandles(array $contents): array
    {
        foreach ((is_array($contents['fields'] ?? null) ? $contents['fields'] : []) as $row) {
            $sets = is_array($row['field']['sets'] ?? null) ? $row['field']['sets'] : null;

            if ($sets === null) {
                continue;
            }

            $grouped = $sets !== [] && collect($sets)->every(fn ($v) => is_array($v) && is_array($v['sets'] ?? null));

            if (! $grouped) {
                return array_map('strval', array_keys($sets));
            }

            $handles = [];
            foreach ($sets as $group) {
                foreach (array_keys($group['sets']) as $h) {
                    $handles[] = (string) $h;
                }
            }

            return $handles;
        }

        return [];
    }

    /** Read-only: uses the shared CMS-read budget, not the write budget. */
    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_cms_reads', 12));
    }
}
