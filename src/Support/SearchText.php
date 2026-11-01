<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Illuminate\Support\Str;
use Statamic\Facades\Entry;

/**
 * Normalises queries and titles so the agent's entry search tolerates
 * punctuation, connectives and accents.
 */
final class SearchText
{
    /**
     * Break a search query into normalised, comparable tokens.
     *
     * Lower-cased and split on any non-alphanumeric run (so punctuation and
     * symbols never have to match literally). Diacritics are preserved so the
     * tokens still match accented titles via the DB `like`. Connective words and
     * symbols ("&", "+", "and", "und", "et", …) are dropped, which is what makes
     * "Salt & Stone", "Salt and Stone" and "Salt Stone" all resolve to the same entry.
     *
     * @return array<int, string>
     */
    public static function tokens(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? '';
        $parts = preg_split('/\s+/', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $connectors = ['and', 'und', 'et', 'y', 'e', 'or', 'oder', 'ou', 'plus'];

        $tokens = [];
        foreach ($parts as $part) {
            if (in_array($part, $connectors, true)) {
                continue;
            }
            // Drop single-character noise, but keep short numbers (e.g. years).
            if (mb_strlen($part) < 2 && ! ctype_digit($part)) {
                continue;
            }
            $tokens[$part] = $part;
        }

        return array_values($tokens);
    }

    /**
     * Fold a value for tolerant comparison: transliterate to ASCII (é→e, ä→a),
     * lower-case, and collapse any non-alphanumeric run to a single space.
     */
    public static function fold(string $value): string
    {
        $value = mb_strtolower(Str::ascii(trim($value)));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
