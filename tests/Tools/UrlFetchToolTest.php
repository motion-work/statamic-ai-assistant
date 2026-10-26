<?php

namespace MotionWork\StatamicAiAssistant\Tests\Tools;

use MotionWork\StatamicAiAssistant\Services\PromptUrlFetcher;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;
use MotionWork\StatamicAiAssistant\Tools\UrlFetchTool;
use Illuminate\Support\Facades\Http;

class UrlFetchToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset PromptUrlFetcher's static fetch cache between tests.
        $prop = (new \ReflectionClass(PromptUrlFetcher::class))->getProperty('fetchCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    public function test_reports_a_reading_activity_line_and_returns_result(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response('<html><body><main><p>Body</p></main></body></html>', 200),
        ]);

        $lines = [];
        $ctx = new ToolContext(activitySink: function (string $l) use (&$lines) {
            $lines[] = $l;
        });

        // Host is on the allowlist (user provided it), so the fetch proceeds.
        $tool = new UrlFetchTool(new PromptUrlFetcher, ['example.com'], true);
        $result = $tool->handle('{"url":"https://example.com/events","reason":"list"}', $ctx);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($lines);
        // Scheme stripped, no leaked "https://".
        $this->assertStringContainsString('example.com/events', $lines[0]);
        $this->assertStringNotContainsString('https://', $lines[0]);
    }

    public function test_refuses_to_fetch_a_url_the_user_did_not_provide(): void
    {
        Http::fake([
            '*' => Http::response('should never be requested', 200),
        ]);

        $ctx = new ToolContext;
        // User referenced allowed-site.test — a guessed example.com URL must be refused.
        $tool = new UrlFetchTool(new PromptUrlFetcher, ['allowed-site.test'], true);
        $result = $tool->handle('{"url":"https://example.com/made-up","reason":"guessing"}', $ctx);

        $this->assertFalse($result['ok']);
        $this->assertSame('url_not_allowed', $result['error']);
        Http::assertNothingSent();
    }

    public function test_allows_same_site_and_www_variants_of_a_provided_host(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response('<html><body><main><p>Body</p></main></body></html>', 200),
        ]);

        $ctx = new ToolContext;
        $tool = new UrlFetchTool(new PromptUrlFetcher, ['allowed-site.test'], true);

        // www. variant and a same-site detail page are both allowed.
        $this->assertTrue($tool->handle('{"url":"https://www.allowed-site.test/","reason":"home"}', $ctx)['ok']);
        $this->assertTrue($tool->handle('{"url":"https://allowed-site.test/events/x","reason":"detail"}', $ctx)['ok']);
    }

    public function test_empty_allowlist_forbids_all_fetching(): void
    {
        Http::fake(['*' => Http::response('nope', 200)]);

        $ctx = new ToolContext;
        $tool = new UrlFetchTool(new PromptUrlFetcher, [], true);
        $result = $tool->handle('{"url":"https://example.com/","reason":"no source"}', $ctx);

        $this->assertFalse($result['ok']);
        $this->assertSame('url_not_allowed', $result['error']);
        Http::assertNothingSent();
    }

    public function test_hosts_from_messages_extracts_only_user_provided_hosts(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'ignore https://system-should-not-count.example'],
            ['role' => 'user', 'content' => 'Copy https://www.allowed-site.test/de and http://foo.test/x please'],
            ['role' => 'assistant', 'content' => 'ok https://assistant-should-not-count.example'],
        ];

        $hosts = UrlFetchTool::hostsFromMessages(new PromptUrlFetcher, $messages);

        $this->assertContains('www.allowed-site.test', $hosts);
        $this->assertContains('foo.test', $hosts);
        $this->assertNotContains('system-should-not-count.example', $hosts);
        $this->assertNotContains('assistant-should-not-count.example', $hosts);
    }
}
