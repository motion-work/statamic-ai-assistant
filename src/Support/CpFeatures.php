<?php

namespace MotionWork\StatamicAiAssistant\Support;

/**
 * Which parts of the addon appear in the Control Panel.
 *
 * AI (agent, field buttons, AI instructions) needs INFOMANIAK_API_TOKEN.
 * Translations need a DeepL key. Without one they stay available only as the
 * LLM fallback, and only when the Infomaniak token is set. The glossary and
 * style rules need neither: Claude reads them through the connector.
 */
class CpFeatures
{
    public static function aiEnabled(): bool
    {
        return trim((string) config('statamic-ai-assistant.infomaniak_api_token')) !== '';
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
