<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Http\Client\Response;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenAiCompatibleClient;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenAiCompatibleService;

/**
 * Infomaniak AI (Swiss-hosted). Tasks map onto its configured models: Light
 * uses the fast model (swapped into infomaniak_model, see
 * AbstractAiService::forTask()), Vision the vision model, everything else the
 * primary model.
 */
class InfomaniakService extends OpenAiCompatibleService
{
    protected function providerName(): string
    {
        return 'infomaniak';
    }

    protected function providerLabel(): string
    {
        return 'Infomaniak';
    }

    /**
     * v2 chat completion endpoint. Required for tool calling — the legacy v1
     * path silently drops `tools` / `tool_choice` parameters on the floor.
     */
    protected function client(): OpenAiCompatibleClient
    {
        $productId = config('statamic-ai-assistant.infomaniak_product_id');

        return new OpenAiCompatibleClient(
            "https://api.infomaniak.com/2/ai/{$productId}/openai/v1/chat/completions",
            (string) config('statamic-ai-assistant.infomaniak_api_token'),
            (int) config('statamic-ai-assistant.infomaniak_http_timeout', 120),
        );
    }

    protected function modelConfigKey(): string
    {
        return 'statamic-ai-assistant.infomaniak_model';
    }

    protected function fastModel(): ?string
    {
        $fast = (string) config('statamic-ai-assistant.infomaniak_model_fast', '');

        return $fast !== '' ? $fast : null;
    }

    public function supportsVision(): bool
    {
        return trim((string) config('statamic-ai-assistant.infomaniak_vision_model', '')) !== '';
    }

    protected function modelFields(AiTask $task): array
    {
        $model = $task === AiTask::Vision
            ? (string) config('statamic-ai-assistant.infomaniak_vision_model')
            : (string) config('statamic-ai-assistant.infomaniak_model');

        return ['model' => $model, ...$this->thinkingParams($model)];
    }

    /**
     * Request fields that switch reasoning off while withoutThinking() is active.
     *
     * Infomaniak serves its open models through vLLM, where the switch is a chat
     * template kwarg: `thinking` for Kimi, `enable_thinking` for Qwen; templates
     * ignore the one they don't know. Mistral tokenizers reject any
     * chat_template_kwargs (HTTP 400), and those models don't think anyway.
     *
     * @return array<string, mixed>
     */
    private function thinkingParams(string $model): array
    {
        if (! $this->thinkingDisabled() || str_contains(strtolower($model), 'mistral')) {
            return [];
        }

        return ['chat_template_kwargs' => ['thinking' => false, 'enable_thinking' => false]];
    }

    protected function failureException(Response $response): \RuntimeException
    {
        $hint = $response->json('error.message')
            ?? $response->json('message')
            ?? mb_substr($response->body(), 0, 500);

        return new \RuntimeException(
            __('Infomaniak request failed (:status): :hint', [
                'status' => $response->status(),
                'hint' => $hint,
            ])
        );
    }

    protected function emptyReplyMessage(): string
    {
        return __('The AI returned no text. Check your Infomaniak product ID, model name, and prompt size.');
    }
}
