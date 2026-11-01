<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Fields\Field;

/**
 * Guesses what an untyped stored value is (an entry reference, a file path,
 * a URL, Bard nodes, nested sets) where no field definition says so, so the
 * translator only sends real text.
 */
final class TranslationValueShapes
{
    public function looksLikeEntryReference(string $value): bool
    {
        return str_starts_with($value, 'entry::')
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    public function looksLikeFilePath(string $value): bool
    {
        return str_contains($value, '/')
            && preg_match('/\.(pdf|jpg|jpeg|png|svg|gif|webp|mp4|mp3|doc|docx|xls|xlsx|ppt|pptx|gltf|glb|bin|zip|tar|gz|csv)$/i', $value);
    }

    public function looksLikeUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, 'mailto:');
    }

    /**
     * @param  array<mixed>  $value
     */
    public function isBardContent(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        $first = reset($value);

        return is_array($first) && isset($first['type']) && in_array($first['type'], [
            'paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote',
            'codeBlock', 'hardBreak', 'horizontalRule', 'image', 'table', 'set',
        ]);
    }

    /**
     * @param  array<mixed>  $value
     */
    public function isUuidArray(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $value
     */
    public function isNestedSets(array $value): bool
    {
        if (empty($value)) {
            return false;
        }

        $first = reset($value);

        return is_array($first) && (isset($first['type']) || isset($first['id']));
    }
}
