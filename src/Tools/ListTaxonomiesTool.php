<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * Lets the agent discover taxonomies and their terms so it can populate `terms`
 * fields with real, existing terms (e.g. categories, room types, tags) instead of
 * inventing slugs the CMS doesn't have.
 *
 * Generic by design: taxonomies and terms are read at runtime via the Statamic
 * facades, so it adapts automatically when taxonomies/terms are added or renamed.
 */
class ListTaxonomiesTool implements ChatTool
{
    private const MAX_TERMS = 500;

    public function name(): string
    {
        return 'list_taxonomies';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_taxonomies',
                'description' => 'List Statamic taxonomies, or the terms of one taxonomy. Call with no arguments to get every '
                    .'taxonomy (handle, title, term count). Pass a "taxonomy" handle to get that taxonomy\'s terms (slug + title). '
                    .'Use the returned slugs when setting a `terms` field so you only reference terms that actually exist.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'taxonomy' => [
                            'type' => 'string',
                            'description' => 'Optional taxonomy handle to list terms for (e.g. "topics"). Omit to list taxonomies.',
                        ],
                        'site' => [
                            'type' => 'string',
                            'description' => 'Optional site/locale handle for the term titles. Defaults to the current site.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        try {
            $args = json_decode($argumentsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_arguments_json'];
        }

        $args = is_array($args) ? $args : [];
        $wantHandle = isset($args['taxonomy']) && is_string($args['taxonomy']) ? trim($args['taxonomy']) : '';
        $site = isset($args['site']) && is_string($args['site']) ? trim($args['site']) : '';

        if ($wantHandle === '') {
            $context->reportActivity((string) __('Listing taxonomies'));

            $taxonomies = Taxonomy::all()->map(function ($tax) {
                return [
                    'handle' => (string) $tax->handle(),
                    'title' => (string) $tax->title(),
                    'terms_count' => (int) $this->termQuery((string) $tax->handle(), '')->count(),
                ];
            })->values()->all();

            return ['ok' => true, 'taxonomies' => $taxonomies];
        }

        $taxonomy = Taxonomy::findByHandle($wantHandle);
        if (! $taxonomy) {
            return [
                'ok' => false,
                'error' => 'taxonomy_not_found',
                'taxonomy' => $wantHandle,
                'available' => Taxonomy::all()->map(fn ($t) => $t->handle())->values()->all(),
            ];
        }

        $context->reportActivity((string) __('Reading terms of ":handle"', ['handle' => $wantHandle]));

        $terms = $this->termQuery($wantHandle, $site)->orderBy('slug')->get();

        $rows = [];
        $seen = [];
        foreach ($terms as $term) {
            $slug = (string) $term->slug();
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            $rows[] = [
                'slug' => $slug,
                'title' => (string) ($term->get('title') ?? $slug),
            ];

            if (count($rows) >= self::MAX_TERMS) {
                break;
            }
        }

        return [
            'ok' => true,
            'taxonomy' => (string) $taxonomy->handle(),
            'title' => (string) $taxonomy->title(),
            'terms' => $rows,
            'truncated' => count($terms) > count($rows),
        ];
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_cms_reads', 12));
    }

    private function termQuery(string $taxonomyHandle, string $site)
    {
        $query = Term::query()->where('taxonomy', $taxonomyHandle);

        if ($site !== '') {
            $query->where('site', $site);
        }

        return $query;
    }
}
