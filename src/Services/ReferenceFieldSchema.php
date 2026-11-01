<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Terms;

/**
 * Schema entries for fields that link to existing content: taxonomy terms
 * (with the term catalog), entries and forms. The model names a target by
 * title or handle; the mapper resolves it.
 */
final class ReferenceFieldSchema
{
    public function __construct(private readonly FieldOptionCatalog $options) {}

    /**
     * @return array<string, mixed>|null
     */
    private function buildTermsFieldSchemaPayload(Field $field, string $siteHandle, ?string $instructions): ?array
    {
        $ft = $field->fieldtype();

        if (! $ft instanceof Terms) {
            return null;
        }

        $taxHandles = $this->options->getTermsFieldTaxonomyHandles($field);

        if ($taxHandles === []) {
            return null;
        }

        $catalog = $this->taxonomyCatalog($taxHandles, $siteHandle);

        if ($catalog === []) {
            return null;
        }

        $single = $ft->usingSingleTaxonomy();
        $maxItems = $field->get('max_items');

        return [
            'label' => $field->display(),
            'generatable' => true,
            'type' => 'taxonomy_terms',
            'taxonomies' => $catalog,
            'single_taxonomy' => $single,
            'max_items' => $maxItems,
            'description' => $this->termsGuidance($single, $maxItems, $instructions),
        ];
    }

    /**
     * Each existing taxonomy of the field with its terms on this site (slug +
     * title), warming the term cache the value mapper resolves against.
     *
     * @param  array<int, string>  $taxHandles
     * @return array<int, array{taxonomy: string, title: string, terms: array<int, array{slug: string, title: string}>}>
     */
    private function taxonomyCatalog(array $taxHandles, string $siteHandle): array
    {
        $catalog = [];

        foreach ($taxHandles as $th) {
            $taxonomy = Taxonomy::findByHandle($th);

            if (! $taxonomy) {
                continue;
            }

            $terms = Term::query()
                ->where('site', $siteHandle)
                ->where('taxonomy', $th)
                ->orderBy('slug')
                ->get();

            $this->options->warmTaxonomyTermCache($siteHandle, $th, $terms);

            $termEntries = [];

            foreach ($terms as $term) {
                $termEntries[] = [
                    'slug' => $term->slug(),
                    'title' => (string) ($term->get('title') ?? $term->slug()),
                ];
            }

            $catalog[] = [
                'taxonomy' => $th,
                'title' => $taxonomy->title(),
                'terms' => $termEntries,
            ];
        }

        return $catalog;
    }

    private function termsGuidance(bool $single, mixed $maxItems, ?string $instructions): string
    {
        $descParts = [];

        if ($instructions) {
            $descParts[] = $instructions;
        }

        $descParts[] = $single
            ? 'Choose from the listed term slugs for this taxonomy. Output must use the exact slug from the list (you may infer the best match from the user prompt).'
            : 'Use taxonomy_handle::term_slug for each value, using only slugs from the corresponding taxonomy list in the catalog.';

        if ($maxItems === 1) {
            $descParts[] = $single
                ? 'Return a single slug string, not an array.'
                : 'Return a single taxonomy_handle::term_slug string, not an array.';
        } elseif ($maxItems !== null) {
            $descParts[] = "Return at most {$maxItems} ".($single ? 'slugs' : 'taxonomy_handle::term_slug values').'.';
        } else {
            $descParts[] = $single
                ? 'Return an array of slugs when multiple terms apply.'
                : 'Return an array of taxonomy_handle::term_slug strings when multiple terms apply.';
        }

        return implode(' ', $descParts);
    }

    /**
     * @return array{label: string, generatable: true, type: string, collections: array<int, string>, max_items: ?int, description: string}
     */
    private function buildEntriesFieldSchemaPayload(Field $field, ?string $instructions): array
    {
        $collections = $this->options->entriesFieldCollectionHandles($field);
        $maxItems = is_numeric($field->get('max_items')) ? (int) $field->get('max_items') : null;

        $descParts = [];
        if ($instructions) {
            $descParts[] = $instructions;
        }
        $descParts[] = 'Link EXISTING entries by their exact title (or slug). Only reference entries that already exist'
            .($collections !== [] ? ' in the collection(s): '.implode(', ', $collections).'.' : '.')
            .' Never invent an entry. If the target is being created in this same request and does not exist yet, leave this empty and still create the entry — never refuse or abort over a missing link.';
        $descParts[] = $maxItems === 1
            ? 'Return a single title string, or an empty string if none applies.'
            : 'Return an array of titles (empty array if none applies)'.($maxItems !== null ? ", at most {$maxItems}." : '.');

        return [
            'label' => $field->display(),
            'generatable' => true,
            'type' => 'entry_references',
            'collections' => $collections,
            'max_items' => $maxItems,
            'description' => implode(' ', $descParts),
        ];
    }

    /**
     * @return array{label: string, generatable: true, type: string, forms: array<int, array{handle: string, title: string}>, max_items: ?int, description: string}|null
     */
    private function buildFormFieldSchemaPayload(Field $field, ?string $instructions): ?array
    {
        $forms = $this->options->availableForms();
        if ($forms === []) {
            // No forms exist on the site — nothing the model could validly pick.
            return null;
        }

        $maxItems = is_numeric($field->get('max_items')) ? (int) $field->get('max_items') : 1;

        $descParts = [];
        if ($instructions) {
            $descParts[] = $instructions;
        }
        $descParts[] = 'Link one of the site\'s EXISTING forms by its exact handle from the "forms" list below (match the user\'s intent, e.g. a contact form). Never invent a form handle.';
        $descParts[] = $maxItems === 1
            ? 'Return a single handle string, or an empty string if no form applies.'
            : 'Return an array of handles (empty array if none applies)'.($maxItems > 1 ? ", at most {$maxItems}." : '.');

        return [
            'label' => $field->display(),
            'generatable' => true,
            'type' => 'form_reference',
            'forms' => $forms,
            'max_items' => $maxItems,
            'description' => implode(' ', $descParts),
        ];
    }

    /**
     * Terms, entries and forms point at other content; null when there is
     * nothing to choose from.
     *
     * @return array<string, mixed>|null
     */
    public function referenceSchemaPayload(Field $field, string $siteHandle, ?string $instructions): ?array
    {
        return match ($field->type()) {
            'terms' => $this->buildTermsFieldSchemaPayload($field, $siteHandle, $instructions),
            'entries' => $this->buildEntriesFieldSchemaPayload($field, $instructions),
            'form' => $this->buildFormFieldSchemaPayload($field, $instructions),
        };
    }
}
