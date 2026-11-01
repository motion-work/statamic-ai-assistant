<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
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

        if ($err = $this->fieldsetsError($component, $container)) {
            return ['ok' => false, 'error' => $err];
        }
        $containerFieldset = Fieldset::find($container);

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

        $fieldIndex = $this->setsFieldIndex($fields);

        if ($fieldIndex === null) {
            return ['ok' => false, 'error' => "Fieldset \"{$container}\" has no field with \"sets\" — it is not a components container. Use list_fieldsets to find the fieldset whose field imports the component_* fieldsets."];
        }

        $newSet = array_filter([
            'display' => $display,
            'icon' => $this->stringArg($args, 'icon') ?: null,
            'fields' => [['import' => $component]],
        ]);

        $sets = $this->addSet($fields[$fieldIndex]['field']['sets'], $component, $container, $setHandle, $newSet, $this->stringArg($args, 'group'));
        if (is_string($sets)) {
            return ['ok' => false, 'error' => $sets];
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

    private function fieldsetsError(string $component, string $container): ?string
    {
        if (! $component || ! Fieldset::find($component)) {
            return "Component fieldset \"{$component}\" not found. Create it first with create_fieldset.";
        }

        if ($container === '' || ! Fieldset::find($container)) {
            $available = Fieldset::all()->map(fn ($fs) => (string) $fs->handle())->sort()->values()->implode(', ');

            return "Container fieldset \"{$container}\" not found. Available fieldsets: ".($available !== '' ? $available : '(none)');
        }

        return null;
    }

    /**
     * Sets come grouped (sets → group → sets → set) or flat (sets → set).
     *
     * @param  array<string, mixed>  $sets
     * @param  array<string, mixed>  $newSet
     * @return array<string, mixed>|string
     */
    private function addSet(array $sets, string $component, string $container, string $setHandle, array $newSet, string $group): array|string
    {
        $grouped = $sets !== [] && collect($sets)->every(fn ($v) => is_array($v) && is_array($v['sets'] ?? null));

        return $grouped
            ? $this->addToGroupedSets($sets, $component, $container, $setHandle, $newSet, $group)
            : $this->addToFlatSets($sets, $component, $container, $setHandle, $newSet);
    }

    /**
     * The components/replicator field: the first field whose config carries a
     * "sets" map (param-free so the model never has to know the container's
     * internal field handle).
     *
     * @param  array<int, mixed>  $fields
     */
    private function setsFieldIndex(array $fields): ?int
    {
        foreach ($fields as $i => $row) {
            if (is_array($row) && is_array($row['field'] ?? null) && is_array($row['field']['sets'] ?? null)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Sets grouped as sets → group → sets → set. The set goes into $groupHandle
     * (the first group when empty). The new sets, or why it cannot be added.
     *
     * @param  array<string, mixed>  $sets
     * @param  array<string, mixed>  $newSet
     * @return array<string, mixed>|string
     */
    private function addToGroupedSets(array $sets, string $component, string $container, string $setHandle, array $newSet, string $groupHandle): array|string
    {
        if ($groupHandle === '') {
            $groupHandle = (string) array_key_first($sets);
        } elseif (! isset($sets[$groupHandle])) {
            return "Set group \"{$groupHandle}\" not found in \"{$container}\". Groups: ".implode(', ', array_map('strval', array_keys($sets)));
        }

        foreach ($sets as $g => $groupConfig) {
            if ($this->groupImportsComponent($groupConfig['sets'], $component)) {
                return "Component \"{$component}\" is already registered in \"{$container}\" (group \"{$g}\").";
            }
            if (isset($groupConfig['sets'][$setHandle])) {
                return "Set \"{$setHandle}\" already exists in \"{$container}\" (group \"{$g}\"). Pass a different set_handle.";
            }
        }

        $sets[$groupHandle]['sets'][$setHandle] = $newSet;

        return $sets;
    }

    /**
     * @param  array<string, mixed>  $sets
     * @param  array<string, mixed>  $newSet
     * @return array<string, mixed>|string
     */
    private function addToFlatSets(array $sets, string $component, string $container, string $setHandle, array $newSet): array|string
    {
        if ($this->groupImportsComponent($sets, $component)) {
            return "Component \"{$component}\" is already registered in \"{$container}\".";
        }
        if (isset($sets[$setHandle])) {
            return "Set \"{$setHandle}\" already exists in \"{$container}\". Pass a different set_handle.";
        }

        $sets[$setHandle] = $newSet;

        return $sets;
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
