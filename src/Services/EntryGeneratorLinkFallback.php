<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Statamic\Facades\Collection;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

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

        if ($type === 'section') {
            return;
        }

        if ($type === 'link') {
            $current = $data[$handle] ?? null;

            $empty = $current === null || $current === '';

            if ($empty) {
                $fallback = $this->resolveFallbackEntryLink($locale, $warnings);

                if ($fallback !== null) {
                    $data[$handle] = $fallback;
                    $displayData[$handle] = $fallback;
                }
            }

            return;
        }

        if ($type === 'group') {
            if (! isset($data[$handle]) || ! is_array($data[$handle])) {
                return;
            }
            if (! isset($displayData[$handle]) || ! is_array($displayData[$handle])) {
                $displayData[$handle] = $data[$handle];
            }

            foreach ($field->fieldtype()->fields()->all() as $child) {
                $this->walk($child, $data[$handle], $displayData[$handle], $locale, $warnings);
            }

            return;
        }

        if (in_array($type, ['replicator', 'components'], true)) {
            if (! isset($data[$handle]) || ! is_array($data[$handle])) {
                return;
            }
            if (! isset($displayData[$handle]) || ! is_array($displayData[$handle])) {
                $displayData[$handle] = $data[$handle];
            }

            $fieldtype = $field->fieldtype();

            foreach ($data[$handle] as $idx => &$row) {
                if (! is_array($row)) {
                    continue;
                }

                if (! isset($displayData[$handle][$idx]) || ! is_array($displayData[$handle][$idx])) {
                    $displayData[$handle][$idx] = $row;
                }

                $setType = $row['type'] ?? null;

                if (! $setType) {
                    continue;
                }

                try {
                    $setFields = $fieldtype->fields($setType);
                } catch (\Exception) {
                    continue;
                }

                foreach ($setFields->all() as $child) {
                    $this->walk($child, $row, $displayData[$handle][$idx], $locale, $warnings);
                }
            }
            unset($row);

            return;
        }

        if ($type === 'grid') {
            if (! isset($data[$handle]) || ! is_array($data[$handle])) {
                return;
            }
            if (! isset($displayData[$handle]) || ! is_array($displayData[$handle])) {
                $displayData[$handle] = $data[$handle];
            }

            $gridFields = $field->fieldtype()->fields();

            foreach ($data[$handle] as $idx => &$row) {
                if (! is_array($row)) {
                    continue;
                }

                if (! isset($displayData[$handle][$idx]) || ! is_array($displayData[$handle][$idx])) {
                    $displayData[$handle][$idx] = $row;
                }

                foreach ($gridFields->all() as $child) {
                    $this->walk($child, $row, $displayData[$handle][$idx], $locale, $warnings);
                }
            }
            unset($row);
        }
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
