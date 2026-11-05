<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;
use MotionWork\StatamicAiAssistant\Services\InfomaniakService;
use MotionWork\StatamicAiAssistant\Services\OpenRouterService;
use MotionWork\StatamicAiAssistant\Support\CpFeatures;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * "auto" picks OpenRouter once its key is set and Infomaniak otherwise; a
 * named provider is always honoured, and the AI features follow that
 * provider's key.
 */
class AiProvidersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic-ai-assistant.provider_name' => 'auto',
            'statamic-ai-assistant.openrouter_api_key' => null,
            'statamic-ai-assistant.infomaniak_api_token' => null,
        ]);
    }

    public function test_auto_keeps_infomaniak_until_an_openrouter_key_is_set(): void
    {
        config(['statamic-ai-assistant.infomaniak_api_token' => 'token']);
        $this->assertSame(AiProviders::INFOMANIAK, AiProviders::active());
        $this->assertInstanceOf(InfomaniakService::class, AiProviders::make());

        config(['statamic-ai-assistant.openrouter_api_key' => 'sk-or-1']);
        $this->assertSame(AiProviders::OPENROUTER, AiProviders::active());
        $this->assertInstanceOf(OpenRouterService::class, AiProviders::make());
    }

    public function test_a_named_provider_wins_over_auto(): void
    {
        config(['statamic-ai-assistant.openrouter_api_key' => 'sk-or-1']);

        config(['statamic-ai-assistant.provider_name' => 'infomaniak']);
        $this->assertInstanceOf(InfomaniakService::class, AiProviders::make());

        config(['statamic-ai-assistant.provider_name' => 'openrouter']);
        $this->assertInstanceOf(OpenRouterService::class, AiProviders::make());
    }

    public function test_the_removed_groq_name_falls_back_to_auto(): void
    {
        config(['statamic-ai-assistant.provider_name' => 'groq']);
        config(['statamic-ai-assistant.infomaniak_api_token' => 'token']);
        $this->assertSame(AiProviders::INFOMANIAK, AiProviders::active());

        config(['statamic-ai-assistant.openrouter_api_key' => 'sk-or-1']);
        $this->assertSame(AiProviders::OPENROUTER, AiProviders::active());
    }

    public function test_the_container_resolves_the_active_provider(): void
    {
        config(['statamic-ai-assistant.openrouter_api_key' => 'sk-or-1']);
        $this->app->forgetInstance(AbstractAiService::class);

        $this->assertInstanceOf(OpenRouterService::class, app(AbstractAiService::class));
    }

    public function test_ai_features_follow_the_active_providers_key(): void
    {
        $this->assertFalse(CpFeatures::aiEnabled());

        config(['statamic-ai-assistant.openrouter_api_key' => 'sk-or-1']);
        $this->assertTrue(CpFeatures::aiEnabled(), 'auto picks OpenRouter, which has its key');

        config(['statamic-ai-assistant.provider_name' => 'infomaniak']);
        $this->assertFalse(CpFeatures::aiEnabled(), 'Infomaniak is named but has no token');

        config(['statamic-ai-assistant.infomaniak_api_token' => 'token']);
        $this->assertTrue(CpFeatures::aiEnabled());
    }
}
