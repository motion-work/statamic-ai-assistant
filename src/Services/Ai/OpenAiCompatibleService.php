<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Support\OpenAiChatCompletionStream;

/**
 * The request/reply flow every OpenAI-style chat completions provider shares:
 * payload building, sending (OpenAiCompatibleClient), error and empty-reply
 * handling, and the AiCompletionFinished event. A provider only says which
 * endpoint and model to use, how to word its errors, and what extra routing
 * fields it sends.
 */
abstract class OpenAiCompatibleService extends AbstractAiService
{
    /** Request fields a request option may never replace. */
    private const PROTECTED_FIELDS = ['messages', 'stream'];

    /** Machine name used in events and logs ("openrouter", "infomaniak"). */
    abstract protected function providerName(): string;

    /** Human name used in editor-facing messages ("OpenRouter"). */
    abstract protected function providerLabel(): string;

    abstract protected function client(): OpenAiCompatibleClient;

    /**
     * Model fields for a call of $task: `model`, and provider-specific ones
     * (`models` fallbacks, `preset`, `reasoning`, `chat_template_kwargs`).
     *
     * @return array<string, mixed>
     */
    abstract protected function modelFields(AiTask $task): array;

    abstract protected function failureException(Response $response): \RuntimeException;

    /** Message when the model answered with no text. */
    abstract protected function emptyReplyMessage(): string;

    /**
     * Fields added after the payload is built (provider routing, end-user id).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function routingFields(array $payload): array
    {
        return [];
    }

    /**
     * Request option keys this provider accepts, or null for any key.
     *
     * @return list<string>|null
     */
    protected function allowedRequestOptions(): ?array
    {
        return ['response_format'];
    }

    /** max_tokens for $task when the caller set none (OpenRouter profiles). */
    protected function taskMaxTokens(AiTask $task): ?int
    {
        return null;
    }

    /** Temperature for $task (OpenRouter profiles); null uses the config value. */
    protected function taskTemperature(AiTask $task): ?float
    {
        return null;
    }

    /** Hook run before AiCompletionFinished is dispatched (provider logging). */
    protected function completionFinished(AiCompletionFinished $event): void {}

    public function supportsChatTools(): bool
    {
        return true;
    }

    protected function callApi(array $messages): string
    {
        return $this->replyText($this->complete($this->payload($messages)));
    }

    /**
     * {@inheritdoc}
     */
    protected function callApiStreaming(array $messages, callable $onDelta): string
    {
        $payload = $this->payload($messages, stream: true);
        $started = hrtime(true);

        try {
            $response = $this->client()->stream($payload);
        } catch (\Throwable $e) {
            Log::error("{$this->providerLabel()} streaming request exception", ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw $this->failed($response);
        }

        $meta = [];
        $full = OpenAiChatCompletionStream::collect($response->toPsrResponse()->getBody(), $onDelta, $meta);
        $meta['id'] ??= $response->header('X-Generation-Id') ?: null;
        $this->finished($payload, $meta, true, $started);

        if ($full === '') {
            throw $this->emptyReply($meta['finish_reason'] ?? null);
        }

        return $full;
    }

    /**
     * {@inheritdoc}
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     */
    public function createChatCompletion(array $messages, ?int $maxTokens, array $tools = [], string|array $toolChoice = 'auto', ?callable $streamHeartbeat = null): array
    {
        $payload = $this->payload($messages, maxTokens: $maxTokens, tools: $tools, toolChoice: $toolChoice);

        return $this->complete($payload, $streamHeartbeat);
    }

    /**
     * One-shot image description as an AiTask::Vision call, using the OpenAI
     * multimodal content-part format (text + image_url data URL).
     */
    public function describeImage(string $imageDataUrl, string $prompt): string
    {
        if (! $this->supportsVision()) {
            throw new \RuntimeException(__('This AI provider does not support image analysis.'));
        }

        return $this->forTask(AiTask::Vision, function () use ($imageDataUrl, $prompt): string {
            $messages = [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl]],
                ],
            ]];

            try {
                $data = $this->complete($this->payload($messages, maxTokens: 800));
            } catch (\RuntimeException $e) {
                Log::error("{$this->providerLabel()} vision request failed", ['message' => $e->getMessage()]);

                throw new \RuntimeException(__('Image analysis failed.'), 0, $e);
            }

            $text = trim($this->extractCompletionMessageContent($data));

            if ($text === '') {
                throw new \RuntimeException(__('Image analysis returned no result.'));
            }

            return $text;
        });
    }

    /**
     * The chat completions payload for the current task and request options.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  string|array<string, mixed>  $toolChoice
     * @return array<string, mixed>
     */
    protected function payload(array $messages, bool $stream = false, ?int $maxTokens = null, array $tools = [], string|array $toolChoice = 'auto'): array
    {
        $task = $this->currentTask();

        $payload = [
            'messages' => $messages,
            ...$this->modelFields($task),
            'temperature' => $this->taskTemperature($task) ?? config('statamic-ai-assistant.temperature'),
            'max_tokens' => $maxTokens ?? $this->maxTokensOverride() ?? $this->taskMaxTokens($task) ?? (int) config('statamic-ai-assistant.max_tokens'),
            'stream' => $stream,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = $toolChoice;
        }

        $payload = array_replace($payload, $this->acceptedRequestOptions());

        return array_replace($payload, $this->routingFields($payload));
    }

    /**
     * Send a non-streaming payload and return the decoded reply.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function complete(array $payload, ?callable $heartbeat = null): array
    {
        $started = hrtime(true);

        try {
            $response = $this->client()->send($payload, $heartbeat);
        } catch (\Throwable $e) {
            Log::error("{$this->providerLabel()} request exception", ['message' => $e->getMessage()]);

            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw $this->failed($response);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new \RuntimeException(__('Unexpected response from :provider.', ['provider' => $this->providerLabel()]));
        }

        // OpenRouter can answer 200 with only an error (the model failed after routing).
        if (is_array($data['error'] ?? null)) {
            throw new \RuntimeException(__('The AI stopped with an error while answering: :message', [
                'message' => (string) ($data['error']['message'] ?? ''),
            ]));
        }

        $this->finished($payload, [
            'id' => $data['id'] ?? ($response->header('X-Generation-Id') ?: null),
            'model' => $data['model'] ?? null,
            'usage' => $data['usage'] ?? [],
        ], false, $started);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function replyText(array $data): string
    {
        $content = $this->extractCompletionMessageContent($data);

        if ($content !== '') {
            return $content;
        }

        Log::warning("{$this->providerLabel()} returned empty assistant content", [
            'response_excerpt' => mb_substr((string) json_encode($data), 0, 2000),
        ]);

        throw $this->emptyReply($data['choices'][0]['finish_reason'] ?? null);
    }

    private function emptyReply(mixed $finishReason): \RuntimeException
    {
        if ($finishReason === 'length') {
            return new \RuntimeException(__('The AI used its whole output budget before answering, usually on reasoning. Raise max_tokens or lower the reasoning effort.'));
        }

        return new \RuntimeException($this->emptyReplyMessage());
    }

    private function failed(Response $response): \RuntimeException
    {
        Log::error("{$this->providerLabel()} API HTTP error", [
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 2000),
        ]);

        return $this->failureException($response);
    }

    /** @return array<string, mixed> */
    private function acceptedRequestOptions(): array
    {
        $options = array_diff_key($this->requestOptions(), array_flip(self::PROTECTED_FIELDS));
        $allowed = $this->allowedRequestOptions();

        return $allowed === null ? $options : array_intersect_key($options, array_flip($allowed));
    }

    /**
     * Dispatch AiCompletionFinished for a call that succeeded. Protected for
     * calls outside chat completions (OpenRouter's images endpoint).
     *
     * @param  array<string, mixed>  $payload  model / models as requested
     * @param  array<string, mixed>  $meta  id, model, usage
     */
    protected function finished(array $payload, array $meta, bool $streamed, int $started): void
    {
        $requested = array_values(array_filter(
            is_array($payload['models'] ?? null) ? $payload['models'] : [$payload['model'] ?? null],
            fn ($model) => is_string($model) && $model !== '',
        ));

        $event = new AiCompletionFinished(
            provider: $this->providerName(),
            task: $this->currentTask(),
            requestedModels: $requested,
            model: is_string($meta['model'] ?? null) ? $meta['model'] : ($requested[0] ?? null),
            generationId: is_string($meta['id'] ?? null) ? $meta['id'] : null,
            usage: is_array($meta['usage'] ?? null) ? $meta['usage'] : [],
            streamed: $streamed,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            context: $this->completionContext(),
        );

        $this->completionFinished($event);
        event($event);
    }
}
