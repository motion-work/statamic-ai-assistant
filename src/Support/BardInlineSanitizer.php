<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Fields\Field;

/**
 * The inline layer of Bard sanitizing: text nodes keep only the marks the
 * field's buttons enable; hard breaks and images pass when allowed.
 */
final class BardInlineSanitizer
{
    /**
     * @param  array<int, mixed>  $content
     * @param  array<int, string>  $buttons
     * @return array<int, mixed>
     */
    public function sanitizeBardInlineContent(array $content, array $buttons): array
    {
        $out = [];

        foreach ($content as $piece) {
            if (! is_array($piece)) {
                continue;
            }

            $t = $piece['type'] ?? '';

            if ($t === 'text') {
                $marks = $piece['marks'] ?? null;

                if (is_array($marks) && $marks !== []) {
                    $filtered = $this->filterBardMarksForButtons($marks, $buttons);

                    if ($filtered !== []) {
                        $piece['marks'] = $filtered;
                    } else {
                        unset($piece['marks']);
                    }
                }

                $out[] = $piece;
            } elseif ($t === 'hardBreak') {
                $out[] = $piece;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $marks
     * @param  array<int, string>  $buttons
     * @return array<int, mixed>
     */
    private function filterBardMarksForButtons(array $marks, array $buttons): array
    {
        $map = [
            'bold' => 'bold',
            'italic' => 'italic',
            'underline' => 'underline',
            'strike' => 'strikethrough',
            'code' => 'code',
            'link' => 'anchor',
            'superscript' => 'superscript',
            'subscript' => 'subscript',
        ];

        $filtered = [];

        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }

            $mt = $mark['type'] ?? '';

            if ($mt === 'small' && in_array('small', $buttons, true)) {
                $filtered[] = $mark;

                continue;
            }

            if (! isset($map[$mt])) {
                continue;
            }

            if (in_array($map[$mt], $buttons, true)) {
                $filtered[] = $mark;
            }
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function bardNodePlainText(array $node): string
    {
        $type = $node['type'] ?? '';

        if ($type === 'text') {
            return (string) ($node['text'] ?? '');
        }

        $text = '';

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $text .= $this->bardNodePlainText($child);
            }
        }

        return $text;
    }
}
