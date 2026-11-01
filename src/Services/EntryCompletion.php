<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;

/**
 * One entry's model call: the tool loop when the provider supports tools, a
 * plain completion otherwise (or when the loop fails), then the reply JSON.
 */
final class EntryCompletion
{
    public function __construct(
        private readonly AbstractAiService $aiService,
        private readonly GenerationToolLoop $toolLoop,
        private readonly EntryJsonParser $parser,
    ) {}

    public function usesUrlTool(): bool
    {
        return (bool) config('statamic-ai-assistant.entry_generator_fetch_url_tool', true)
            && (bool) config('statamic-ai-assistant.prompt_url_fetch.enabled', true)
            && $this->aiService->supportsChatTools();
    }

    /**
     * Runs the completion, with the tool loop when the provider supports it (a
     * failing loop falls back to one plain completion), and parses the JSON.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, string>  $toolWarnings
     * @param  bool  $hasAttachment  An attached document is the source — the model must not go online instead
     * @return array<string, mixed>
     */
    public function entryJson(array $messages, ?callable $onStreamToken, array &$toolWarnings, PreferredAssetPaths $imageSink, ?string $imageContainerHint, bool $hasAttachment): array
    {
        $maxTokens = (int) config('statamic-ai-assistant.generator_max_tokens', 4000);
        $rawResponse = null;

        if ($this->usesUrlTool()) {
            try {
                $rawResponse = $this->toolLoop->run($messages, $maxTokens, $onStreamToken, $toolWarnings, $imageSink, $imageContainerHint, $hasAttachment);
            } catch (\Throwable $e) {
                Log::warning('[entry-gen-tool] tool loop failed; falling back to single-shot completion', [
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        }

        $rawResponse ??= $this->aiService->generateFromMessages($messages, $maxTokens, $onStreamToken);

        if ($rawResponse === null || $rawResponse === '') {
            throw new \RuntimeException(__('The AI returned no content. Check your provider settings and try again.'));
        }

        return $this->parser->parseResponseOrRetry($rawResponse, $messages, $maxTokens, $onStreamToken);
    }
}
