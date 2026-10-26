<?php

namespace BoldWeb\StatamicAiAssistant\Tools;

use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Services\RemoteImageFetcher;

/**
 * ChatTool adapter over RemoteImageFetcher's save_remote_image capability. Pulls
 * the asset sink + container hint from the ToolContext so its execution fits the
 * uniform handle() signature. Only registered on the create path (where saved
 * images are auto-attached), so its presence is decided at wiring time.
 */
class SaveImageTool implements ChatTool
{
    public function __construct(private RemoteImageFetcher $fetcher) {}

    public function name(): string
    {
        return 'save_remote_image';
    }

    public function definition(): array
    {
        return $this->fetcher->chatToolDefinition();
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        $args = json_decode($argumentsJson, true);
        if (is_array($args) && isset($args['url']) && is_string($args['url'])) {
            $short = preg_replace('~^https?://~i', '', trim($args['url']));
            $context->reportActivity((string) __('Saving image :url', ['url' => \Illuminate\Support\Str::limit((string) $short, 50)]));
        }

        $warnings = [];
        $json = $this->fetcher->executeChatTool(
            $argumentsJson,
            $context->imageSink ?? new PreferredAssetPaths,
            $context->imageContainerHint,
            $context->onStreamToken(),
            $warnings,
        );
        foreach ($warnings as $w) {
            $context->addWarning((string) $w);
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'tool_error'];
    }

    public function maxCalls(): ?int
    {
        return max(0, (int) config('statamic-ai-assistant.image_fetch.max_images', 30));
    }
}
