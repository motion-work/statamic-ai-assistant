<?php

namespace MotionWork\StatamicAiAssistant\Tools\Advanced;

use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use Illuminate\Support\Str;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * Adds terms to an EXISTING taxonomy. create_taxonomy only makes the container
 * (handle + title + sites); this fills it with terms — the step that was
 * previously impossible, so "create a taxonomy with terms X, Y" silently
 * dropped the terms.
 *
 * Idempotent: a term whose slug already exists is skipped (never duplicated),
 * so it is safe to call right after create_taxonomy in the same turn, to
 * re-run, or to top up a taxonomy that already has some terms.
 */
class CreateTermsTool extends AbstractAdvancedTool
{
    public function name(): string
    {
        return 'create_terms';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'create_terms',
                'description' => 'Add one or more terms to an EXISTING taxonomy (create the taxonomy first with create_taxonomy). '
                    .'Applies IMMEDIATELY — only when the user explicitly asked. Terms already present (matched by slug) are skipped, not duplicated. '
                    .'Titles are written in the site default locale; translate them into other locales afterwards if needed.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'taxonomy' => [
                            'type' => 'string',
                            'description' => 'Handle of the taxonomy to add terms to (must already exist).',
                        ],
                        'terms' => [
                            'type' => 'array',
                            'items' => ['type' => 'object'],
                            'description' => 'Terms to create: [{"title": "Kulinarik"}, {"title": "Live Musik", "slug": "musik"}]. '
                                .'"slug" is optional and derived from the title when omitted. A bare string title is also accepted.',
                        ],
                    ],
                    'required' => ['taxonomy', 'terms'],
                ],
            ],
        ];
    }

    protected function run(array $args, ToolContext $context): array
    {
        $handle = $this->stringArg($args, 'taxonomy');
        if ($err = $this->invalidHandleError($handle, 'taxonomy')) {
            return ['ok' => false, 'error' => $err];
        }

        if (! Taxonomy::find($handle)) {
            return ['ok' => false, 'error' => "Taxonomy \"{$handle}\" does not exist. Available: ".Taxonomy::handles()->sort()->implode(', ').'. Create it first with create_taxonomy.'];
        }

        $rawTerms = isset($args['terms']) && is_array($args['terms']) ? $args['terms'] : [];
        if ($rawTerms === []) {
            return ['ok' => false, 'error' => 'Provide at least one term as {"title": "..."} (optional "slug").'];
        }

        $locale = Site::default()->handle();

        $context->reportActivity((string) __('Adding terms to taxonomy :taxonomy', ['taxonomy' => $handle]));

        $created = [];
        $skipped = [];
        $seen = [];

        foreach ($rawTerms as $index => $item) {
            // Accept a bare string title as a convenience, or a {title, slug} object.
            if (is_string($item)) {
                $title = trim($item);
                $slug = '';
            } elseif (is_array($item)) {
                $title = isset($item['title']) && is_string($item['title']) ? trim($item['title']) : '';
                $slug = isset($item['slug']) && is_string($item['slug']) ? trim($item['slug']) : '';
            } else {
                return ['ok' => false, 'error' => "Term at index {$index} must be a string title or an object {\"title\": \"...\"}."];
            }

            if ($title === '') {
                return ['ok' => false, 'error' => "Term at index {$index} is missing a non-empty \"title\"."];
            }

            $slug = Str::slug($slug !== '' ? $slug : $title);
            if ($slug === '') {
                return ['ok' => false, 'error' => "Term \"{$title}\" produced an empty slug — provide an explicit \"slug\"."];
            }

            // De-dupe within this call, then against what already exists on disk.
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            if (Term::find($handle.'::'.$slug)) {
                $skipped[] = $slug;

                continue;
            }

            $term = Term::make()->taxonomy($handle)->slug($slug);
            $term->dataForLocale($locale, ['title' => $title]);
            $term->save();

            $created[] = $slug;
        }

        return [
            'ok' => true,
            'taxonomy' => $handle,
            'created' => $created,
            'skipped' => $skipped,
        ];
    }
}
