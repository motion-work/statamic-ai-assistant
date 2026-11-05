<?php

namespace MotionWork\StatamicAiAssistant\Usage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use Statamic\Facades\User;

/**
 * Records every finished AI call in the usage log (the "AI usage" CP page).
 *
 * Recording is bookkeeping: it must never fail or slow down the AI call it
 * describes, so any error is logged and the call carries on.
 */
final class RecordAiUsage
{
    private const PRUNE_THROTTLE_KEY = 'statamic-ai-assistant.usage.pruned';

    public function __construct(private readonly UsageLog $log) {}

    public function handle(AiCompletionFinished $event): void
    {
        if (! config('statamic-ai-assistant.usage_tracking', true)) {
            return;
        }

        try {
            $this->log->append(UsageRecord::fromEvent($event, $this->userId($event)));
            $this->pruneOncePerDay();
        } catch (\Throwable $e) {
            Log::warning('AI usage could not be recorded', [
                'error' => $e->getMessage(),
                'task' => $event->task->value,
                'model' => $event->model,
            ]);
        }
    }

    /** The CP user behind the call: attached by a queued job, else the signed-in user. */
    private function userId(AiCompletionFinished $event): ?string
    {
        $fromContext = $event->context['user_id'] ?? null;
        if (is_scalar($fromContext) && (string) $fromContext !== '') {
            return (string) $fromContext;
        }

        $id = User::current()?->id();

        return $id !== null ? (string) $id : null;
    }

    private function pruneOncePerDay(): void
    {
        if (Cache::add(self::PRUNE_THROTTLE_KEY, true, now()->addDay())) {
            $this->log->prune((int) config('statamic-ai-assistant.usage_retention_months', 12));
        }
    }
}
