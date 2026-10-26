<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Tools;

use BoldWeb\StatamicAiAssistant\Services\PromptUrlFetcher;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use BoldWeb\StatamicAiAssistant\Tools\ToolContext;
use BoldWeb\StatamicAiAssistant\Tools\UrlFetchTool;
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

        $tool = new UrlFetchTool(new PromptUrlFetcher);
        $result = $tool->handle('{"url":"https://example.com/events","reason":"list"}', $ctx);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($lines);
        // Scheme stripped, no leaked "https://".
        $this->assertStringContainsString('example.com/events', $lines[0]);
        $this->assertStringNotContainsString('https://', $lines[0]);
    }
}
