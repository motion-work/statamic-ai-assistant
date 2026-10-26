<?php

namespace BoldWeb\StatamicAiAssistant\Support;

/**
 * Removes leading/trailing whitespace and empty boundary paragraphs from AI / DeepL HTML output.
 * Matches empty <p> including ProseMirror-style <br class="..."> inside paragraphs.
 */
class TrimAiOutput
{
    private const EMPTY_P_INNER = '(?:\s|&nbsp;|<br[^>]*>)*';

    public static function normalize(string $content): string
    {
        $content = trim($content);

        if ($content === '' || ! str_contains($content, '<')) {
            return $content;
        }

        // Unwrap legacy aiText wrapper divs from Statamic 5 Bard content.
        $content = preg_replace(
            '/<div\s+data-statamic-ai-text-legacy(?:="[^"]*")?[^>]*>([\s\S]*?)<\/div>/iu',
            '$1',
            $content,
        ) ?? $content;

        $leading = '/^(<p[^>]*>'.self::EMPTY_P_INNER.'<\/p>\s*)+/iu';
        $trailing = '/(\s*<p[^>]*>'.self::EMPTY_P_INNER.'<\/p>)+$/iu';

        do {
            $prev = $content;
            $content = preg_replace($leading, '', $content) ?? $content;
            $content = preg_replace($trailing, '', $content) ?? $content;
            $content = trim($content);
        } while ($content !== $prev);

        return $content;
    }
}
