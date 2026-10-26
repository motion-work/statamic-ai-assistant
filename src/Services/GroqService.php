<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Support\OpenAiChatCompletionStream;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use LucianoTonet\GroqLaravel\Facades\Groq;
use LucianoTonet\GroqPHP\GroqException;

class GroqService extends AbstractAiService
{
    public function supportsChatTools(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     */
    public function createChatCompletion(array $messages, ?int $maxTokens, array $tools = [], string|array $toolChoice = 'auto', ?callable $streamHeartbeat = null): array
    {
        $apiKey = config('statamic-ai-assistant.groq_api_key');

        if (! $apiKey) {
            throw new \RuntimeException(__('GROQ_API_KEY is not configured.'));
        }

        $payload = [
            'model' => config('statamic-ai-assistant.groq_model'),
            'messages' => $messages,
            'temperature' => config('statamic-ai-assistant.temperature'),
            'max_tokens' => $maxTokens ?? (int) config('statamic-ai-assistant.max_tokens'),
            'stream' => false,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice;
        }

        $httpTimeout = max(30, (int) config('statamic-ai-assistant.groq_http_timeout', 300));

        try {
            $client = new Client(['timeout' => $httpTimeout, 'read_timeout' => $httpTimeout]);
            $requestOpts = [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'http_errors' => false,
            ];
            if ($streamHeartbeat !== null) {
                $requestOpts['progress'] = static function () use ($streamHeartbeat): void {
                    $streamHeartbeat();
                };
            }
            $response = $client->post('https://api.groq.com/openai/v1/chat/completions', $requestOpts);
        } catch (\Throwable $e) {
            Log::error('Groq chat completion (tools) error', ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if ($response->getStatusCode() >= 400) {
            $hint = is_array($data) ? ($data['error']['message'] ?? $body) : $body;

            throw new \RuntimeException(
                __('Groq request failed (:status): :hint', [
                    'status' => $response->getStatusCode(),
                    'hint' => mb_substr((string) $hint, 0, 500),
                ])
            );
        }

        if (! is_array($data)) {
            throw new \RuntimeException(__('Unexpected response from Groq.'));
        }

        return $data;
    }

    /**
     * Call the Groq API with the provided messages.
     *
     * @param array $messages
     * @return string
     */
    protected function callApi(array $messages): string
    {
        $payload = [
            'messages'   => $messages,
            'model'      => config('statamic-ai-assistant.groq_model'),
            'temperature'=> config('statamic-ai-assistant.temperature'),
            'max_tokens' => config('statamic-ai-assistant.max_tokens'),
            'stop'       => null,
            'stream'     => false,
        ];

        try {
            $response = Groq::chat()->completions()->create($payload);
        } catch (GroqException $e) {
            Log::error('Groq API error', ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            Log::error('Groq API error', ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if (! is_array($response)) {
            Log::warning('Groq API returned unexpected response type', ['type' => get_debug_type($response)]);

            throw new \RuntimeException(__('Unexpected response from AI provider.'));
        }

        $content = $this->extractCompletionMessageContent($response);

        if ($content === '') {
            Log::warning('Groq returned empty assistant content', [
                'response_excerpt' => mb_substr(json_encode($response), 0, 2000),
            ]);

            throw new \RuntimeException(
                __('The AI returned no text. Check that :model is a valid Groq model name, or try a shorter prompt.', [
                    'model' => (string) config('statamic-ai-assistant.groq_model'),
                ])
            );
        }

        return $content;
    }

    /**
     * {@inheritdoc}
     */
    protected function callApiStreaming(array $messages, callable $onDelta): string
    {
        $apiKey = config('statamic-ai-assistant.groq_api_key');

        if (! $apiKey) {
            throw new \RuntimeException(__('GROQ_API_KEY is not configured.'));
        }

        $payload = [
            'messages' => $messages,
            'model' => config('statamic-ai-assistant.groq_model'),
            'temperature' => config('statamic-ai-assistant.temperature'),
            'max_tokens' => config('statamic-ai-assistant.max_tokens'),
            'stream' => true,
        ];

        $httpTimeout = max(30, (int) config('statamic-ai-assistant.groq_http_timeout', 300));

        try {
            $client = new Client(['timeout' => $httpTimeout, 'read_timeout' => $httpTimeout]);
            $response = $client->post('https://api.groq.com/openai/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'stream' => true,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('Groq streaming API error', ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() >= 400) {
            $hint = (string) $response->getBody();

            throw new \RuntimeException(
                __('Groq streaming request failed (:status): :hint', [
                    'status' => $response->getStatusCode(),
                    'hint' => mb_substr($hint, 0, 500),
                ])
            );
        }

        $stream = $response->getBody();
        $full = OpenAiChatCompletionStream::collect($stream, $onDelta);

        if ($full === '') {
            throw new \RuntimeException(
                __('The AI returned no text. Check that :model is a valid Groq model name, or try a shorter prompt.', [
                    'model' => (string) config('statamic-ai-assistant.groq_model'),
                ])
            );
        }

        return $full;
    }
}
