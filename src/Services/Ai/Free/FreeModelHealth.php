<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai\Free;

use Illuminate\Support\Facades\Cache;

/**
 * Which free models to skip for a while. A busy or failing model (429, 5xx,
 * timeout, skipped by OpenRouter's fallback) is moved down for busy_minutes;
 * one blocked by the privacy setting ("No endpoints found matching your data
 * policy") for policy_hours, but only under that policy, so allowing training
 * makes it usable again at once. A success clears the busy mark.
 */
final class FreeModelHealth
{
    private const PREFIX = 'statamic-ai-assistant.free-models.';

    public function busy(string $model): void
    {
        Cache::put($this->busyKey($model), true, now()->addMinutes(
            (int) config('statamic-ai-assistant.openrouter_free_models.busy_minutes', 15),
        ));
    }

    public function blocked(string $model, string $policy): void
    {
        Cache::put($this->policyKey($model, $policy), true, now()->addHours(
            (int) config('statamic-ai-assistant.openrouter_free_models.policy_hours', 24),
        ));
    }

    public function ok(string $model): void
    {
        Cache::forget($this->busyKey($model));
    }

    public function usable(string $model, string $policy): bool
    {
        return ! Cache::has($this->busyKey($model)) && ! Cache::has($this->policyKey($model, $policy));
    }

    private function busyKey(string $model): string
    {
        return self::PREFIX.'busy.'.md5($model);
    }

    private function policyKey(string $model, string $policy): string
    {
        return self::PREFIX.'policy.'.$policy.'.'.md5($model);
    }
}
