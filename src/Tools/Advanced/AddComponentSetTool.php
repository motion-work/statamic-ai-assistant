<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Illuminate\Support\Str;
use Statamic\Facades\Fieldset;

/**
 * Registers a component fieldset as a new SET of a container fieldset's
 * components/replicator field — the second half of the site convention where
 * page-builder blocks live as component_* fieldsets and a container fieldset
 * (commonly main_components) lists them as sets, each importing one fieldset.
 *
 * Handles both Statamic set formats: grouped sets (sets → group → sets → set)
 * and flat sets (sets → set). Pure contents manipulation via the native
 * Fieldset repository — no fieldtype needs to be resolved.
 */
class AddComponentSetTool extends AbstractAdvancedTool
{
    public function name(): string
    {
        return 'add_component_set';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'add_component_set',
                'description' => 'Register an existing component fieldset as a new set of a CONTAINER fieldset\'s components/replicator field, '
                    .'so editors can use the component in entries. The container is the fieldset whose field has "sets" that import other component fieldsets '
                    .'(find it via list_fieldsets). Applies IMMEDIATELY — only when the user explicitly asked for a new component.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'component' => [
                            'type' => 'string',
                            'description' => 'Handle of the component fieldset to register (must exist — create it first with create_fieldset).',
                        ],
                        'container' => [
                            'type' => 'string',
                            'description' => 'Handle of the container fieldset holding the components/replicator field (e.g. the one whose sets import component_* fieldsets).',
                        ],
                        'set_handle' => [
                            'type' => 'string',
                            'description' => 'snake_case handle for the new set. Defaults to the component handle without its "component_" prefix.',
                        ],
                        'display' => [
                            'type' => 'string',
                            'description' => 'Human label editors see when picking the block (defaults to the component fieldset\'s title).',
                        ],
                        'icon' => [
                            'type' => 'string',
                            'description' => 'Optional icon name, matching the style of the container\'s existing sets.',
                        ],
                        'group' => [
                            'type' => 'string',
                            'description' => 'Set-group handle when the container organizes sets in groups. Defaults to the first group.',
                        ],
                    ],
                    'required' => ['component', 'container'],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $component = $this->stringArg($args, 'component');
        $container = $this->stringArg($args, 'container');

        if (! $component || ! Fieldset::find($component)) {
            return ['ok' => false, 'error' => "Component fieldset \"{$component}\" not found. Create it first with create_fieldset."];
        }

        $containerFieldset = $container !== '' ? Fieldset::find($container) : null;
        if (! $containerFieldset) {
            $available = Fieldset::all()->map(fn ($fs) => (string) $fs->handle())->sort()->values()->implode(', ');

            return ['ok' => false, 'error' => "Container fieldset \"{$container}\" not found. Available fieldsets: ".($available !== '' ? $available : '(none)')];
        }

        $setHandle = $this->stringArg($args, 'set_handle');
        if ($setHandle === '') {
            $setHandle = Str::after($component, 'component_');
        }
        if ($err = $this->invalidHandleError($setHandle, 'set')) {
            return ['ok' => false, 'error' => $err];
        }

        $display = $this->stringArg($args, 'display');
        if ($display === '') {
            $display = (string) (Fieldset::find($component)?->title() ?? Str::title(str_replace('_', ' ', $setHandle)));
        }

        $contents = $containerFieldset->contents();
        $fields = is_array($contents['fields'] ?? null) ? $contents['fields'] : [];

        // Locate the components/replicator field: the first field whose config
        // carries a "sets" map (param-free so the model never has to know the
        // container's internal field handle).
        $fieldIndex = null;
        foreach ($fields as $i => $row) {
            if (is_array($row) && is_array($row['field'] ?? null) && is_array($row['field']['sets'] ?? null)) {
                $fieldIndex = $i;
                break;
            }
        }

        if ($fieldIndex === null) {
            return ['ok' => false, 'error' => "Fieldset \"{$container}\" has no field with \"sets\" — it is not a components container. Use list_fieldsets to find the fieldset whose field imports the component_* fieldsets."];
        }

        $sets = $fields[$fieldIndex]['field']['sets'];

        // Grouped format (sets → group → sets → set) vs flat (sets → set).
        $grouped = $sets !== [] && collect($sets)->every(fn ($v) => is_array($v) && is_array($v['sets'] ?? null));

        $newSet = array_filter([
            'display' => $display,
            'icon' => $this->stringArg($args, 'icon') ?: null,
            'fields' => [['import' => $component]],
        ]);

        if ($grouped) {
            $groupHandle = $this->stringArg($args, 'group');
            if ($groupHandle === '') {
                $groupHandle = (string) array_key_first($sets);
            } elseif (! isset($sets[$groupHandle])) {
                return ['ok' => false, 'error' => "Set group \"{$groupHandle}\" not found in \"{$container}\". Groups: ".implode(', ', array_map('strval', array_keys($sets)))];
            }

            foreach ($sets as $g => $groupConfig) {
                if ($this->groupImportsComponent($groupConfig['sets'], $component)) {
                    return ['ok' => false, 'error' => "Component \"{$component}\" is already registered in \"{$container}\" (group \"{$g}\")."];
                }
                if (isset($groupConfig['sets'][$setHandle])) {
                    return ['ok' => false, 'error' => "Set \"{$setHandle}\" already exists in \"{$container}\" (group \"{$g}\"). Pass a different set_handle."];
                }
            }

            $sets[$groupHandle]['sets'][$setHandle] = $newSet;
        } else {
            if ($this->groupImportsComponent($sets, $component)) {
                return ['ok' => false, 'error' => "Component \"{$component}\" is already registered in \"{$container}\"."];
            }
            if (isset($sets[$setHandle])) {
                return ['ok' => false, 'error' => "Set \"{$setHandle}\" already exists in \"{$container}\". Pass a different set_handle."];
            }

            $sets[$setHandle] = $newSet;
        }

        $context->reportActivity((string) __('Registering component :component in :container', [
            'component' => $component,
            'container' => $container,
        ]));

        $fields[$fieldIndex]['field']['sets'] = $sets;
        $contents['fields'] = $fields;
        $containerFieldset->setContents($contents);
        $containerFieldset->save();

        return [
            'ok' => true,
            'registered' => true,
            'handle' => $component,
            'container' => $container,
            'set_handle' => $setHandle,
            'display' => $display,
        ];
    }

    /**
     * Whether any set in the given set map already imports the component.
     *
     * @param  array<string, mixed>  $sets
     */
    private function groupImportsComponent(array $sets, string $component): bool
    {
        foreach ($sets as $set) {
            foreach ((is_array($set['fields'] ?? null) ? $set['fields'] : []) as $row) {
                if (is_array($row) && ($row['import'] ?? null) === $component) {
                    return true;
                }
            }
        }

        return false;
    }
}
