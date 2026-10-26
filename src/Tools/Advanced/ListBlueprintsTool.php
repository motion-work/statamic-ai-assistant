<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;

/**
 * Lists the blueprints of every collection and taxonomy (handles + titles
 * only) so the model can orient itself before reading or changing structure.
 */
class ListBlueprintsTool extends AbstractAdvancedTool
{
    public function name(): string
    {
        return 'list_blueprints';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_blueprints',
                'description' => 'List all blueprints per collection and taxonomy (handles and titles only). '
                    .'Use read_blueprint afterwards to inspect a specific blueprint\'s fields.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'collection' => [
                            'type' => 'string',
                            'description' => 'Optional collection handle to list only that collection\'s blueprints.',
                        ],
                        'taxonomy' => [
                            'type' => 'string',
                            'description' => 'Optional taxonomy handle to list only that taxonomy\'s blueprints.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $collectionFilter = $this->stringArg($args, 'collection');
        $taxonomyFilter = $this->stringArg($args, 'taxonomy');

        $collections = [];
        foreach (Collection::all() as $collection) {
            if ($collectionFilter !== '' && $collection->handle() !== $collectionFilter) {
                continue;
            }

            $collections[] = [
                'collection' => $collection->handle(),
                'title' => $collection->title(),
                'blueprints' => $collection->entryBlueprints()->map(fn ($bp) => [
                    'handle' => $bp->handle(),
                    'title' => $bp->title(),
                    'hidden' => (bool) $bp->hidden(),
                ])->values()->all(),
            ];
        }

        if ($collectionFilter !== '' && $collections === []) {
            return ['ok' => false, 'error' => "Collection \"{$collectionFilter}\" not found. Available: ".Collection::handles()->sort()->implode(', ')];
        }

        $taxonomies = [];
        foreach (Taxonomy::all() as $taxonomy) {
            if ($taxonomyFilter !== '' && $taxonomy->handle() !== $taxonomyFilter) {
                continue;
            }

            $taxonomies[] = [
                'taxonomy' => $taxonomy->handle(),
                'title' => $taxonomy->title(),
                'blueprints' => $taxonomy->termBlueprints()->map(fn ($bp) => [
                    'handle' => $bp->handle(),
                    'title' => $bp->title(),
                ])->values()->all(),
            ];
        }

        if ($taxonomyFilter !== '' && $taxonomies === []) {
            return ['ok' => false, 'error' => "Taxonomy \"{$taxonomyFilter}\" not found. Available: ".Taxonomy::handles()->sort()->implode(', ')];
        }

        // Form blueprints (contact forms etc.) — one blueprint per form, same
        // handle as the form. Address them via the "form" parameter.
        $forms = \Statamic\Facades\Form::all()->map(fn ($form) => [
            'form' => (string) $form->handle(),
            'title' => (string) $form->title(),
        ])->values()->all();

        return ['ok' => true, 'collections' => $collections, 'taxonomies' => $taxonomies, 'forms' => $forms];
    }

    /** Read-only: uses the shared CMS-read budget, not the write budget. */
    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_cms_reads', 12));
    }
}
