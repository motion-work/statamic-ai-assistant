<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\HtmlReadableExtractor;
use BoldWeb\StatamicAiAssistant\Services\PromptUrlFetcher;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class PromptUrlFetcherTest extends TestCase
{
    private PromptUrlFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset the per-request static fetch cache between tests.
        $prop = (new \ReflectionClass(PromptUrlFetcher::class))->getProperty('fetchCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $this->fetcher = new PromptUrlFetcher;
    }

    public function test_html_mode_requests_html_and_returns_extracted_markdown(): void
    {
        config(['statamic-ai-assistant.prompt_url_fetch.reader_format' => 'html']);

        Http::fake([
            'r.jina.ai/*' => Http::response(
                '<html><body><main><h1>Real Title</h1><p>The genuine article body that Jina markdown mode would have dropped.</p></main></body></html>',
                200,
            ),
        ]);

        $result = $this->fetcher->fetchSingle('https://example.com/page');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('# Real Title', $result['body']);
        $this->assertStringContainsString('genuine article body', $result['body']);
        // No raw HTML should survive the conversion.
        $this->assertStringNotContainsString('<main>', $result['body']);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Return-Format', 'html'));
    }

    public function test_markdown_kill_switch_returns_reader_output_verbatim(): void
    {
        config(['statamic-ai-assistant.prompt_url_fetch.reader_format' => 'markdown']);

        Http::fake([
            'r.jina.ai/*' => Http::response("# Jina Markdown\n\nReader-provided content.", 200),
        ]);

        $result = $this->fetcher->fetchSingle('https://example.com/page');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Reader-provided content.', $result['body']);

        Http::assertSent(fn ($request) => ! $request->hasHeader('X-Return-Format'));
    }

    public function test_html_extraction_empty_falls_back_to_markdown(): void
    {
        config(['statamic-ai-assistant.prompt_url_fetch.reader_format' => 'html']);

        // First call (html, with X-Return-Format) yields an empty document;
        // second call (markdown fallback, different headers) yields content.
        Http::fakeSequence('r.jina.ai/*')
            ->push('<html><body></body></html>', 200)
            ->push("# Fallback markdown content", 200);

        $result = $this->fetcher->fetchSingle('https://example.com/page');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Fallback markdown content', $result['body']);
        Http::assertSentCount(2);
    }

    public function test_full_scope_keeps_links_outside_main(): void
    {
        config(['statamic-ai-assistant.prompt_url_fetch.reader_format' => 'html']);

        Http::fake([
            'r.jina.ai/*' => Http::response(
                '<html><body><main><p>Detail body.</p></main><section><a href="/x">Discover this teaser</a></section></body></html>',
                200,
            ),
        ]);

        $result = $this->fetcher->fetchSingle('https://example.com/list', HtmlReadableExtractor::SCOPE_FULL);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Discover this teaser', $result['body']);
    }
}
