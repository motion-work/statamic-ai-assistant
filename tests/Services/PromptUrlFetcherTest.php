<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\HtmlReadableExtractor;
use MotionWork\StatamicAiAssistant\Services\PromptUrlFetcher;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
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
        config([
            'statamic-ai-assistant.prompt_url_fetch.reader_format' => 'html',
            'statamic-ai-assistant.prompt_url_fetch.direct_first' => false,
        ]);

        // Jina html (X-Return-Format) yields an empty document, the direct fetch
        // is blocked, so it falls through to Jina's markdown mode.
        Http::fake([
            'r.jina.ai/*' => Http::sequence()
                ->push('<html><body></body></html>', 200)
                ->push('# Fallback markdown content', 200),
            '*' => Http::response('', 403),
        ]);

        $result = $this->fetcher->fetchSingle('https://example.com/page');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Fallback markdown content', $result['body']);
        // jina-html → direct (blocked) → jina-markdown.
        Http::assertSentCount(3);
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

    public function test_direct_fetch_is_used_first_and_skips_jina_for_server_rendered_pages(): void
    {
        config([
            'statamic-ai-assistant.prompt_url_fetch.reader_format' => 'html',
            'statamic-ai-assistant.prompt_url_fetch.direct_first' => true,
        ]);

        Http::fake([
            'r.jina.ai/*' => Http::response('<html><body><main><p>SHOULD NOT BE USED</p></main></body></html>', 200),
            '*' => Http::response(
                '<html><body><main><h1>Direct Title</h1><p>Server-rendered content fetched directly.</p></main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $result = $this->fetcher->fetchSingle('https://direct-site.test/page');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('# Direct Title', $result['body']);
        $this->assertStringContainsString('fetched directly', $result['body']);
        // Jina must not be touched when the direct fetch already yields content.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'r.jina.ai'));
    }

    public function test_direct_fetch_failure_falls_back_to_jina(): void
    {
        config([
            'statamic-ai-assistant.prompt_url_fetch.reader_format' => 'html',
            'statamic-ai-assistant.prompt_url_fetch.direct_first' => true,
        ]);

        Http::fake([
            'r.jina.ai/*' => Http::response('<html><body><main><h1>Via Jina</h1><p>Recovered through the reader.</p></main></body></html>', 200),
            '*' => Http::response('', 403),
        ]);

        $result = $this->fetcher->fetchSingle('https://blocked.test/page');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('Recovered through the reader.', $result['body']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'r.jina.ai'));
    }

    public function test_direct_fetch_with_empty_body_html_falls_back_to_jina(): void
    {
        // Reproduces the TYPO3/Jina case: a 200 HTML response whose <body> is
        // empty (real markup dumped elsewhere) — local extraction yields nothing,
        // so we must fall back to the reader instead of returning empty.
        config([
            'statamic-ai-assistant.prompt_url_fetch.reader_format' => 'html',
            'statamic-ai-assistant.prompt_url_fetch.direct_first' => true,
        ]);

        Http::fake([
            'r.jina.ai/*' => Http::response('<html><body><main><h1>Reader Save</h1><p>The reader recovered the content.</p></main></body></html>', 200),
            '*' => Http::response('<html lang="de"><head></head><body></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->fetcher->fetchSingle('https://typo3-site.test/page');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('The reader recovered the content.', $result['body']);
    }
}
