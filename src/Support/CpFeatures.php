<?php

namespace MotionWork\StatamicAiAssistant\Support;

use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;

/**
 * Which parts of the addon appear in the Control Panel.
 *
 * AI (agent, field buttons, AI instructions) needs the active provider's key
 * (OPENROUTER_API_KEY or INFOMANIAK_API_TOKEN, see AiProviders).
 * Translations need a DeepL key. Without one they stay available only as the
 * LLM fallback, and only when the AI provider has its key. The glossary and
 * style rules need neither: Claude reads them through the connector.
 */
class CpFeatures
{
    public static function aiEnabled(): bool
    {
        return AiProviders::hasCredentials();
    }

    public static function deeplEnabled(): bool
    {
        return trim((string) config('deepl.api_key')) !== '';
    }

    public static function glossaryEnabled(): bool
    {
        return (bool) config('statamic-ai-assistant.translations', true);
    }

    public static function translationsEnabled(): bool
    {
        if (! config('statamic-ai-assistant.translations', true)) {
            return false;
        }

        if (self::deeplEnabled()) {
            return true;
        }

        return self::aiEnabled()
            && (bool) config('statamic-ai-assistant.translation_llm_fallback', true);
    }
}
