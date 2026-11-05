<?php

namespace MotionWork\StatamicAiAssistant\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;

/**
 * Dispatched after every successful provider call (plain, streamed and tool
 * calls; every provider). Listen to it to record usage and cost.
 *
 * `usage` is the provider's usage object as sent: prompt_tokens,
 * completion_tokens, total_tokens, and on OpenRouter also `cost` (credits),
 * `prompt_tokens_details.cached_tokens` and
 * `completion_tokens_details.reasoning_tokens`.
 *
 * `context` is what the caller attached with
 * AbstractAiService::withCompletionContext() (an agent session id, an entry id, …).
 */
final class AiCompletionFinished
{
    use Dispatchable;

    /**
     * @param  list<string>  $requestedModels  Models asked for, in fallback order.
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $provider,
        public readonly AiTask $task,
        public readonly array $requestedModels,
        public readonly ?string $model,
        public readonly ?string $generationId,
        public readonly array $usage,
        public readonly bool $streamed,
        public readonly int $durationMs,
        public readonly array $context = [],
    ) {}

    /** What the call cost in OpenRouter credits, when the provider reports it. */
    public function cost(): ?float
    {
        $cost = $this->usage['cost'] ?? null;

        return is_numeric($cost) ? (float) $cost : null;
    }

    /**
     * Whether a fallback model answered instead of the first one requested.
     * Routing suffixes (":nitro") are ignored, and a provider reporting a dated
     * id ("…-20261001") of the requested model still counts as that model.
     */
    public function usedFallback(): bool
    {
        if ($this->model === null || $this->requestedModels === []) {
            return false;
        }

        $primary = explode(':', $this->requestedModels[0])[0];

        // A preset ("@preset/…") names no model, so nothing to compare against.
        return ! str_starts_with($primary, '@') && ! str_starts_with($this->model, $primary);
    }
}
