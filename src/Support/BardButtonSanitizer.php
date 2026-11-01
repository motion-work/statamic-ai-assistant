<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Fields\Field;

/**
 * Trims Bard (ProseMirror) nodes and marks to what a field's toolbar buttons
 * enable; otherwise the Control Panel shows "Invalid content, :type
 * button/extension is not enabled". Disabled blocks degrade to the closest
 * allowed one (a heading to a paragraph, a list to paragraphs) instead of
 * losing their text.
 */
final class BardButtonSanitizer
{
    /**
     * Strip ProseMirror nodes and marks that this Bard field does not enable in the CP.
     * Otherwise Statamic shows "Invalid content, :type button/extension is not enabled".
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, string>|null  $buttons  Field config `buttons`; null/empty uses Statamic Bard defaults.
     * @return array<int, array<string, mixed>>
     */
    public function sanitizeBardNodesForFieldButtons(array $nodes, ?array $buttons): array
    {
        $buttons = $this->normalizeBardButtonsForSanitize($buttons);
        $out = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['type'])) {
                continue;
            }

            foreach ($this->sanitizeBardNodeForFieldButtons($node, $buttons) as $n) {
                $out[] = $n;
            }
        }

        return $out ?: [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeBardButtonsForSanitize(?array $buttons): array
    {
        if ($buttons === null || $buttons === []) {
            return array_map('strtolower', [
                'h2', 'h3', 'bold', 'italic', 'unorderedlist', 'orderedlist',
                'removeformat', 'quote', 'anchor', 'image', 'table',
            ]);
        }

        return array_map(fn ($b) => strtolower((string) $b), $buttons);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardNodeForFieldButtons(array $node, array $buttons): array
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'heading' => $this->sanitizeBardHeadingNode($node, $buttons),
            'paragraph' => [$this->sanitizeBardParagraphNode($node, $buttons)],
            'bulletList' => $this->sanitizeBardBulletListNode($node, $buttons),
            'orderedList' => $this->sanitizeBardOrderedListNode($node, $buttons),
            'blockquote' => $this->sanitizeBardBlockquoteNode($node, $buttons),
            'horizontalRule' => $this->sanitizeBardHorizontalRuleNode($buttons),
            'codeBlock' => $this->sanitizeBardCodeBlockNode($node, $buttons),
            'table' => $this->sanitizeBardTableNode($node, $buttons),
            'image' => $this->sanitizeBardImageNode($node, $buttons),
            default => [$node],
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardHeadingNode(array $node, array $buttons): array
    {
        $level = (int) ($node['attrs']['level'] ?? 2);
        $btn = 'h'.$level;

        if (in_array($btn, $buttons, true)) {
            $node['content'] = (new BardInlineSanitizer)->sanitizeBardInlineContent($node['content'] ?? [], $buttons);

            return [$node];
        }

        return [[
            'type' => 'paragraph',
            'content' => (new BardInlineSanitizer)->sanitizeBardInlineContent($node['content'] ?? [], $buttons) ?: [['type' => 'text', 'text' => '']],
        ]];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $buttons
     * @return array<string, mixed>
     */
    private function sanitizeBardParagraphNode(array $node, array $buttons): array
    {
        $node['content'] = (new BardInlineSanitizer)->sanitizeBardInlineContent($node['content'] ?? [], $buttons);

        if (isset($node['attrs']['textAlign'])) {
            $align = $node['attrs']['textAlign'];
            $alignBtn = match ($align) {
                'left' => 'alignleft',
                'center' => 'aligncenter',
                'right' => 'alignright',
                'justify' => 'alignjustify',
                default => null,
            };

            if ($alignBtn === null || ! in_array($alignBtn, $buttons, true)) {
                unset($node['attrs']['textAlign']);

                if (empty($node['attrs'])) {
                    unset($node['attrs']);
                }
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardBulletListNode(array $node, array $buttons): array
    {
        if (in_array('unorderedlist', $buttons, true)) {
            $node['content'] = $this->sanitizeBardListItems($node['content'] ?? [], $buttons);

            return [$node];
        }

        return $this->flattenBardListItemsToTopLevelBlocks($node['content'] ?? [], $buttons);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardOrderedListNode(array $node, array $buttons): array
    {
        if (in_array('orderedlist', $buttons, true)) {
            $node['content'] = $this->sanitizeBardListItems($node['content'] ?? [], $buttons);

            return [$node];
        }

        return $this->flattenBardListItemsToTopLevelBlocks($node['content'] ?? [], $buttons);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardBlockquoteNode(array $node, array $buttons): array
    {
        if (in_array('quote', $buttons, true)) {
            $inner = [];

            foreach ($node['content'] ?? [] as $block) {
                if (! is_array($block)) {
                    continue;
                }

                foreach ($this->sanitizeBardNodeForFieldButtons($block, $buttons) as $n) {
                    $inner[] = $n;
                }
            }

            return [[
                'type' => 'blockquote',
                'content' => $inner ?: [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]],
            ]];
        }

        $flattened = [];

        foreach ($node['content'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            foreach ($this->sanitizeBardNodeForFieldButtons($block, $buttons) as $n) {
                $flattened[] = $n;
            }
        }

        return $flattened ?: [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]];
    }

    /**
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardHorizontalRuleNode(array $buttons): array
    {
        if (in_array('horizontalrule', $buttons, true)) {
            return [['type' => 'horizontalRule']];
        }

        return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardCodeBlockNode(array $node, array $buttons): array
    {
        if (in_array('codeblock', $buttons, true)) {
            return [$node];
        }

        $text = '';

        foreach ($node['content'] ?? [] as $piece) {
            if (is_array($piece) && ($piece['type'] ?? '') === 'text') {
                $text .= (string) ($piece['text'] ?? '');
            }
        }

        return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardTableNode(array $node, array $buttons): array
    {
        if (in_array('table', $buttons, true)) {
            return [$node];
        }

        $text = (new BardInlineSanitizer)->bardNodePlainText($node);

        return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBardImageNode(array $node, array $buttons): array
    {
        if (in_array('image', $buttons, true)) {
            return [$node];
        }

        return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]];
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, string>  $buttons
     * @return array<int, mixed>
     */
    private function sanitizeBardListItems(array $items, array $buttons): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'listItem') {
                continue;
            }

            $inner = [];

            foreach ($item['content'] ?? [] as $block) {
                if (! is_array($block)) {
                    continue;
                }

                foreach ($this->sanitizeBardNodeForFieldButtons($block, $buttons) as $n) {
                    $inner[] = $n;
                }
            }

            $out[] = [
                'type' => 'listItem',
                'content' => $inner ?: [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]],
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<int, string>  $buttons
     * @return array<int, array<string, mixed>>
     */
    private function flattenBardListItemsToTopLevelBlocks(array $items, array $buttons): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item) || ($item['type'] ?? '') !== 'listItem') {
                continue;
            }

            foreach ($item['content'] ?? [] as $block) {
                if (! is_array($block)) {
                    continue;
                }

                foreach ($this->sanitizeBardNodeForFieldButtons($block, $buttons) as $n) {
                    $out[] = $n;
                }
            }
        }

        return $out ?: [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]];
    }
}
