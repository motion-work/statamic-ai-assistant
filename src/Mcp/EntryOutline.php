<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;
use Statamic\Fields\Blueprint;

/**
 * A short outline of a composed entry for the preview: each field with its
 * value or, for block fields, the blocks in order with their headlines.
 */
final class EntryOutline
{
    /**
     * Blocks and headlines of the composed entry, for the user to review.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public function outline(Blueprint $blueprint, array $data): array
    {
        $outline = [];
        foreach ($blueprint->fields()->all() as $field) {
            $value = $data[$field->handle()] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (in_array($field->type(), ['replicator', 'components'], true) && is_array($value)) {
                $blocks = [];
                foreach ($value as $set) {
                    if (is_array($set) && isset($set['type'])) {
                        $headline = $this->headline($set);
                        $blocks[] = $headline === '' ? $set['type'] : $set['type'].': '.$headline;
                    }
                }
                $outline[] = ['field' => $field->display(), 'blocks' => $blocks];
            } elseif (is_string($value)) {
                $outline[] = ['field' => $field->display(), 'value' => Str::limit(strip_tags($value), 120)];
            } elseif ($field->type() === 'bard' && is_array($value)) {
                $text = [];
                array_walk_recursive($value, function ($node, $key) use (&$text) {
                    if ($key === 'text' && is_string($node)) {
                        $text[] = $node;
                    }
                });
                $outline[] = ['field' => $field->display(), 'value' => Str::limit(implode(' ', $text), 120)];
            }
        }

        return $outline;
    }

    /**
     * @param  array<string, mixed>  $set
     */
    private function headline(array $set): string
    {
        foreach (['title', 'label', 'subtitle'] as $key) {
            if (is_string($set[$key] ?? null) && trim($set[$key]) !== '') {
                return Str::limit(trim($set[$key]), 90);
            }
        }

        return '';
    }
}
