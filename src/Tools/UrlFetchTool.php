<?php

namespace BoldWeb\StatamicAiAssistant\Tools;

use BoldWeb\StatamicAiAssistant\Services\PromptUrlFetcher;

/**
 * ChatTool adapter over PromptUrlFetcher's fetch_page_content capability. The
 * fetcher keeps its own responsibilities (inline prompt augmentation, caching);
 * this thin adapter just exposes it through the shared ChatTool contract.
 */
class UrlFetchTool implements ChatTool
{
    public function __construct(private PromptUrlFetcher $fetcher) {}

    public function name(): string
    {
        return 'fetch_page_content';
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
            $context->reportActivity((string) __('Reading :url', ['url' => \Illuminate\Support\Str::limit((string) $short, 60)]));
        }

        $warnings = [];
        $json = $this->fetcher->executeChatTool($argumentsJson, $context->onStreamToken(), $warnings);
        foreach ($warnings as $w) {
            $context->addWarning((string) $w);
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'tool_error'];
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_fetches', 100));
    }
}
