<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Support\TrimAiOutput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Statamic\Facades\Site;

abstract class AbstractAiService
{
    /**
     * @param  array  $messages
     * @return string
     */
    abstract protected function callApi(array $messages): string;

    /**
     * Whether a provider failure is transient and worth retrying: rate limiting,
     * gateway/upstream errors, and dropped connections. Hard timeouts are treated
     * as NON-transient on purpose — the model is simply slow, so retrying only
     * stacks full-length waits and delays the eventual (same) failure.
     */
    protected function isTransientAiFailure(\Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return ! Str::contains(Str::lower($e->getMessage()), ['timed out', 'timeout', 'operation too slow']);
        }

        if ($e instanceof RequestException) {
            return in_array($e->response?->status(), [429, 500, 502, 503, 504], true);
        }

        return false;
    }

    /** Max attempts for a transient provider call (1 = no retry). */
    protected function aiRetryTimes(): int
    {
        return max(1, (int) config('statamic-ai-assistant.ai_http_retry_times', 3));
    }

    /** Backoff between attempts (ms): honour Retry-After, else exponential + jitter. */
    protected function aiRetrySleepMs(): \Closure
    {
        return function (int $attempt, \Throwable $exception): int {
            if ($exception instanceof RequestException) {
                $retryAfter = (int) $exception->response?->header('Retry-After');
                if ($retryAfter > 0) {
                    return min($retryAfter, 30) * 1000;
                }
            }

            return min(8000, (2 ** max(0, $attempt - 1)) * 1000) + random_int(0, 300);
        };
    }

    /** `$when` predicate for Http::retry — retry transient failures only. */
    protected function aiRetryWhen(): \Closure
    {
        return fn (\Throwable $e): bool => $this->isTransientAiFailure($e);
    }

    /**
     * Config key holding this provider's primary model, or null if the provider
     * has no swappable model tier.
     */
    protected function modelConfigKey(): ?string
    {
        return null;
    }

    /**
     * The fast/cheap model for lightweight tasks, or null to keep the primary model.
     */
    protected function fastModel(): ?string
    {
        return null;
    }

    /**
     * Run $fn with the provider's fast model temporarily swapped in, restoring the
     * primary model afterwards. This reuses the same config-override pattern as the
     * max_tokens handling below, so there is no model handoff or context threading:
     * the call simply uses a different model, then everything reverts.
     *
     * A no-op (runs $fn as-is) when the provider has no fast tier or it resolves to
     * the same model — so callers can always wrap lightweight tasks safely.
     *
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    public function usingFastModel(callable $fn): mixed
    {
        $key = $this->modelConfigKey();
        $fast = $this->fastModel();

        if ($key === null || $fast === null || $fast === '' || $fast === config($key)) {
            return $fn();
        }

        $original = config($key);
        config([$key => $fast]);

        try {
            return $fn();
        } finally {
            config([$key => $original]);
        }
    }

    /**
     * Send raw messages to the LLM and return the raw response.
     *
     * Temporarily overrides max_tokens if $maxTokens is provided.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @param  callable(string): void|null  $onToken  Invoked with each streamed text delta (OpenAI-compatible providers only)
     */
    public function generateFromMessages(array $messages, ?int $maxTokens = null, ?callable $onToken = null): string
    {
        $originalMaxTokens = null;

        if ($maxTokens !== null) {
            $originalMaxTokens = config('statamic-ai-assistant.max_tokens');
            config(['statamic-ai-assistant.max_tokens' => $maxTokens]);
        }

        try {
            if ($onToken !== null) {
                return $this->callApiStreaming($messages, $onToken);
            }

            return $this->callApi($messages);
        } finally {
            if ($originalMaxTokens !== null) {
                config(['statamic-ai-assistant.max_tokens' => $originalMaxTokens]);
            }
        }
    }

    /**
     * Stream chat completion tokens to $onDelta and return the full assistant text.
     *
     * @param  array<array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     */
    protected function callApiStreaming(array $messages, callable $onDelta): string
    {
        throw new \RuntimeException(__('Streaming is not supported for this AI provider.'));
    }

    /**
     * Whether this provider supports OpenAI-style chat tools (function calling).
     */
    public function supportsChatTools(): bool
    {
        return false;
    }

    /**
     * Non-streaming chat completion with optional tools. Returns the raw API JSON (OpenAI shape).
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  string|array<string, mixed>  $toolChoice  'auto'|'required'|'none' or {type: 'function', function: {name: '...'}}
     * @return array<string, mixed>
     */
    /**
     * @param  callable(): void|null  $streamHeartbeat  Throttled CP keepalive while the HTTP client receives the completion body.
     */
    public function createChatCompletion(array $messages, ?int $maxTokens, array $tools = [], string|array $toolChoice = 'auto', ?callable $streamHeartbeat = null): array
    {
        throw new \RuntimeException(__('This AI provider does not support tool calling for entry generation.'));
    }

    /**
     * Generate content using the provided prompt.
     */
    public function generateContentFromPrompt(string $prompt): string
    {
        $currentLocale = optional(Site::selected())->locale() ?: 'en';
        $promptAdded = 'if not specified YOU NEED to write this article in this language: '.$currentLocale;

        $messages = [];
        if (config('statamic-ai-assistant.prompt_preface')) {
            $messages[] = [
                'role' => 'system',
                'content' => config('statamic-ai-assistant.prompt_preface').$promptAdded,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $content = $this->callApi($messages);

        return $this->cleanResult($content);
    }

    /**
     * Generate refactored content using the provided text and instructions.
     */
    public function generateRefactorFromPrompt(string $textToRefactor, string $prompt): string
    {
        $messages = [];
        if (config('statamic-ai-assistant.prompt_refactor_preface')) {
            $messages[] = [
                'role' => 'system',
                'content' => config('statamic-ai-assistant.prompt_refactor_preface'),
            ];
        }

        $combinedPrompt = "Please refactor the following text:\n\n"
            .$textToRefactor
            ."\n\nUser instructions for the refactoring: ".$prompt;

        $messages[] = [
            'role' => 'user',
            'content' => $combinedPrompt,
        ];

        $content = $this->callApi($messages);

        return $this->cleanResult($content);
    }

    /**
     * Generate HTML refactored content using the provided text and instructions.
     */
    public function generateHtmlRefactorFromPrompt(string $textToRefactor, string $prompt): string
    {
        $messages = [];
        if (config('statamic-ai-assistant.prompt_html_refactor_preface')) {
            $messages[] = [
                'role' => 'system',
                'content' => config('statamic-ai-assistant.prompt_html_refactor_preface'),
            ];
        }

        $combinedPrompt = "Please refactor the following text:\n\n"
            .$textToRefactor
            ."\n\nUser instructions for the refactoring: ".$prompt;

        $messages[] = [
            'role' => 'user',
            'content' => $combinedPrompt,
        ];

        $content = $this->callApi($messages);

        return $this->cleanResult($content);
    }

    /**
     * Extract assistant text from an OpenAI-style chat completion JSON payload.
     *
     * @param  array<string, mixed>  $data
     */
    protected function extractCompletionMessageContent(array $data): string
    {
        $choice = $data['choices'][0] ?? null;
        if (! is_array($choice)) {
            return '';
        }

        $message = $choice['message'] ?? null;
        if (! is_array($message)) {
            return '';
        }

        $content = $message['content'] ?? null;

        if (is_string($content)) {
            return $content;
        }

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && ($part['type'] ?? '') === 'text' && isset($part['text'])) {
                    $parts[] = (string) $part['text'];
                }
            }

            return implode("\n", $parts);
        }

        return '';
    }

    /**
     * Clean up the result by converting encoding and replacing erroneous characters.
     */
    public function cleanResult($content = ''): string
    {
        $content = mb_convert_encoding($content, 'UTF-8');
        $content = trim($content, '"');
        $content = str_replace("\u{2019}", "'", $content);
        $content = str_replace("\u{00E2}", "'", $content);
        $content = str_replace('  ', ' ', $content);
        $content = str_replace("\u{00E2}", '&', $content);
        $content = str_replace("\u{2018}", '-', $content);
        $content = str_replace("\u{0080}", '', $content);

        return TrimAiOutput::normalize($content);
    }
}
