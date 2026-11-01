<?php

namespace MotionWork\StatamicAiAssistant\Support;

/**
 * Whether an entry's collection is enabled for a site. A localization in a
 * site the collection does not have breaks Statamic's save for structured
 * collections (there is no tree for that site) and is invisible in the CP.
 */
final class CollectionSites
{
    public static function includes(mixed $entry, string $siteHandle): bool
    {
        $collection = $entry->collection();

        return $collection !== null && $collection->sites()->contains($siteHandle);
    }

    public static function refusal(mixed $entry, string $siteHandle): string
    {
        return (string) __('The collection ":collection" is not set up for the site :site. Add the site to the collection first.', [
            'collection' => $entry->collection()?->title() ?? $entry->collectionHandle(),
            'site' => $siteHandle,
        ]);
    }
}
