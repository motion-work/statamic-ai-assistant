<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Facades\Collection;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

class EntryGeneratorLinkFallback
{
    /** @var array<string, string|null> */
    private array $fallbackEntryLinkByLocale = [];

    /**
     * When a Statamic link field is still empty after generation, set it to a configured
     * entry (e.g. home) so the CP editor loads valid content.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     */
    public function fillEmptyLinkFields(array &$data, array &$displayData, Blueprint $blueprint, string $locale, array &$warnings): void
    {
        $cfg = config('statamic-ai-assistant.generator_fallback_link', []);

        if (! ($cfg['enabled'] ?? true)) {
            return;
        }

        foreach ($blueprint->fields()->all() as $field) {
            $this->walk($field, $data, $displayData, $locale, $warnings);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     */
    private function walk(Field $field, array &$data, array &$displayData, string $locale, array &$warnings): void
    {
        $handle = $field->handle();
        $type = $field->type();

        if ($type === 'link') {
            $current = $data[$handle] ?? null;

            if ($current === null || $current === '') {
                $fallback = $this->resolveFallbackEntryLink($locale, $warnings);

                if ($fallback !== null) {
                    $data[$handle] = $fallback;
                    $displayData[$handle] = $fallback;
                }
            }

            return;
        }

        if (! in_array($type, ['group', 'replicator', 'components', 'grid'], true)
            || ! isset($data[$handle]) || ! is_array($data[$handle])) {
            return;
        }

        if (! isset($displayData[$handle]) || ! is_array($displayData[$handle])) {
            $displayData[$handle] = $data[$handle];
        }

        $fieldtype = $field->fieldtype();

        if ($type === 'group') {
            foreach ($fieldtype->fields()->all() as $child) {
                $this->walk($child, $data[$handle], $displayData[$handle], $locale, $warnings);
            }

            return;
        }

        if ($type === 'grid') {
            $gridFields = $fieldtype->fields();
            $rowFields = fn (array $row) => $gridFields;
        } else {
            $rowFields = fn (array $row) => ($row['type'] ?? null) ? $fieldtype->fields($row['type']) : null;
        }

        $this->walkRows($data[$handle], $displayData[$handle], $rowFields, $locale, $warnings);
    }

    /**
     * Walks the rows of a replicator or grid; $rowFields gives a row's fields
     * (null skips the row, e.g. a set without a type).
     *
     * @param  array<int|string, mixed>  $rows
     * @param  array<int|string, mixed>  $displayRows
     * @param  callable(array<string, mixed>): ?Fields  $rowFields
     */
    private function walkRows(array &$rows, array &$displayRows, callable $rowFields, string $locale, array &$warnings): void
    {
        foreach ($rows as $idx => &$row) {
            if (! is_array($row)) {
                continue;
            }

            if (! isset($displayRows[$idx]) || ! is_array($displayRows[$idx])) {
                $displayRows[$idx] = $row;
            }

            foreach ($rowFields($row)?->all() ?? [] as $child) {
                $this->walk($child, $row, $displayRows[$idx], $locale, $warnings);
            }
        }
        unset($row);
    }

    private function resolveFallbackEntryLink(string $locale, array &$warnings): ?string
    {
        if (array_key_exists($locale, $this->fallbackEntryLinkByLocale)) {
            return $this->fallbackEntryLinkByLocale[$locale];
        }

        $cfg = config('statamic-ai-assistant.generator_fallback_link', []);
        $collectionHandle = $cfg['collection'] ?? 'pages';
        $slug = $cfg['slug'] ?? 'home';

        $collection = Collection::findByHandle($collectionHandle);

        if (! $collection) {
            $warnings[] = __('Fallback link: collection ":collection" not found.', ['collection' => $collectionHandle]);
            $this->fallbackEntryLinkByLocale[$locale] = null;

            return null;
        }

        $entry = $collection->queryEntries()
            ->where('slug', $slug)
            ->where('site', $locale)
            ->first();

        if (! $entry) {
            $warnings[] = __('Fallback link: no entry with slug ":slug" in :collection for site :site.', [
                'slug' => $slug,
                'collection' => $collectionHandle,
                'site' => $locale,
            ]);
            $this->fallbackEntryLinkByLocale[$locale] = null;

            return null;
        }

        $resolved = 'entry::'.$entry->id();
        $this->fallbackEntryLinkByLocale[$locale] = $resolved;

        return $resolved;
    }
}
