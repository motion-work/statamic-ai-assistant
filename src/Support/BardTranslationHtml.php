<?php

namespace MotionWork\StatamicAiAssistant\Support;

/**
 * Bard nodes as one HTML string for translation, and back, so DeepL sees a
 * whole paragraph (marks included) instead of fragments.
 */
final class BardTranslationHtml
{
    /** @var array<string, string> ProseMirror mark type → HTML tag */
    private const MARK_TO_TAG = [
        'bold' => 'b',
        'italic' => 'i',
        'underline' => 'u',
        'strike' => 's',
        'code' => 'code',
        'superscript' => 'sup',
        'subscript' => 'sub',
        'small' => 'small',
    ];

    /**
     * @param  array<mixed>  $content
     */
    public function shouldMergeBardContent(array $content): bool
    {
        $hasMarkedText = false;

        foreach ($content as $node) {
            if (! is_array($node)) {
                return false;
            }

            $type = $node['type'] ?? null;

            if ($type === 'text' || $type === 'hardBreak') {
                if ($type === 'text' && ! empty($node['marks'])) {
                    $hasMarkedText = true;
                }

                continue;
            }

            return false;
        }

        return $hasMarkedText;
    }

    /**
     * @param  array<mixed>  $content
     */
    public function bardContentToHtml(array $content): string
    {
        $html = '';

        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? null;

            if ($type === 'hardBreak') {
                $html .= '<br>';

                continue;
            }

            if ($type !== 'text' || ! isset($node['text'])) {
                continue;
            }

            $text = htmlspecialchars($node['text'], ENT_QUOTES, 'UTF-8');
            $marks = $node['marks'] ?? [];

            foreach ($marks as $mark) {
                $tag = self::MARK_TO_TAG[$mark['type']] ?? null;

                if ($tag) {
                    $html .= "<{$tag}>";
                } elseif ($mark['type'] === 'link') {
                    $href = htmlspecialchars($mark['attrs']['href'] ?? '', ENT_QUOTES, 'UTF-8');
                    $attrs = " href=\"{$href}\"";

                    if (! empty($mark['attrs']['target'])) {
                        $attrs .= ' target="'.htmlspecialchars($mark['attrs']['target'], ENT_QUOTES, 'UTF-8').'"';
                    }

                    if (! empty($mark['attrs']['rel'])) {
                        $attrs .= ' rel="'.htmlspecialchars($mark['attrs']['rel'], ENT_QUOTES, 'UTF-8').'"';
                    }

                    $html .= "<a{$attrs}>";
                }
            }

            $html .= $text;

            foreach (array_reverse($marks) as $mark) {
                $tag = self::MARK_TO_TAG[$mark['type']] ?? null;

                if ($tag) {
                    $html .= "</{$tag}>";
                } elseif ($mark['type'] === 'link') {
                    $html .= '</a>';
                }
            }
        }

        return $html;
    }

    /**
     * @return array<mixed>
     */
    public function htmlToBardContent(string $html): array
    {
        if (trim($html) === '') {
            return [['type' => 'text', 'text' => '']];
        }

        $doc = new \DOMDocument;
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>';
        @$doc->loadHTML($wrapped, LIBXML_NOERROR);

        $body = $doc->getElementsByTagName('body')->item(0);

        if (! $body) {
            return [['type' => 'text', 'text' => html_entity_decode($html, ENT_QUOTES, 'UTF-8')]];
        }

        $nodes = [];
        (new BardHtmlParser)->walkDomNodes($body, [], $nodes);

        return $nodes ?: [['type' => 'text', 'text' => html_entity_decode($html, ENT_QUOTES, 'UTF-8')]];
    }
}
