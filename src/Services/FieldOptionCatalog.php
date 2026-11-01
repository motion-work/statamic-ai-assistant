<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Facades\Form;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Fields\Field;
use Statamic\Fieldtypes\Terms;

/**
 * What a field accepts: a choice field's options, the taxonomies and terms of
 * a terms field, the collections of an entries field, the available forms.
 * Shared by the schema builder and the value mapper, so a request's term
 * lookups are warmed once.
 */
final class FieldOptionCatalog
{
    /**
     * @var array<string, array{by_lower_slug: array<string, string>, by_lower_title: array<string, string>}>
     */
    private array $taxonomyTermMatchCache = [];

    /**
     * @return array<int, string>
     */
    public function getTermsFieldTaxonomyHandles(Field $field): array
    {
        $configTax = $field->get('taxonomies');

        if ($configTax === null || $configTax === []) {
            return Taxonomy::handles()->values()->all();
        }

        $out = [];

        foreach (Arr::wrap($configTax) as $item) {
            if (is_string($item) || is_int($item)) {
                $h = trim((string) $item);

                if ($h !== '') {
                    $out[] = $h;
                }

                continue;
            }

            if (is_array($item)) {
                $h = $item['handle'] ?? $item['value'] ?? $item['taxonomy'] ?? null;

                if (is_string($h) && trim($h) !== '') {
                    $out[] = trim($h);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  iterable<int, \Statamic\Taxonomies\Term>  $terms
     */
    public function warmTaxonomyTermCache(string $siteHandle, string $taxonomyHandle, iterable $terms): void
    {
        $key = $siteHandle.'|'.$taxonomyHandle;
        $byLowerSlug = [];
        $byLowerTitle = [];

        foreach ($terms as $term) {
            $slug = $term->slug();
            $title = (string) ($term->get('title') ?? $slug);
            $byLowerSlug[strtolower($slug)] = $slug;
            $byLowerTitle[strtolower(trim($title))] = $slug;
        }

        $this->taxonomyTermMatchCache[$key] = [
            'by_lower_slug' => $byLowerSlug,
            'by_lower_title' => $byLowerTitle,
        ];
    }

    private function ensureTaxonomyTermCache(string $siteHandle, string $taxonomyHandle): void
    {
        $key = $siteHandle.'|'.$taxonomyHandle;

        if (isset($this->taxonomyTermMatchCache[$key])) {
            return;
        }

        $terms = Term::query()
            ->where('site', $siteHandle)
            ->where('taxonomy', $taxonomyHandle)
            ->get();

        $this->warmTaxonomyTermCache($siteHandle, $taxonomyHandle, $terms);
    }

    public function resolveSlugInTaxonomy(string $raw, string $taxonomyHandle, string $siteHandle): ?string
    {
        $this->ensureTaxonomyTermCache($siteHandle, $taxonomyHandle);
        $key = $siteHandle.'|'.$taxonomyHandle;
        $cache = $this->taxonomyTermMatchCache[$key];
        $lower = strtolower(trim($raw));

        if (isset($cache['by_lower_slug'][$lower])) {
            return $cache['by_lower_slug'][$lower];
        }

        if (isset($cache['by_lower_title'][$lower])) {
            return $cache['by_lower_title'][$lower];
        }

        $lang = Site::get($siteHandle)?->lang() ?? Site::default()->lang();
        $slugified = Str::slug($raw, '-', $lang);
        $lowerSlug = strtolower($slugified);

        if ($slugified !== '' && isset($cache['by_lower_slug'][$lowerSlug])) {
            return $cache['by_lower_slug'][$lowerSlug];
        }

        return null;
    }

    /** Case/punctuation-insensitive key so "Salt & Stone" == "salt and stone" == "salt-stone". */
    public function normalizeEntryRef(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(['&', '+'], ' and ', $s);
        $s = preg_replace('~[^a-z0-9]+~u', ' ', $s) ?? $s;

        return trim(preg_replace('~\s+~', ' ', $s) ?? $s);
    }

    /** @return array<int, string> */
    public function entriesFieldCollectionHandles(Field $field): array
    {
        $configured = $field->get('collections');
        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($c) => is_string($c) ? $c : null,
            $configured,
        )));
    }

    /** @return array<int, array{handle: string, title: string}> */
    public function availableForms(): array
    {
        return Form::all()
            ->map(fn ($form) => ['handle' => (string) $form->handle(), 'title' => (string) $form->title()])
            ->values()
            ->all();
    }

    /**
     * Whether a choice field stores an ARRAY of values (checkboxes, or a
     * select/button_group configured with multiple: true).
     */
    public function choiceFieldIsMultiple(Field $field): bool
    {
        return $field->type() === 'checkboxes' || (bool) ($field->config()['multiple'] ?? false);
    }

    /**
     * The STORED values of a choice field's options — the single source used by
     * the prompt schema, the mapper validation, and the required-field fallback,
     * so the model is always shown exactly the values that will be accepted.
     *
     * Statamic option configs come in three shapes: a flat list of values
     * (['red', 'blue']), a value => label map (['red' => 'Red']), or a list of
     * {key, value} rows ([['key' => 'red', 'value' => 'Red']]). The stored value
     * is the list item, the map KEY, or the row's `key` — never the label.
     *
     * @return array<int, string>
     */
    public function choiceFieldOptionValues(Field $field): array
    {
        $options = $field->config()['options'] ?? [];

        if (! is_array($options) || $options === []) {
            return [];
        }

        $values = [];

        foreach ($options as $key => $opt) {
            if (is_array($opt)) {
                $v = $opt['key'] ?? $opt['value'] ?? null;
            } elseif (is_string($key) && $key !== '') {
                $v = $key;
            } else {
                $v = is_scalar($opt) ? $opt : null;
            }

            if ($v !== null && $v !== '') {
                $values[] = (string) $v;
            }
        }

        return array_values(array_unique($values));
    }

    public function firstSelectOptionValue(Field $field): ?string
    {
        return $this->choiceFieldOptionValues($field)[0] ?? null;
    }
}
