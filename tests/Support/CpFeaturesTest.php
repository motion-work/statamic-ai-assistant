<?php

namespace MotionWork\StatamicAiAssistant\Tests\Support;

use MotionWork\StatamicAiAssistant\Support\CpFeatures;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

class CpFeaturesTest extends TestCase
{
    public function test_ai_is_hidden_without_an_infomaniak_token(): void
    {
        config(['statamic-ai-assistant.infomaniak_api_token' => null]);

        $this->assertFalse(CpFeatures::aiEnabled());

        config(['statamic-ai-assistant.infomaniak_api_token' => '   ']);

        $this->assertFalse(CpFeatures::aiEnabled());
    }

    public function test_ai_is_visible_when_the_infomaniak_token_is_set(): void
    {
        config(['statamic-ai-assistant.infomaniak_api_token' => 'token']);

        $this->assertTrue(CpFeatures::aiEnabled());
    }

    public function test_without_a_token_only_deepl_translations_stay_visible(): void
    {
        config([
            'statamic-ai-assistant.infomaniak_api_token' => null,
            'statamic-ai-assistant.translations' => true,
            'statamic-ai-assistant.translation_llm_fallback' => true,
            'deepl.api_key' => null,
        ]);

        $this->assertFalse(CpFeatures::translationsEnabled());

        config(['deepl.api_key' => 'deepl-key']);

        $this->assertTrue(CpFeatures::translationsEnabled());
    }

    public function test_llm_translation_fallback_stays_when_the_token_is_set(): void
    {
        config([
            'statamic-ai-assistant.infomaniak_api_token' => 'token',
            'statamic-ai-assistant.translations' => true,
            'statamic-ai-assistant.translation_llm_fallback' => true,
            'deepl.api_key' => null,
        ]);

        $this->assertTrue(CpFeatures::translationsEnabled());

        config(['statamic-ai-assistant.translation_llm_fallback' => false]);

        $this->assertFalse(CpFeatures::translationsEnabled());
    }

    public function test_the_translations_switch_hides_them_even_with_a_deepl_key(): void
    {
        config([
            'statamic-ai-assistant.translations' => false,
            'deepl.api_key' => 'deepl-key',
        ]);

        $this->assertFalse(CpFeatures::translationsEnabled());
    }
}
