<?php

namespace MotionWork\StatamicAiAssistant\Usage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;

/**
 * The spending limit of this site's OpenRouter key (GET /api/v1/key), for the
 * "AI usage" page. Cached for five minutes; a failed lookup is not cached, so
 * the page tries again on the next load.
 */
final class OpenRouterKeyBudget
{
    private const CACHE_KEY = 'statamic-ai-assistant.openrouter-key-budget';

    private const CACHE_SECONDS = 300;

    private const FIELDS = ['label', 'limit', 'limit_remaining', 'limit_reset', 'usage', 'usage_daily', 'usage_weekly', 'usage_monthly', 'is_free_tier'];

    /** Whether there is an OpenRouter key to ask about. */
    public function applies(): bool
    {
        return AiProviders::active() === AiProviders::OPENROUTER && $this->apiKey() !== '';
    }

    /**
     * The key's limit and spend, or null when OpenRouter is not in use or the
     * lookup failed.
     *
     * @return array<string, mixed>|null
     */
    public function current(): ?array
    {
        if (! $this->applies()) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $budget = $this->fetch();
        if ($budget !== null) {
            Cache::put(self::CACHE_KEY, $budget, self::CACHE_SECONDS);
        }

        return $budget;
    }

    /** @return array<string, mixed>|null */
    private function fetch(): ?array
    {
        $url = rtrim((string) config('statamic-ai-assistant.openrouter_base_url', 'https://openrouter.ai/api/v1'), '/').'/key';

        try {
            $response = Http::timeout(5)->withToken($this->apiKey())->acceptJson()->get($url);
        } catch (\Throwable $e) {
            Log::notice('OpenRouter key budget lookup failed', ['error' => $e->getMessage()]);

            return null;
        }

        $data = $response->successful() ? $response->json('data') : null;
        if (! is_array($data)) {
            Log::notice('OpenRouter key budget lookup failed', ['status' => $response->status()]);

            return null;
        }

        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    private function apiKey(): string
    {
        return trim((string) config('statamic-ai-assistant.openrouter_api_key', ''));
    }
}
