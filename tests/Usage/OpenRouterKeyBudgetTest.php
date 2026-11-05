<?php

namespace MotionWork\StatamicAiAssistant\Tests\Usage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Usage\OpenRouterKeyBudget;

/**
 * The OpenRouter key's spending limit, read from GET /key and cached.
 */
class OpenRouterKeyBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'statamic-ai-assistant.provider_name' => 'auto',
            'statamic-ai-assistant.openrouter_api_key' => 'sk-or-test',
            'statamic-ai-assistant.openrouter_base_url' => 'https://openrouter.ai/api/v1',
        ]);
    }

    public function test_the_key_limit_is_read_once_and_cached(): void
    {
        Http::fake(['openrouter.ai/api/v1/key' => Http::response(['data' => [
            'label' => 'client-site',
            'limit' => 5,
            'limit_remaining' => 4.25,
            'limit_reset' => 'monthly',
            'usage_monthly' => 0.75,
            'is_management_key' => false,
        ]])]);

        $budget = new OpenRouterKeyBudget;
        $first = $budget->current();
        $second = $budget->current();

        $this->assertSame(4.25, $first['limit_remaining']);
        $this->assertSame('monthly', $first['limit_reset']);
        $this->assertArrayNotHasKey('is_management_key', $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-or-test'));
    }

    public function test_a_failed_lookup_returns_null_and_is_not_cached(): void
    {
        Http::fakeSequence('openrouter.ai/api/v1/key')
            ->push(['error' => ['code' => 401, 'message' => 'No auth']], 401)
            ->push(['data' => ['limit' => null, 'limit_remaining' => null]]);

        $budget = new OpenRouterKeyBudget;

        $this->assertNull($budget->current());
        $this->assertSame(['limit' => null, 'limit_remaining' => null], $budget->current());
    }

    public function test_it_does_not_apply_without_openrouter(): void
    {
        Http::fake();
        config(['statamic-ai-assistant.provider_name' => 'infomaniak']);

        $budget = new OpenRouterKeyBudget;

        $this->assertFalse($budget->applies());
        $this->assertNull($budget->current());
        Http::assertNothingSent();
    }
}
