<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Support\LlmDate;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Saves what the agent or the connector produced: a new draft entry, or an
 * update laid over an entry's data (objects merge, lists are replaced whole).
 */
final class EntryWriter
{
    /**
     * Save generated data as a new entry (draft).
     */
    public function create(
        string $collectionHandle,
        string $blueprintHandle,
        string $locale,
        array $data,
    ): StatamicEntry {
        $collection = Collection::findByHandle($collectionHandle);

        $entry = Entry::make()
            ->collection($collectionHandle)
            ->blueprint($blueprintHandle)
            ->locale($locale);

        $title = $data['title'] ?? null;
        $slug = is_string($title) && $title !== ''
            ? Str::slug($title)
            : 'untitled-'.now()->timestamp;

        $entry->slug($slug);
        $entry->data($data);

        // Dated collections use a separate entry date (ordering, CP sidebar, filenames).
        // Blueprint `date` in $data alone does not always set that attribute — the CP can
        // still show "today" unless we assign it explicitly.
        if ($collection && $collection->dated()) {
            $entryDate = LlmDate::parse($data['date'] ?? null);
            if ($entryDate !== null) {
                $entry->date($entryDate);
            }
        }

        $entry->published(false);
        $entry->save();

        return $entry;
    }

    /**
     * Persist a partial update: load the entry, merge only the fields produced
     * by `generateUpdateForEntry`, and save. Slug, date, and published flag are
     * preserved unless the LLM explicitly returned a new title (slug stays
     * unchanged on purpose — re-slugging breaks URLs).
     */
    public function update(string $entryId, array $data): StatamicEntry
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            throw new \RuntimeException(__('Entry not found.'));
        }

        if ($data === []) {
            return $entry;
        }

        $existing = is_array($entry->data()->all()) ? $entry->data()->all() : [];
        $entry->data(self::merge($existing, $data));
        $entry->save();

        return $entry;
    }

    /**
     * Deep-merge $b into $a where:
     *  - Associative array nodes recurse (so a group like `image_or_video`
     *    preserves siblings the LLM did not return).
     *  - List nodes (numerically-indexed) get REPLACED wholesale (so a
     *    replicator array like `page_builder` doesn't accumulate stale blocks
     *    from the existing entry when the LLM returns a shorter list).
     *  - Scalars and type mismatches are replaced.
     *
     * @param  array<string|int, mixed>  $a
     * @param  array<string|int, mixed>  $b
     * @return array<string|int, mixed>
     */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $val) {
            if (is_array($val) && isset($a[$key]) && is_array($a[$key]) && ! array_is_list($val) && ! array_is_list($a[$key])) {
                $a[$key] = self::merge($a[$key], $val);
            } else {
                $a[$key] = $val;
            }
        }

        return $a;
    }
}
