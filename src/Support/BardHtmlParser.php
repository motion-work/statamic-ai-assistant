<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Fields\Field;

/**
 * Converts the loose HTML a model writes into a valid Bard (ProseMirror)
 * document: stray text is wrapped in paragraphs, list items get paragraphs,
 * marks come from the tags. Statamic's own converter (tiptap) keeps such text
 * as bare top-level or list-item text nodes, which Bard cannot load.
 */
final class BardHtmlParser
{
    /** @var array<string, string> HTML tag → ProseMirror mark type */
    private const TAG_TO_MARK = [
        'b' => 'bold',
        'strong' => 'bold',
        'i' => 'italic',
        'em' => 'italic',
        'u' => 'underline',
        's' => 'strike',
        'del' => 'strike',
        'code' => 'code',
        'sup' => 'superscript',
        'sub' => 'subscript',
        'small' => 'small',
    ];

    /**
     * @param  array<array{type: string, attrs?: array<string, mixed>}>  $currentMarks
     * @param  array<mixed>  $result
     */
    public function walkDomNodes(\DOMNode $parent, array $currentMarks, array &$result): void
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $child->textContent;

                if ($text === '') {
                    continue;
                }

                $node = ['type' => 'text', 'text' => $text];

                if (! empty($currentMarks)) {
                    $node['marks'] = $currentMarks;
                }

                $result[] = $node;
            } elseif ($child instanceof \DOMElement) {
                $tagName = strtolower($child->tagName);

                if ($tagName === 'br') {
                    $result[] = ['type' => 'hardBreak'];

                    continue;
                }

                $mark = $this->domElementToMark($child);
                $newMarks = $mark ? array_merge($currentMarks, [$mark]) : $currentMarks;
                $this->walkDomNodes($child, $newMarks, $result);
            }
        }
    }

    /**
     * @return array{type: string, attrs?: array<string, mixed>}|null
     */
    private function domElementToMark(\DOMElement $element): ?array
    {
        $tagName = strtolower($element->tagName);

        if (isset(self::TAG_TO_MARK[$tagName])) {
            return ['type' => self::TAG_TO_MARK[$tagName]];
        }

        if ($tagName === 'a') {
            $mark = ['type' => 'link', 'attrs' => ['href' => $element->getAttribute('href')]];

            if ($element->hasAttribute('target')) {
                $mark['attrs']['target'] = $element->getAttribute('target');
            }

            if ($element->hasAttribute('rel')) {
                $mark['attrs']['rel'] = $element->getAttribute('rel');
            }

            return $mark;
        }

        return null;
    }

    /**
     * Convert a full HTML string (with block elements like p, h1-h6, ul, ol, blockquote, hr)
     * into a complete ProseMirror node array suitable for a Bard field.
     *
     * Unlike htmlToBardContent() which handles inline content only, this method
     * handles the full document structure.
     *
     * @return array<mixed>
     */
    public function htmlToFullBardDocument(string $html): array
    {
        $html = trim($html);

        if ($html === '') {
            return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]];
        }

        $doc = new \DOMDocument;
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>';
        @$doc->loadHTML($wrapped, LIBXML_NOERROR);

        $body = $doc->getElementsByTagName('body')->item(0);

        if (! $body) {
            return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => html_entity_decode($html, ENT_QUOTES, 'UTF-8')]]]];
        }

        $nodes = [];

        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = trim($child->textContent);

                if ($text !== '') {
                    $nodes[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
                }

                continue;
            }

            if (! ($child instanceof \DOMElement)) {
                continue;
            }

            $node = $this->domBlockElementToNode($child);

            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return $nodes ?: [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => html_entity_decode($html, ENT_QUOTES, 'UTF-8')]]]];
    }

    private function domBlockElementToNode(\DOMElement $element): ?array
    {
        $tag = strtolower($element->tagName);

        if ($tag === 'p') {
            $content = $this->extractInlineContent($element);

            return ['type' => 'paragraph', 'content' => $content ?: [['type' => 'text', 'text' => '']]];
        }

        if (preg_match('/^h([1-6])$/', $tag, $m)) {
            $content = $this->extractInlineContent($element);

            return [
                'type' => 'heading',
                'attrs' => ['level' => (int) $m[1]],
                'content' => $content ?: [['type' => 'text', 'text' => '']],
            ];
        }

        if ($tag === 'ul') {
            return ['type' => 'bulletList', 'content' => $this->extractListItems($element)];
        }

        if ($tag === 'ol') {
            return ['type' => 'orderedList', 'content' => $this->extractListItems($element)];
        }

        if ($tag === 'blockquote') {
            $children = [];

            foreach ($element->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $node = $this->domBlockElementToNode($child);

                    if ($node !== null) {
                        $children[] = $node;
                    }
                } elseif ($child instanceof \DOMText && trim($child->textContent) !== '') {
                    $children[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $child->textContent]]];
                }
            }

            return ['type' => 'blockquote', 'content' => $children ?: [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]]];
        }

        if ($tag === 'hr') {
            return ['type' => 'horizontalRule'];
        }

        // Fallback: treat unknown block elements as paragraphs with inline content
        $content = $this->extractInlineContent($element);

        if (! empty($content)) {
            return ['type' => 'paragraph', 'content' => $content];
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private function extractInlineContent(\DOMElement $element): array
    {
        $nodes = [];
        $this->walkDomNodes($element, [], $nodes);

        return $nodes;
    }

    /**
     * @return array<mixed>
     */
    private function extractListItems(\DOMElement $listElement): array
    {
        $items = [];

        foreach ($listElement->childNodes as $child) {
            if (! ($child instanceof \DOMElement) || strtolower($child->tagName) !== 'li') {
                continue;
            }

            $liContent = [];
            $hasBlocks = false;

            foreach ($child->childNodes as $liChild) {
                if (! ($liChild instanceof \DOMElement) || ! in_array(strtolower($liChild->tagName), ['p', 'ul', 'ol', 'blockquote'])) {
                    continue;
                }

                $hasBlocks = true;
                $node = $this->domBlockElementToNode($liChild);

                if ($node !== null) {
                    $liContent[] = $node;
                }
            }

            if (! $hasBlocks) {
                // Simple list item — inline content wrapped in a paragraph
                $inline = $this->extractInlineContent($child);
                $liContent = [['type' => 'paragraph', 'content' => $inline ?: [['type' => 'text', 'text' => '']]]];
            }

            $items[] = ['type' => 'listItem', 'content' => $liContent];
        }

        return $items ?: [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '']]]]]];
    }
}
