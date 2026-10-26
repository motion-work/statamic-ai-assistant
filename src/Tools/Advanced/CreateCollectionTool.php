<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

/**
 * Creates a new collection (all sites by default). A fresh collection has no
 * blueprint yet — the result tells the model to follow up with
 * create_blueprint before entries can be generated into it.
 */
class CreateCollectionTool extends AbstractAdvancedTool
{
    public function name(): string
    {
        return 'create_collection';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_collection',
                'description' => 'Create a NEW collection. Applies IMMEDIATELY — only do this when the user explicitly asked for a new collection. '
                    .'A new collection has NO blueprint: call create_blueprint right after, otherwise no entries can be created in it.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'snake_case handle (e.g. "events").',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Human title (e.g. "Events").',
                        ],
                        'route' => [
                            'type' => 'string',
                            'description' => 'Optional URL pattern (e.g. "/events/{slug}"). Omit for no public routes.',
                        ],
                        'dated' => [
                            'type' => 'boolean',
                            'description' => 'true when entries are date-ordered (news, events, blog). Default false.',
                        ],
                        'template' => [
                            'type' => 'string',
                            'description' => 'Optional default template (only if the user named one).',
                        ],
                        'taxonomies' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional taxonomy handles to attach (must already exist).',
                        ],
                    ],
                    'required' => ['handle', 'title'],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $handle = $this->stringArg($args, 'handle');
        if ($err = $this->invalidHandleError($handle, 'collection')) {
            return ['ok' => false, 'error' => $err];
        }

        $title = $this->stringArg($args, 'title');
        if ($title === '') {
            return ['ok' => false, 'error' => 'Collection title is required.'];
        }

        if (Collection::find($handle)) {
            return ['ok' => false, 'error' => "Collection \"{$handle}\" already exists. Use configure_collection to change it."];
        }

        $taxonomies = [];
        if (isset($args['taxonomies']) && is_array($args['taxonomies'])) {
            foreach ($args['taxonomies'] as $tax) {
                if (! is_string($tax) || trim($tax) === '') {
                    continue;
                }
                $tax = trim($tax);

                if (! Taxonomy::find($tax)) {
                    return ['ok' => false, 'error' => "Taxonomy \"{$tax}\" does not exist. Available: ".Taxonomy::handles()->sort()->implode(', ').'. Create it first with create_taxonomy.'];
                }

                $taxonomies[] = $tax;
            }
        }

        $context->reportActivity((string) __('Creating collection :title', ['title' => $title]));

        $collection = Collection::make($handle)
            ->title($title)
            ->sites(Site::all()->map->handle()->values()->all());

        $route = $this->stringArg($args, 'route');
        if ($route !== '') {
            $collection->routes($route);
        }

        if (($args['dated'] ?? false) === true) {
            $collection->dated(true);
        }

        $template = $this->stringArg($args, 'template');
        if ($template !== '') {
            $collection->template($template);
        }

        if ($taxonomies !== []) {
            $collection->taxonomies($taxonomies);
        }

        $collection->save();

        return [
            'ok' => true,
            'created' => true,
            'handle' => $collection->handle(),
            'title' => $collection->title(),
            'next_step' => 'This collection has no blueprint yet. Call create_blueprint with collection="'.$handle.'" to define its fields — entries cannot be generated before that.',
        ];
    }
}
