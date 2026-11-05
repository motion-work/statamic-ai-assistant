<?php

namespace MotionWork\StatamicAiAssistant\Semantic;

use MotionWork\StatamicAiAssistant\Services\EntryContentSearch;
use Statamic\Entries\Entry;

/**
 * What one entry localization is embedded as: title, slug and its flattened
 * field text (the same flattening as search_entry_content), truncated. The
 * hash includes model and dimensions, so changing either re-embeds.
 */
final class EntryEmbeddingText
{
    public const MAX_CHARS = 8000;

    public static function for(Entry $entry): string
    {
        $text = trim(implode("\n", [
            (string) ($entry->value('title') ?? ''),
            (string) ($entry->slug() ?? ''),
            (new EntryContentSearch)->flattenEntryText($entry),
        ]));

        return mb_substr($text, 0, self::MAX_CHARS);
    }

    public static function hash(string $text, string $model, int $dimensions): string
    {
        return sha1($model.'|'.$dimensions.'|'.$text);
    }
}
