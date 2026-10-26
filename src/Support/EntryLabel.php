<?php

namespace BoldWeb\StatamicAiAssistant\Support;

use Statamic\Contracts\Entries\Entry;

/**
 * Safe display label for CP / API responses.
 * Statamic Entry has no guaranteed title() method; use value() with fallbacks.
 */
final class EntryLabel
{
    public static function for(Entry $entry): string
    {
        $title = $entry->value('title');
        if (is_string($title) && $title !== '') {
            return $title;
        }

        $slug = $entry->value('slug');
        if (is_string($slug) && $slug !== '') {
            return $slug;
        }

        return (string) $entry->id();
    }
}
