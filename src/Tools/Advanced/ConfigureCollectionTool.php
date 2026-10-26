<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;

/**
 * Changes settings of an existing collection through an explicit whitelist —
 * unknown keys are reported back as ignored (never silently dropped), so the
 * model learns what it can and cannot configure.
 */
class ConfigureCollectionTool extends AbstractAdvancedTool
{
    private const SETTABLE = ['title', 'route', 'dated', 'template', 'layout', 'sort_field', 'sort_direction', 'default_status', 'taxonomies'];

    public function name(): string
    {
        return 'configure_collection';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'configure_collection',
                'description' => 'Change settings of an EXISTING collection. Applies IMMEDIATELY — only when the user explicitly asked. '
                    .'Settable keys: '.implode(', ', self::SETTABLE).'. Other keys are ignored and reported back.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'Handle of the collection to configure.',
                        ],
                        'settings' => [
                            'type' => 'object',
                            'description' => 'Key/value settings to change, e.g. {"title": "News", "dated": true, "taxonomies": ["topics"]}.',
                        ],
                    ],
                    'required' => ['handle', 'settings'],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $handle = $this->stringArg($args, 'handle');
        $collection = $handle !== '' ? Collection::find($handle) : null;

        if (! $collection) {
            return ['ok' => false, 'error' => "Collection \"{$handle}\" not found. Available: ".Collection::handles()->sort()->implode(', ')];
        }

        $settings = isset($args['settings']) && is_array($args['settings']) ? $args['settings'] : [];
        if ($settings === []) {
            return ['ok' => false, 'error' => 'Provide at least one setting to change. Settable: '.implode(', ', self::SETTABLE)];
        }

        if (isset($settings['taxonomies'])) {
            $taxonomies = is_array($settings['taxonomies']) ? array_values(array_filter($settings['taxonomies'], 'is_string')) : null;

            if ($taxonomies === null) {
                return ['ok' => false, 'error' => '"taxonomies" must be an array of taxonomy handles.'];
            }

            foreach ($taxonomies as $tax) {
                if (! Taxonomy::find($tax)) {
                    return ['ok' => false, 'error' => "Taxonomy \"{$tax}\" does not exist. Available: ".Taxonomy::handles()->sort()->implode(', ').'. Create it first with create_taxonomy.'];
                }
            }
        }

        $context->reportActivity((string) __('Configuring collection :handle', ['handle' => $handle]));

        $applied = [];
        $ignored = [];

        foreach ($settings as $key => $value) {
            $wasApplied = match ($key) {
                'title' => is_string($value) ? (bool) $collection->title($value) : false,
                'route' => is_string($value) ? (bool) $collection->routes($value) : false,
                'dated' => is_bool($value) ? (bool) $collection->dated($value) : false,
                'template' => is_string($value) ? (bool) $collection->template($value) : false,
                'layout' => is_string($value) ? (bool) $collection->layout($value) : false,
                'sort_field' => is_string($value) ? (bool) $collection->sortField($value) : false,
                'sort_direction' => is_string($value) ? (bool) $collection->sortDirection($value) : false,
                'default_status' => in_array($value, ['published', 'draft'], true) ? (bool) $collection->defaultPublishState($value === 'published') : false,
                'taxonomies' => is_array($value) ? (bool) $collection->taxonomies(array_values(array_filter($value, 'is_string'))) : false,
                default => false,
            };

            if ($wasApplied) {
                $applied[] = (string) $key;
            } else {
                $ignored[] = (string) $key;
            }
        }

        if ($applied === []) {
            return ['ok' => false, 'error' => 'No valid settings applied. Settable keys (with correct value types): '.implode(', ', self::SETTABLE), 'ignored' => $ignored];
        }

        $collection->save();

        $result = [
            'ok' => true,
            'updated' => true,
            'handle' => $collection->handle(),
            'applied' => $applied,
        ];

        if ($ignored !== []) {
            $result['ignored'] = $ignored;
            $result['hint'] = 'Ignored keys are not settable or had a wrong value type. Settable: '.implode(', ', self::SETTABLE);
        }

        return $result;
    }
}
