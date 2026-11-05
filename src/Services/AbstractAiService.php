<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Services\Ai\AiRetryPolicy;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Support\TrimAiOutput;
use Statamic\Facades\Site;

abstract class AbstractAiService
{
    /** Whether calls inside withoutThinking() should ask the model to skip reasoning. */
    private bool $thinkingDisabled = false;

    /** The task of the calls in progress (forTask()); null means Agent. */
    private ?AiTask $task = null;

    /** @var array<string, mixed> Extra payload fields for the calls in progress (withRequestOptions()). */
    private array $requestOptions = [];

    /** @var array<string, mixed> Caller context attached to AiCompletionFinished (withCompletionContext()). */
    private array $completionContext = [];

    /** max_tokens passed to generateFromMessages() for the call in progress. */
    private ?int $maxTokensOverride = null;

    abstract protected function callApi(array $messages): string;

    /**
     * Whether a provider failure is transient and worth retrying (see AiRetryPolicy).
     */
    protected function isTransientAiFailure(\Throwable $e): bool
    {
        return AiRetryPolicy::isTransient($e);
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
     * Run $fn as calls for $task: the task picks the model (an OpenRouter
     * profile; for Infomaniak the fast model on Light, the vision model on
     * Vision), and quick tasks (every task but Agent) run withoutThinking().
     * The previous task is restored afterwards, also when $fn throws.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function forTask(AiTask $task, callable $fn): mixed
    {
        $previous = $this->task;
        $previousOptions = $this->requestOptions;
        $this->task = $task;

        // A response_format belongs to the call that set it: the entry schema
        // must not reach a nested call for another task (e.g. alt text written
        // by a tool while the entry is generated), which would answer in JSON.
        if ($task !== ($previous ?? AiTask::Agent)) {
            unset($this->requestOptions['response_format']);
        }

        try {
            $run = $task === AiTask::Light ? fn () => $this->withLegacyFastModel($fn) : $fn;

            return $task->isQuick() ? $this->withoutThinking($run) : $run();
        } finally {
            $this->task = $previous;
            $this->requestOptions = $previousOptions;
        }
    }

    /** The task of the call in progress; Agent outside forTask(). */
    public function currentTask(): AiTask
    {
        return $this->task ?? AiTask::Agent;
    }

    /**
     * Run $fn with the provider's fast model: forTask(AiTask::Light, $fn).
     * Kept for existing callers (target selection, block hints).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function usingFastModel(callable $fn): mixed
    {
        return $this->forTask(AiTask::Light, $fn);
    }

    /**
     * Providers with a config-based model tier (Infomaniak) swap their fast
     * model into the primary model key for the duration of $fn, then restore
     * it. A no-op when the provider has no fast tier or it resolves to the same
     * model. OpenRouter resolves its profile per call instead.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function withLegacyFastModel(callable $fn): mixed
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
     * Run $fn with extra fields merged into every provider payload it sends,
     * e.g. ['response_format' => [...]] or ['session_id' => '...'] (OpenRouter
     * sticky routing for prompt caching). Nested calls merge over the outer
     * options. Providers drop fields they do not support (Infomaniak keeps
     * only response_format). `messages` and `stream` cannot be overridden.
     *
     * @template T
     *
     * @param  array<string, mixed>  $options
     * @param  callable(): T  $fn
     * @return T
     */
    public function withRequestOptions(array $options, callable $fn): mixed
    {
        $previous = $this->requestOptions;
        $this->requestOptions = array_replace($previous, $options);

        try {
            return $fn();
        } finally {
            $this->requestOptions = $previous;
        }
    }

    /** @return array<string, mixed> */
    protected function requestOptions(): array
    {
        return $this->requestOptions;
    }

    /**
     * Run $fn with $context attached to the AiCompletionFinished event of every
     * call it makes, e.g. ['agent_session_id' => '...', 'entry_id' => '...'].
     * Nested calls merge over the outer context.
     *
     * @template T
     *
     * @param  array<string, mixed>  $context
     * @param  callable(): T  $fn
     * @return T
     */
    public function withCompletionContext(array $context, callable $fn): mixed
    {
        $previous = $this->completionContext;
        $this->completionContext = array_replace($previous, $context);

        try {
            return $fn();
        } finally {
            $this->completionContext = $previous;
        }
    }

    /** @return array<string, mixed> */
    protected function completionContext(): array
    {
        return $this->completionContext;
    }

    /** max_tokens passed to generateFromMessages() for the call in progress. */
    protected function maxTokensOverride(): ?int
    {
        return $this->maxTokensOverride;
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
     *
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
        $previousOverride = $this->maxTokensOverride;

        if ($maxTokens !== null) {
            $originalMaxTokens = config('statamic-ai-assistant.max_tokens');
            config(['statamic-ai-assistant.max_tokens' => $maxTokens]);
            $this->maxTokensOverride = $maxTokens;
        }

        try {
            if ($onToken !== null) {
                return $this->callApiStreaming($messages, $onToken);
            }

            return $this->callApi($messages);
        } finally {
            $this->maxTokensOverride = $previousOverride;
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
     * @param  callable(): void|null  $streamHeartbeat  Throttled CP keepalive while the HTTP client receives the completion body.
     * @return array<string, mixed>
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

        $system = (config('statamic-ai-assistant.prompt_preface') ? config('statamic-ai-assistant.prompt_preface').$promptAdded : '')
            ."\n\n".$this->fieldContextInstructions($context);

        return $this->quickCompletion($system, $prompt);
    }

    /**
     * Generate refactored content using the provided text and instructions.
     */
    public function generateRefactorFromPrompt(string $textToRefactor, string $prompt, array $context = []): string
    {
        return $this->refactor('prompt_refactor_preface', $textToRefactor, $prompt, $context);
    }

    /**
     * Generate HTML refactored content using the provided text and instructions.
     */
    public function generateHtmlRefactorFromPrompt(string $textToRefactor, string $prompt, array $context = []): string
    {
        return $this->refactor('prompt_html_refactor_preface', $textToRefactor, $prompt, $context);
    }

    private function refactor(string $prefaceKey, string $textToRefactor, string $prompt, array $context): string
    {
        $system = config('statamic-ai-assistant.'.$prefaceKey)."\n\n".$this->fieldContextInstructions($context);

        $combinedPrompt = "Please refactor the following text:\n\n"
            .$textToRefactor
            ."\n\nUser instructions for the refactoring: ".$prompt;

        return $this->quickCompletion($system, $combinedPrompt);
    }

    /**
     * One quick field completion (AiTask::Field, no thinking): an optional
     * system message plus the user's request.
     */
    private function quickCompletion(string $system, string $user): string
    {
        $messages = [];
        $system = trim($system);
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $user];

        $content = $this->forTask(AiTask::Field, fn () => $this->callApi($messages));

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
