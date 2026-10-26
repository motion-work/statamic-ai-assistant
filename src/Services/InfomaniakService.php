<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Support\OpenAiChatCompletionStream;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InfomaniakService extends AbstractAiService
{
    public function supportsChatTools(): bool
    {
        return true;
    }

    /**
     * v2 chat completion endpoint. Required for tool calling — the legacy v1
     * path silently drops `tools` / `tool_choice` parameters on the floor.
     */
    private function endpointUrl(): string
    {
        $productId = config('statamic-ai-assistant.infomaniak_product_id');

        return "https://api.infomaniak.com/2/ai/{$productId}/openai/v1/chat/completions";
    }

    private function timeout(): int
    {
        return (int) config('statamic-ai-assistant.infomaniak_http_timeout', 120);
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

    /**
     * Build a RuntimeException message from a failed HTTP response.
     */
    private function failureException(\Illuminate\Http\Client\Response $response): \RuntimeException
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

    /**
     * {@inheritdoc}
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     */
    public function createChatCompletion(array $messages, ?int $maxTokens, array $tools = [], string|array $toolChoice = 'auto', ?callable $streamHeartbeat = null): array
    {
        $payload = [
            'messages' => $messages,
            'model' => config('statamic-ai-assistant.infomaniak_model'),
            'temperature' => config('statamic-ai-assistant.temperature'),
            'max_tokens' => $maxTokens ?? (int) config('statamic-ai-assistant.max_tokens'),
            'stream' => false,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice;
        }

        $guzzleExtras = [];
        if ($streamHeartbeat !== null) {
            $guzzleExtras['progress'] = static function () use ($streamHeartbeat): void {
                $streamHeartbeat();
            };
        }

        try {
            $response = Http::timeout($this->timeout())
                ->retry($this->aiRetryTimes(), $this->aiRetrySleepMs(), $this->aiRetryWhen(), throw: false)
                ->withToken(config('statamic-ai-assistant.infomaniak_api_token'))
                ->withOptions($guzzleExtras)
                ->post($this->endpointUrl(), $payload);
        } catch (\Throwable $e) {
            Log::error('Infomaniak chat completion (tools) exception', ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw $this->failureException($response);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new \RuntimeException(__('Unexpected response from Infomaniak.'));
        }

        return $data;
    }

    /**
     * Call the Infomaniak API with the provided messages.
     *
     * @param array $messages
     * @return string
     */
    protected function callApi(array $messages): string
    {
        $payload = [
            'messages'    => $messages,
            'model'       => config('statamic-ai-assistant.infomaniak_model'),
            'temperature' => config('statamic-ai-assistant.temperature'),
            'max_tokens'  => config('statamic-ai-assistant.max_tokens'),
            'stream'      => false,
        ];

        try {
            $response = Http::timeout($this->timeout())
                ->retry($this->aiRetryTimes(), $this->aiRetrySleepMs(), $this->aiRetryWhen(), throw: false)
                ->withToken(config('statamic-ai-assistant.infomaniak_api_token'))
                ->post($this->endpointUrl(), $payload);

            if (! $response->successful()) {
                Log::error('Infomaniak API HTTP error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 2000),
                ]);

                throw $this->failureException($response);
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new \RuntimeException(__('Unexpected response from Infomaniak.'));
            }

            $content = $this->extractCompletionMessageContent($data);

            if ($content === '') {
                Log::warning('Infomaniak returned empty assistant content', [
                    'response_excerpt' => mb_substr(json_encode($data), 0, 2000),
                ]);

                throw new \RuntimeException(
                    __('The AI returned no text. Check your Infomaniak product ID, model name, and prompt size.')
                );
            }

            return $content;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Infomaniak API exception', ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function callApiStreaming(array $messages, callable $onDelta): string
    {
        $payload = [
            'messages' => $messages,
            'model' => config('statamic-ai-assistant.infomaniak_model'),
            'temperature' => config('statamic-ai-assistant.temperature'),
            'max_tokens' => config('statamic-ai-assistant.max_tokens'),
            'stream' => true,
        ];

        $timeout = $this->timeout();

        try {
            $response = Http::timeout($timeout)
                ->withToken(config('statamic-ai-assistant.infomaniak_api_token'))
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => $timeout,
                ])
                ->post($this->endpointUrl(), $payload);
        } catch (\Throwable $e) {
            Log::error('Infomaniak streaming API exception', ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw $this->failureException($response);
        }

        $stream = $response->toPsrResponse()->getBody();
        $full = OpenAiChatCompletionStream::collect($stream, $onDelta);

        if ($full === '') {
            throw new \RuntimeException(
                __('The AI returned no text. Check your Infomaniak product ID, model name, and prompt size.')
            );
        }

        return $full;
    }
}
