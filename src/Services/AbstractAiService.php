<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\TrimAiOutput;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Statamic\Facades\Site;

abstract class AbstractAiService
{
    /** Whether calls inside withoutThinking() should ask the model to skip reasoning. */
    private bool $thinkingDisabled = false;

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
        // Fast-tier tasks are short by design (small max_tokens budgets), so a
        // reasoning model must answer directly instead of thinking first.
        $fn = fn () => $this->withoutThinking($fn);

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
     * Run $fn with reasoning ("thinking") switched off for every provider call it
     * makes. Reasoning models otherwise spend 1–2k tokens thinking before even a
     * one-sentence answer: slow (≈15s instead of ≈1s) and, with small max_tokens
     * budgets, an empty reply. Used for quick tasks (fieldtype generate/refactor,
     * alt text, fast-tier helpers); the AI agent's planning and entry generation
     * keep thinking on.
     *
     * Providers that have no thinking switch ignore the flag. A no-op when
     * quick_tasks_without_thinking is false.
     *
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    public function withoutThinking(callable $fn): mixed
    {
        if (! config('statamic-ai-assistant.quick_tasks_without_thinking', true)) {
            return $fn();
        }

        $previous = $this->thinkingDisabled;
        $this->thinkingDisabled = true;

        try {
            return $fn();
        } finally {
            $this->thinkingDisabled = $previous;
        }
    }

    protected function thinkingDisabled(): bool
    {
        return $this->thinkingDisabled;
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
     * Whether this provider can analyze images (a vision model is configured).
     */
    public function supportsVision(): bool
    {
        return false;
    }

    /**
     * Describe an image (data URL) with the provider's vision model.
     * Only callable when supportsVision() is true.
     */
    public function describeImage(string $imageDataUrl, string $prompt): string
    {
        throw new \RuntimeException(__('This AI provider does not support image analysis.'));
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
    public function generateContentFromPrompt(string $prompt, array $context = []): string
    {
        $currentLocale = optional(Site::selected())->locale() ?: 'en';
        $promptAdded = 'if not specified YOU NEED to write this article in this language: '.$currentLocale;

        $messages = [];
        $system = trim((config('statamic-ai-assistant.prompt_preface') ? config('statamic-ai-assistant.prompt_preface').$promptAdded : '')
            ."\n\n".$this->fieldContextInstructions($context));
        if ($system !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $system,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $content = $this->withoutThinking(fn () => $this->callApi($messages));

        return $this->cleanResult($content);
    }

    /**
     * Generate refactored content using the provided text and instructions.
     */
    public function generateRefactorFromPrompt(string $textToRefactor, string $prompt, array $context = []): string
    {
        $messages = [];
        $system = trim((string) config('statamic-ai-assistant.prompt_refactor_preface')."\n\n".$this->fieldContextInstructions($context));
        if ($system !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $system,
            ];
        }

        $combinedPrompt = "Please refactor the following text:\n\n"
            .$textToRefactor
            ."\n\nUser instructions for the refactoring: ".$prompt;

        $messages[] = [
            'role' => 'user',
            'content' => $combinedPrompt,
        ];

        $content = $this->withoutThinking(fn () => $this->callApi($messages));

        return $this->cleanResult($content);
    }

    /**
     * Generate HTML refactored content using the provided text and instructions.
     */
    public function generateHtmlRefactorFromPrompt(string $textToRefactor, string $prompt, array $context = []): string
    {
        $messages = [];
        $system = trim((string) config('statamic-ai-assistant.prompt_html_refactor_preface')."\n\n".$this->fieldContextInstructions($context));
        if ($system !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $system,
            ];
        }

        $combinedPrompt = "Please refactor the following text:\n\n"
            .$textToRefactor
            ."\n\nUser instructions for the refactoring: ".$prompt;

        $messages[] = [
            'role' => 'user',
            'content' => $combinedPrompt,
        ];

        $content = $this->withoutThinking(fn () => $this->callApi($messages));

        return $this->cleanResult($content);
    }

    /**
     * Instructions describing where the text goes, so a field-level request
     * doesn't need the editor to repeat the context: the field (label + the
     * editor-written field hint from the AI instructions page), the entry it
     * belongs to, the site-wide instructions, and an optional word limit.
     *
     * @param  array{field_label?: string, field_handle?: string, entry_title?: string, max_words?: int|string|null}  $context
     */
    protected function fieldContextInstructions(array $context): string
    {
        $lines = [];

        $label = trim((string) ($context['field_label'] ?? ''));
        if ($label !== '') {
            $lines[] = "You are writing the content of the field \"{$label}\".";
        }

        $handle = trim((string) ($context['field_handle'] ?? ''));
        if ($handle !== '') {
            $hint = app(SetHintsService::class)->forField($handle);
            if ($hint !== null) {
                if (($hint['ai_description'] ?? '') !== '') {
                    $lines[] = 'What belongs in this field: '.$hint['ai_description'];
                }
                if (($hint['when_to_use'] ?? []) !== []) {
                    $lines[] = "Writing guidelines for this field:\n- ".implode("\n- ", $hint['when_to_use']);
                }
            }
        }

        $title = trim((string) ($context['entry_title'] ?? ''));
        if ($title !== '' && $title !== $label) {
            $lines[] = "It belongs to the page \"{$title}\". Stay on that topic.";
        }

        $site = app(SetHintsService::class)->siteInstructions();
        if ($site !== '') {
            $lines[] = "Site-wide instructions (always follow):\n{$site}";
        }

        $maxWords = (int) ($context['max_words'] ?? 0);
        if ($maxWords > 0) {
            $lines[] = "Keep the result to at most {$maxWords} words.";
        }

        return implode("\n\n", $lines);
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
