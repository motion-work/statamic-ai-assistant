<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Turns a fetched HTML document into clean markdown.
 *
 * This exists because Jina Reader's default markdown mode runs Readability
 * "largest-content-block-wins" extraction, which silently discards the real
 * article on any page where a listing/teaser block is bigger than the content
 * (TYPO3 tx_news, WP "recent posts", Drupal views, …). We fetch raw HTML
 * instead (chrome already stripped by Jina via X-Remove-Selector) and do our
 * own, predictable extraction here.
 *
 * Scope is chosen per fetch, by intent:
 *   - SCOPE_MAIN: prefer the <main>/<article>/[role=main] subtree. Used when
 *     copying a page's content — we want the article, not the page's link lists.
 *     Falls back to the full body if no substantial landmark exists.
 *   - SCOPE_FULL: convert the whole (de-chromed) body. Used when the caller is
 *     discovering teasers/links on a listing page before fetching detail pages.
 */
class HtmlReadableExtractor
{
    public const SCOPE_MAIN = 'main';

    public const SCOPE_FULL = 'full';

    /**
     * Minimum trimmed text length for a landmark (<main> etc.) to be trusted as
     * the real content. Below this we treat the landmark as an empty layout
     * wrapper (common on SPA shells) and fall back to the full body.
     */
    private const MIN_LANDMARK_TEXT = 200;

    /**
     * Convert fetched HTML to markdown.
     *
     * @param  string  $scope  self::SCOPE_MAIN or self::SCOPE_FULL
     */
    public function extract(string $html, string $scope = self::SCOPE_MAIN): string
    {
        return $this->extractReadable($html, $scope)['markdown'];
    }

    /**
     * Convert fetched HTML to markdown AND surface the page's primary heading
     * (first <h1>) from the same parse. The headline lets callers pin the entry
     * title to the source page's real H1 — a copied page should keep its own
     * title rather than one the model paraphrases from the body.
     *
     * @param  string  $scope  self::SCOPE_MAIN or self::SCOPE_FULL
     * @return array{markdown: string, headline: ?string}
     */
    public function extractReadable(string $html, string $scope = self::SCOPE_MAIN): array
    {
        $html = trim($html);
        if ($html === '') {
            return ['markdown' => '', 'headline' => null];
        }

        $doc = $this->loadDocument($html);
        if ($doc === null) {
            return ['markdown' => '', 'headline' => null];
        }

        $this->stripNonContentNodes($doc);

        $node = $scope === self::SCOPE_MAIN
            ? ($this->findMainLandmark($doc) ?? $this->bodyNode($doc))
            : $this->bodyNode($doc);

        if ($node === null) {
            return ['markdown' => '', 'headline' => null];
        }

        $headline = $this->firstHeading($node) ?? $this->firstHeading($this->bodyNode($doc));

        $innerHtml = $this->nodeHtml($node);
        if (trim($innerHtml) === '') {
            return ['markdown' => '', 'headline' => $headline];
        }

        return [
            'markdown' => trim($this->converter()->convert($innerHtml)),
            'headline' => $headline,
        ];
    }

    /**
     * First non-empty <h1> text within (or below) the given node. Returns null
     * when the subtree has no usable level-1 heading.
     */
    private function firstHeading(?\DOMNode $node): ?string
    {
        if ($node === null) {
            return null;
        }

        $doc = $node->ownerDocument;
        if ($doc === null) {
            return null;
        }

        $xpath = new \DOMXPath($doc);
        // descendant-or-self so an <article> that *is* wrapped tightly around its
        // heading still matches, and a body scope finds the first h1 anywhere.
        $nodes = $xpath->query('.//h1', $node);
        if ($nodes === false) {
            return null;
        }

        foreach ($nodes as $h1) {
            $text = $this->normalizeHeading((string) $h1->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Collapse whitespace and trim a heading's text so a multi-line or
     * indentation-padded <h1> becomes a clean single-line title.
     */
    private function normalizeHeading(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function loadDocument(string $html): ?\DOMDocument
    {
        $doc = new \DOMDocument;

        $previous = libxml_use_internal_errors(true);
        // The XML encoding prolog forces libxml to read the bytes as UTF-8;
        // without it loadHTML assumes ISO-8859-1 and mangles non-ASCII text.
        $ok = $doc->loadHTML(
            '<?xml encoding="utf-8" ?>'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $doc : null;
    }

    /**
     * Remove nodes that carry no readable content. The converter also strips
     * these, but pulling them out first keeps text-length measurements honest
     * when we decide whether a landmark is substantial.
     */
    private function stripNonContentNodes(\DOMDocument $doc): void
    {
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//script | //style | //noscript | //template');
        if ($nodes === false) {
            return;
        }

        // Snapshot into an array — removing while iterating a live NodeList skips siblings.
        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * First substantial <main>, else <article>, else [role="main"]. Returns null
     * when none exists or the candidate is too thin to be the real content.
     */
    private function findMainLandmark(\DOMDocument $doc): ?\DOMNode
    {
        $xpath = new \DOMXPath($doc);

        foreach (['//main', '//article', '//*[@role="main"]'] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }

            foreach ($nodes as $node) {
                if (mb_strlen(trim((string) $node->textContent)) >= self::MIN_LANDMARK_TEXT) {
                    return $node;
                }
            }
        }

        return null;
    }

    private function bodyNode(\DOMDocument $doc): ?\DOMNode
    {
        return $doc->getElementsByTagName('body')->item(0)
            ?? $doc->documentElement;
    }

    private function nodeHtml(\DOMNode $node): string
    {
        $doc = $node->ownerDocument;
        if ($doc === null) {
            return '';
        }

        // For body/document nodes, serialize the children (skip the wrapper tag);
        // for a landmark element, serialize the element itself.
        if ($node instanceof \DOMElement && in_array(strtolower($node->nodeName), ['body', 'html'], true)) {
            $html = '';
            foreach ($node->childNodes as $child) {
                $html .= $doc->saveHTML($child);
            }

            return $html;
        }

        return (string) $doc->saveHTML($node);
    }

    private function converter(): HtmlConverter
    {
        return new HtmlConverter([
            // Drop tags with no markdown equivalent but keep their inner text.
            'strip_tags' => true,
            // Belt-and-suspenders: remove any non-content nodes the DOM pass missed.
            'remove_nodes' => 'script style noscript template head title meta link',
            'hard_break' => true,
            'strip_placeholder_links' => true,
            // ATX headings (# Title) are more consistent than setext (Title\n===)
            // — which only covers h1/h2 — and read more cleanly for the LLM.
            'header_style' => 'atx',
        ]);
    }
}
