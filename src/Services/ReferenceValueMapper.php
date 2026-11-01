<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\Form;
use Statamic\Facades\Term;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Terms;

/**
 * Maps the model's values for fields that point at other content: terms (by
 * slug, title or a new term), entries (by id, title or slug) and forms. An
 * unknown reference is dropped with a warning, never stored as is.
 */
final class ReferenceValueMapper
{
    public function __construct(
        private FieldOptionCatalog $options,
        private EntrySearch $search,
    ) {}

    /**
     * The model's value for a terms field as (taxonomy, raw term) pairs: a
     * {taxonomy: term(s)} map, a single string (optionally "taxonomy::term"),
     * or a list of either.
     *
     * @param  array<int, string>  $taxHandles
     * @return list<array{tax: ?string, raw: string}>
     */
    private function flattenTermsLlmValue(mixed $value, bool $singleTaxonomy, array $taxHandles): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_array($value) && Arr::isAssoc($value)) {
            return $this->flattenTermsMap($value, $singleTaxonomy, $taxHandles);
        }

        if (is_string($value) || is_numeric($value)) {
            return $this->flattenTermString(trim((string) $value), $singleTaxonomy, $taxHandles);
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $out = array_merge($out, $this->flattenTermsLlmValue((string) $item, $singleTaxonomy, $taxHandles));
        }

        return $out;
    }

    /**
     * A {taxonomy: term(s)} map. With a single taxonomy only the first value counts.
     *
     * @param  array<int|string, mixed>  $value
     * @param  array<int, string>  $taxHandles
     * @return list<array{tax: ?string, raw: string}>
     */
    private function flattenTermsMap(array $value, bool $singleTaxonomy, array $taxHandles): array
    {
        if ($singleTaxonomy) {
            $first = reset($value);
            $raw = is_scalar($first) ? trim((string) $first) : '';

            return $raw === '' ? [] : [['tax' => $taxHandles[0] ?? null, 'raw' => $raw]];
        }

        $out = [];

        foreach ($value as $k => $v) {
            foreach (Arr::wrap($v) as $one) {
                $raw = is_scalar($one) ? trim((string) $one) : '';

                if ($raw !== '') {
                    $out[] = ['tax' => (string) $k, 'raw' => $raw];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $taxHandles
     * @return list<array{tax: ?string, raw: string}>
     */
    private function flattenTermString(string $s, bool $singleTaxonomy, array $taxHandles): array
    {
        if ($s === '') {
            return [];
        }

        if ($singleTaxonomy) {
            return [['tax' => $taxHandles[0] ?? null, 'raw' => $s]];
        }

        if (str_contains($s, '::')) {
            [$t, $r] = explode('::', $s, 2);

            return [['tax' => trim($t), 'raw' => trim($r)]];
        }

        return [['tax' => null, 'raw' => $s]];
    }

    /**
     * @param  array<int, string>  $taxHandles
     */
    private function resolveRawToTaxonomySlug(?string $taxonomyHint, string $raw, array $taxHandles, string $siteHandle, Field $field, array &$warnings): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '::')) {
            [$taxonomyHint, $raw] = explode('::', $raw, 2);
            $taxonomyHint = trim((string) $taxonomyHint);
            $raw = trim($raw);
        }

        if ($taxonomyHint !== null && $taxonomyHint !== '' && ! in_array($taxonomyHint, $taxHandles, true)) {
            $warnings[] = __(':field: unknown taxonomy ":t".', [
                'field' => $field->display(),
                't' => $taxonomyHint,
            ]);

            return null;
        }

        if ($taxonomyHint !== null && $taxonomyHint !== '') {
            $slug = $this->options->resolveSlugInTaxonomy($raw, $taxonomyHint, $siteHandle);

            if ($slug === null) {
                $warnings[] = __(':field: no matching term for ":raw" in taxonomy :tax.', [
                    'field' => $field->display(),
                    'raw' => $raw,
                    'tax' => $taxonomyHint,
                ]);

                return null;
            }

            return "{$taxonomyHint}::{$slug}";
        }

        if (count($taxHandles) === 1) {
            $th = $taxHandles[0];
            $slug = $this->options->resolveSlugInTaxonomy($raw, $th, $siteHandle);

            if ($slug === null) {
                $warnings[] = __(':field: no matching term for ":raw".', [
                    'field' => $field->display(),
                    'raw' => $raw,
                ]);

                return null;
            }

            return "{$th}::{$slug}";
        }

        foreach ($taxHandles as $th) {
            $slug = $this->options->resolveSlugInTaxonomy($raw, $th, $siteHandle);

            if ($slug !== null) {
                return "{$th}::{$slug}";
            }
        }

        $warnings[] = __(':field: no matching term for ":raw" in any configured taxonomy.', [
            'field' => $field->display(),
            'raw' => $raw,
        ]);

        return null;
    }

    public function mapTermsFieldValue(mixed $value, Field $field, array &$warnings, string $siteHandle): mixed
    {
        $ft = $field->fieldtype();

        if (! $ft instanceof Terms) {
            return null;
        }

        $taxHandles = $this->options->getTermsFieldTaxonomyHandles($field);

        if ($taxHandles === []) {
            return null;
        }

        $singleTax = $ft->usingSingleTaxonomy();
        $flat = $this->flattenTermsLlmValue($value, $singleTax, $taxHandles);

        if ($flat === []) {
            return null;
        }

        $resolved = [];

        foreach ($flat as $item) {
            $id = $this->resolveRawToTaxonomySlug($item['tax'], $item['raw'], $taxHandles, $siteHandle, $field, $warnings);

            if ($id !== null) {
                $resolved[] = $id;
            }
        }

        $resolved = array_values(array_unique($resolved));

        if ($resolved === []) {
            return null;
        }

        $maxItems = $field->get('max_items');
        $maxItems = is_numeric($maxItems) ? (int) $maxItems : null;

        if ($maxItems !== null && count($resolved) > $maxItems) {
            $resolved = array_slice($resolved, 0, $maxItems);
            $warnings[] = __(':field: too many taxonomy terms; only the first :n kept.', [
                'field' => $field->display(),
                'n' => $maxItems,
            ]);
        }

        $maxOne = $maxItems === 1;

        if ($singleTax) {
            $slugs = [];

            foreach ($resolved as $id) {
                $parts = explode('::', $id, 2);
                $slugs[] = $parts[1] ?? $parts[0];
            }

            if ($maxOne) {
                return $slugs[0] ?? null;
            }

            return $slugs;
        }

        if ($maxOne) {
            return $resolved[0] ?? null;
        }

        return $resolved;
    }

    /**
     * Resolve the model's entry references (titles/slugs) to real entry ids,
     * scoped to the field's target collections. Unresolved references become a
     * warning — never a silent drop.
     */
    public function mapEntriesFieldValue(mixed $value, Field $field, array &$warnings): mixed
    {
        $refs = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $refs[] = trim($item);
            } elseif (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                $refs[] = trim($item['id']); // tolerate {id:…}/{title:…} shapes
            } elseif (is_array($item) && isset($item['title']) && is_string($item['title'])) {
                $refs[] = trim($item['title']);
            }
        }

        if ($refs === []) {
            return null;
        }

        $collections = $this->options->entriesFieldCollectionHandles($field);
        $ids = [];
        foreach (array_unique($refs) as $ref) {
            $id = $this->resolveEntryReference($ref, $collections);
            if ($id !== null) {
                $ids[] = $id;
            } else {
                $warnings[] = __(':field: could not link ":ref" — no existing entry with that title/slug was found.', [
                    'field' => $field->display(),
                    'ref' => Str::limit($ref, 60),
                ]);
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return null;
        }

        $maxItems = is_numeric($field->get('max_items')) ? (int) $field->get('max_items') : null;
        if ($maxItems !== null && count($ids) > $maxItems) {
            $ids = array_slice($ids, 0, $maxItems);
        }

        return $maxItems === 1 ? $ids[0] : $ids;
    }

    /**
     * Best existing-entry id for a title/slug reference, scoped to $collections
     * (all collections when empty). Requires a confident title/slug match so a
     * vague reference never links the wrong entry.
     */
    private function resolveEntryReference(string $ref, array $collections): ?string
    {
        $needle = $this->options->normalizeEntryRef($ref);
        $scopes = $collections === [] ? [null] : $collections;

        foreach ($scopes as $collection) {
            foreach ($this->search->findEntriesShortlist($collection, $ref, 10) as $row) {
                if ($this->options->normalizeEntryRef((string) ($row['title'] ?? '')) === $needle
                    || $this->options->normalizeEntryRef((string) ($row['slug'] ?? '')) === $needle) {
                    return (string) $row['id'];
                }
            }
        }

        return null;
    }

    /**
     * Resolve the model's form reference(s) — handle or title — to valid existing
     * form handles. Unresolved references warn rather than vanish.
     */
    public function mapFormFieldValue(mixed $value, Field $field, array &$warnings): mixed
    {
        $refs = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $refs[] = trim($item);
            } elseif (is_array($item) && isset($item['handle']) && is_string($item['handle'])) {
                $refs[] = trim($item['handle']); // tolerate {handle:…}/{id:…} shapes
            } elseif (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                $refs[] = trim($item['id']);
            }
        }

        if ($refs === []) {
            return null;
        }

        $handles = [];
        foreach (array_unique($refs) as $ref) {
            $handle = $this->resolveFormReference($ref);
            if ($handle !== null) {
                $handles[] = $handle;
            } else {
                $warnings[] = __(':field: could not link the form ":ref" — no form with that handle/title exists.', [
                    'field' => $field->display(),
                    'ref' => Str::limit($ref, 60),
                ]);
            }
        }

        $handles = array_values(array_unique($handles));
        if ($handles === []) {
            return null;
        }

        $maxItems = is_numeric($field->get('max_items')) ? (int) $field->get('max_items') : 1;
        if ($maxItems >= 1 && count($handles) > $maxItems) {
            $handles = array_slice($handles, 0, $maxItems);
        }

        // A relationship with max_items 1 stores a single handle string.
        return $maxItems === 1 ? $handles[0] : $handles;
    }

    /** Match a handle/title reference to an existing form handle (case/punct-insensitive on title). */
    private function resolveFormReference(string $ref): ?string
    {
        $needle = $this->options->normalizeEntryRef($ref);

        foreach ($this->options->availableForms() as $form) {
            if ($form['handle'] === $ref
                || $this->options->normalizeEntryRef($form['handle']) === $needle
                || $this->options->normalizeEntryRef($form['title']) === $needle) {
                return $form['handle'];
            }
        }

        return null;
    }

    public function firstTermValueForTermsField(Field $field, string $siteHandle): mixed
    {
        $ft = $field->fieldtype();

        if (! $ft instanceof Terms) {
            return null;
        }

        $taxHandles = $this->options->getTermsFieldTaxonomyHandles($field);
        $taxHandles = array_values($taxHandles);
        sort($taxHandles);

        foreach ($taxHandles as $th) {
            $term = Term::query()
                ->where('site', $siteHandle)
                ->where('taxonomy', $th)
                ->orderBy('slug')
                ->first();

            if (! $term) {
                continue;
            }

            $slug = $term->slug();
            $maxItemsCfg = $field->get('max_items');
            $maxOne = is_numeric($maxItemsCfg) && (int) $maxItemsCfg === 1;

            if ($ft->usingSingleTaxonomy()) {
                if ($maxOne) {
                    return $slug;
                }

                return [$slug];
            }

            $id = "{$th}::{$slug}";

            if ($maxOne) {
                return $id;
            }

            return [$id];
        }

        return null;
    }
}
