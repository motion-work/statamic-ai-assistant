<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

/**
 * Creates a new taxonomy (all sites by default). Attaching it to a collection
 * is a separate, explicit step via configure_collection — the result says so.
 */
class CreateTaxonomyTool extends AbstractAdvancedTool
{
    public function name(): string
    {
        return 'create_taxonomy';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_taxonomy',
                'description' => 'Create a NEW taxonomy (e.g. topics, categories). Applies IMMEDIATELY — only when the user explicitly asked. '
                    .'To use it on entries, attach it to a collection afterwards with configure_collection {"settings": {"taxonomies": [...]}}.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'handle' => [
                            'type' => 'string',
                            'description' => 'snake_case handle (e.g. "topics").',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Human title (e.g. "Topics").',
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
        if ($err = $this->invalidHandleError($handle, 'taxonomy')) {
            return ['ok' => false, 'error' => $err];
        }

        $title = $this->stringArg($args, 'title');
        if ($title === '') {
            return ['ok' => false, 'error' => 'Taxonomy title is required.'];
        }

        if (Taxonomy::find($handle)) {
            return ['ok' => false, 'error' => "Taxonomy \"{$handle}\" already exists."];
        }

        $context->reportActivity((string) __('Creating taxonomy :title', ['title' => $title]));

        $taxonomy = Taxonomy::make($handle)
            ->title($title)
            ->sites(Site::all()->map->handle()->values()->all());
        $taxonomy->save();

        return [
            'ok' => true,
            'created' => true,
            'handle' => $taxonomy->handle(),
            'title' => $taxonomy->title(),
            'next_step' => 'This created the empty taxonomy only. Add its terms with create_terms {"taxonomy": "'.$handle.'", "terms": [{"title": "..."}]}, and to use it on entries attach it to a collection with configure_collection {"handle": "<collection>", "settings": {"taxonomies": ["'.$handle.'"]}}.',
        ];
    }
}
