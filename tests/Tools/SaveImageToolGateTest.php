<?php

namespace MotionWork\StatamicAiAssistant\Tests\Tools;

use MotionWork\StatamicAiAssistant\Services\AssetImageDownloader;
use MotionWork\StatamicAiAssistant\Services\ImageAltTextGenerator;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Services\RemoteImageFetcher;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\SaveImageTool;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;

class SaveImageToolGateTest extends TestCase
{
    /** A fetcher that records whether it was asked to download anything. */
    private function spyFetcher(bool &$called): RemoteImageFetcher
    {
        return new class($called) extends RemoteImageFetcher
        {
            public function __construct(private bool &$called)
            {
                parent::__construct(new AssetImageDownloader(new ImageAltTextGenerator(app(\MotionWork\StatamicAiAssistant\Services\AbstractAiService::class))));
            }

            public function executeChatTool(string $argumentsJson, PreferredAssetPaths $sink, ?string $containerHint, ?callable $onStreamToken, array &$warningsOut): string
            {
                $this->called = true;

                return json_encode(['ok' => true, 'path' => 'x.jpg']);
            }
        };
    }

    private function context(): ToolContext
    {
        return new ToolContext(imageSink: new PreferredAssetPaths, imageContainerHint: 'assets');
    }

    public function test_invented_host_is_refused_without_any_download_when_allowlist_empty(): void
    {
        $called = false;
        $tool = new SaveImageTool($this->spyFetcher($called), [], true);

        $result = $tool->handle(json_encode(['url' => 'https://upload.wikimedia.org/x.jpg', 'reason' => 'hero']), $this->context());

        $this->assertFalse($result['ok']);
        $this->assertSame('image_url_not_allowed', $result['error']);
        $this->assertFalse($called, 'no network download may be attempted for a refused URL');
    }

    public function test_foreign_host_refused_even_with_a_user_allowlist(): void
    {
        $called = false;
        $tool = new SaveImageTool($this->spyFetcher($called), ['example.com'], true);

        $result = $tool->handle(json_encode(['url' => 'https://upload.wikimedia.org/x.jpg', 'reason' => 'hero']), $this->context());

        $this->assertFalse($result['ok']);
        $this->assertFalse($called);
    }

    public function test_host_in_user_allowlist_is_downloaded(): void
    {
        $called = false;
        $tool = new SaveImageTool($this->spyFetcher($called), ['example.com'], true);

        $result = $tool->handle(json_encode(['url' => 'https://cdn.example.com/hero.jpg', 'reason' => 'hero']), $this->context());

        $this->assertTrue($result['ok'] ?? false);
        $this->assertTrue($called);
    }

    public function test_url_seen_in_fetched_content_is_allowed_even_on_a_foreign_cdn(): void
    {
        $called = false;
        $tool = new SaveImageTool($this->spyFetcher($called), ['example.com'], true);

        $context = $this->context();
        // Simulate a prior fetch of example.com whose body referenced a CDN image.
        $context->rememberFetchedContent('https://example.com/article', '{"content":"see https://cloudfront.net/media/hero.jpg here"}');

        $result = $tool->handle(json_encode(['url' => 'https://cloudfront.net/media/hero.jpg', 'reason' => 'hero']), $context);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertTrue($called);
    }

    public function test_restrict_false_lets_anything_through(): void
    {
        $called = false;
        $tool = new SaveImageTool($this->spyFetcher($called), [], false);

        $result = $tool->handle(json_encode(['url' => 'https://anywhere.example/x.jpg', 'reason' => 'hero']), $this->context());

        $this->assertTrue($result['ok'] ?? false);
        $this->assertTrue($called);
    }
}
