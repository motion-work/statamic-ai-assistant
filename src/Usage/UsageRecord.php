<?php

namespace MotionWork\StatamicAiAssistant\Usage;

use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;

/**
 * Turns one AiCompletionFinished event into the line UsageLog stores.
 *
 * Cost is in USD as OpenRouter reports it (`usage.cost`); Infomaniak reports
 * none, so it stays null there and only calls and tokens count.
 */
final class UsageRecord
{
    /** Context keys copied onto the record; anything else a caller attached is dropped. */
    public const CONTEXT_KEYS = ['agent_session_id', 'plan_entry_id', 'entry_id', 'translation_batch_id'];

    /** @return array<string, mixed> */
    public static function fromEvent(AiCompletionFinished $event, ?string $userId): array
    {
        $usage = $event->usage;

        $record = [
            'ts' => now()->toIso8601String(),
            'provider' => $event->provider,
            'task' => $event->task->value,
            'model' => $event->model,
            'requested' => $event->requestedModels,
            'fallback' => $event->usedFallback(),
            'prompt_tokens' => self::int($usage['prompt_tokens'] ?? null),
            'completion_tokens' => self::int($usage['completion_tokens'] ?? null),
            'cached_tokens' => self::int($usage['prompt_tokens_details']['cached_tokens'] ?? null),
            'reasoning_tokens' => self::int($usage['completion_tokens_details']['reasoning_tokens'] ?? null),
            // Web searches OpenRouter ran for this call (the web_search server tool).
            'web_searches' => self::int(
                $usage['server_tool_use_details']['web_search_requests']
                    ?? $usage['server_tool_use']['web_search_requests']
                    ?? null
            ),
            'cost' => $event->cost(),
            'duration_ms' => $event->durationMs,
            'streamed' => $event->streamed,
            'generation_id' => $event->generationId,
            'user_id' => $userId,
        ];

        foreach (self::CONTEXT_KEYS as $key) {
            $value = $event->context[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $record[$key] = (string) $value;
            }
        }

        return $record;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
