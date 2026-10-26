<?php

namespace BoldWeb\StatamicAiAssistant\Support;

/**
 * Pulls a single top-level JSON object out of LLM output that may include
 * prefixes/suffixes (reasoning text, markdown, trailing commentary).
 */
final class JsonObjectExtractor
{
    /**
     * Extract the first complete `{ ... }` by brace depth, honoring string literals.
     */
    public static function firstObject(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $start = strpos($text, '{');

        if ($start === false) {
            return null;
        }

        $len = strlen($text);
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $c = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($c === '\\') {
                    $escape = true;

                    continue;
                }
                if ($c === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($c === '"') {
                $inString = true;

                continue;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
