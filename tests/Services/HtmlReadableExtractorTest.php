<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\HtmlReadableExtractor;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

class HtmlReadableExtractorTest extends TestCase
{
    private HtmlReadableExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new HtmlReadableExtractor;
    }

    /**
     * The bug we fix: a short article living next to a large listing block.
     * Jina's markdown mode kept the listing and dropped the article — our
     * extraction must keep the article.
     */
    public function test_main_scope_keeps_real_article_even_with_large_listing(): void
    {
        $html = <<<'HTML'
        <html><body>
            <nav>site nav (already stripped upstream)</nav>
            <main>
                <article>
                    <h1>Oldtimertreff</h1>
                    <p>Von April bis September trifft man sich im Hof zum Fachsimpeln.</p>
                </article>
                <section class="teasers">
                    <a href="/a">Event A teaser with quite a lot of text to outweigh the article body so a largest-block heuristic would wrongly pick this listing instead of the real content above.</a>
                    <a href="/b">Event B teaser also long enough to dominate the page weighting.</a>
                </section>
            </main>
        </body></html>
        HTML;

        $md = $this->extractor->extract($html, HtmlReadableExtractor::SCOPE_MAIN);

        $this->assertStringContainsString('Von April bis September', $md);
        $this->assertStringContainsString('Oldtimertreff', $md);
    }

    public function test_main_scope_falls_back_to_body_when_no_landmark(): void
    {
        $html = '<html><body><div class="content"><h2>Plain page</h2><p>Body level content without any landmark element.</p></div></body></html>';

        $md = $this->extractor->extract($html, HtmlReadableExtractor::SCOPE_MAIN);

        $this->assertStringContainsString('Body level content', $md);
    }

    public function test_main_scope_ignores_empty_landmark_wrapper_and_uses_body(): void
    {
        // <main> is an empty layout shell (SPA-style); the real content sits
        // outside it. The landmark is too thin to trust, so we fall back to body.
        $html = '<html><body><main></main><div>The actual readable content of this page is here and is well over the minimum length threshold required for the body fallback to surface it to the model.</div></body></html>';

        $md = $this->extractor->extract($html, HtmlReadableExtractor::SCOPE_MAIN);

        $this->assertStringContainsString('actual readable content', $md);
    }

    public function test_full_scope_returns_content_outside_main(): void
    {
        $html = '<html><body><main><p>Main content.</p></main><section class="more-events"><a href="/x">Teaser link to discover</a></section></body></html>';

        $full = $this->extractor->extract($html, HtmlReadableExtractor::SCOPE_FULL);

        $this->assertStringContainsString('Main content', $full);
        $this->assertStringContainsString('Teaser link to discover', $full);
    }

    public function test_scripts_and_styles_are_stripped(): void
    {
        $html = '<html><body><main><p>Visible text.</p><script>var x = function(){ alert(1); };</script><style>.foo{display:none}</style></main></body></html>';

        $md = $this->extractor->extract($html, HtmlReadableExtractor::SCOPE_MAIN);

        $this->assertStringContainsString('Visible text', $md);
        $this->assertStringNotContainsString('alert(1)', $md);
        $this->assertStringNotContainsString('display:none', $md);
    }

    public function test_structure_and_images_are_preserved(): void
    {
        $html = '<html><body><main><h1>Title</h1><ul><li>one</li><li>two</li></ul><img src="https://example.com/a.png" alt="pic"></main></body></html>';

        $md = $this->extractor->extract($html, HtmlReadableExtractor::SCOPE_MAIN);

        $this->assertStringContainsString('# Title', $md);
        $this->assertStringContainsString('one', $md);
        $this->assertStringContainsString('https://example.com/a.png', $md);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->extractor->extract('', HtmlReadableExtractor::SCOPE_MAIN));
        $this->assertSame('', $this->extractor->extract('   ', HtmlReadableExtractor::SCOPE_MAIN));
    }

    public function test_extract_readable_surfaces_the_page_h1_as_headline(): void
    {
        $html = "<html><body><main><article><h1>  Oldtimertreff\n  im Hof </h1><p>Von April bis September trifft man sich im Hof zum Fachsimpeln und Geniessen.</p></article></main></body></html>";

        $result = $this->extractor->extractReadable($html, HtmlReadableExtractor::SCOPE_MAIN);

        $this->assertSame('Oldtimertreff im Hof', $result['headline']);
        $this->assertStringContainsString('Von April bis September', $result['markdown']);
    }

    public function test_extract_readable_finds_h1_outside_main_when_landmark_has_none(): void
    {
        // The <main> body qualifies as the content landmark but has no heading of
        // its own; the page H1 lives in the header. Fall back to the body's h1.
        $html = '<html><body><header><h1>Real Page Heading</h1></header><main><p>A sufficiently long article body so the main landmark is trusted as the real readable content here.</p></main></body></html>';

        $result = $this->extractor->extractReadable($html, HtmlReadableExtractor::SCOPE_MAIN);

        $this->assertSame('Real Page Heading', $result['headline']);
    }

    public function test_extract_readable_returns_null_headline_when_no_h1(): void
    {
        $html = '<html><body><main><h2>Only a subheading</h2><p>Body content with no level-one heading anywhere on the page at all.</p></main></body></html>';

        $result = $this->extractor->extractReadable($html, HtmlReadableExtractor::SCOPE_MAIN);

        $this->assertNull($result['headline']);
    }
}
