<?php

namespace MotionWork\StatamicAiAssistant\Services;

use DeepL\DeepLClient;
use DeepL\DeepLException;
use DeepL\MultilingualGlossaryDictionaryEntries;
use DeepL\TextResult;
use DeepL\TranslateTextOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeeplService
{
    private ?DeepLClient $client = null;

    private DeeplLanguages $languages;

    private DeeplRequestPlanner $requests;

    public function __construct()
    {
        $this->languages = new DeeplLanguages;
        $this->requests = new DeeplRequestPlanner($this->languages);
    }

    /** @see DeeplUsage::getUsageForApi() */
    public function getUsageForApi(): array
    {
        return (new DeeplUsage)->getUsageForApi();
    }

    /** @see DeeplLanguages::mapLanguage() */
    public function mapLanguage(string $localeOrHandle): string
    {
        return $this->languages->mapLanguage($localeOrHandle);
    }

    private function client(): DeepLClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $apiKey = config('deepl.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('DeepL API key is not configured. Set DEEPL_API_KEY in your .env file.');
        }

        $this->client = new DeepLClient($apiKey);

        return $this->client;
    }

    public function isConfigured(): bool
    {
        return ! empty(config('deepl.api_key'));
    }

    /**
     * Whether translations go to the LLM instead: no DeepL key, and the
     * translation_llm_fallback switch is on.
     */
    public function usesLlmFallback(): bool
    {
        return ! $this->isConfigured() && (bool) config('statamic-ai-assistant.translation_llm_fallback', true);
    }

    /**
     * CP notice shown whenever a translation ran on the LLM fallback, or null
     * when DeepL handles translations.
     */
    public function llmFallbackNotice(): ?string
    {
        return $this->usesLlmFallback()
            ? __('DeepL is not configured (DEEPL_API_KEY is missing), so the AI model translated instead.')
            : null;
    }

    /**
     * Translate an array of texts in a single API call.
     * Empty strings are preserved and not sent to the API.
     *
     * @param  array<int, string>  $texts
     * @param  array<string, mixed>  $options  DeepL TranslateTextOptions (e.g. tag_handling)
     * @return array<int, string>
     */
    public function translateBatch(array $texts, ?string $sourceLang, string $targetLang, array $options = []): array
    {
        $targetLang = $this->languages->resolveStatamicLocale($targetLang);
        $mappedSource = $this->languages->mapSourceLanguageForDeepL($sourceLang);
        $mappedTarget = $this->languages->normalizeDeepLTargetLanguage($this->languages->mapLanguage($targetLang));

        $nonEmptyTexts = [];
        $indexMap = [];

        foreach ($texts as $index => $text) {
            $trimmed = trim($text);
            if ($trimmed !== '') {
                $nonEmptyTexts[] = $trimmed;
                $indexMap[] = $index;
            }
        }

        if (empty($nonEmptyTexts)) {
            return $texts;
        }

        if ($this->usesLlmFallback()) {
            $results = app(LlmTranslator::class)->translate(
                $nonEmptyTexts,
                $sourceLang !== null ? $this->languages->resolveStatamicLocale($sourceLang) : null,
                $targetLang,
                ($options[TranslateTextOptions::TAG_HANDLING] ?? null) === 'html',
            );

            $translated = $texts;
            foreach ($results as $i => $result) {
                $translated[$indexMap[$i]] = $result;
            }

            return $translated;
        }

        // Glossary + style rules configured via the CP apply to EVERY translation
        // path (entry, bulk, field, Bard, navigation sync) because they all funnel
        // through this method. Explicit caller options always win. When a glossary
        // term and a style rule both apply, the batch is split so the term is
        // enforced on the classic model while the rest keeps the style rule.
        $segments = $this->requests->planTranslationSegments($nonEmptyTexts, $options, $mappedSource, $mappedTarget);

        $translated = $texts;

        foreach ($segments as $segment) {
            $segmentTexts = array_map(fn ($pos) => $nonEmptyTexts[$pos], $segment['positions']);

            $results = $this->runTranslateSegment(
                $segmentTexts,
                $mappedSource,
                $mappedTarget,
                $segment['options'],
                $options,
            );

            foreach ($results as $i => $result) {
                $originalIndex = $indexMap[$segment['positions'][$i]];
                $translated[$originalIndex] = $result->text;
            }
        }

        return $translated;
    }

    /**
     * Translate one planned segment, with the existing glossary/style downgrade
     * and source-language auto-detect retries.
     *
     * @param  array<int, string>  $segmentTexts
     * @param  array<string, mixed>  $segmentOptions  enhanced options for this segment
     * @param  array<string, mixed>  $callerOptions  original caller options (retry fallback)
     * @return array<int, TextResult>
     */
    private function runTranslateSegment(array $segmentTexts, ?string $mappedSource, string $mappedTarget, array $segmentOptions, array $callerOptions): array
    {
        try {
            return $this->client()->translateText($segmentTexts, $mappedSource, $mappedTarget, $segmentOptions);
        } catch (DeepLException $e) {
            if ($segmentOptions !== $callerOptions && $this->shouldRetryWithoutEnhancements($e)) {
                // The account/model may not support glossaries or style rules for
                // this language pair — retry plain rather than failing the translation.
                Log::warning('[deepl] glossary/style options rejected; retrying without them', [
                    'message' => $e->getMessage(),
                ]);

                return $this->client()->translateText($segmentTexts, $mappedSource, $mappedTarget, $callerOptions);
            }

            if ($mappedSource !== null && $this->shouldRetryTranslationWithAutoDetectedSource($e)) {
                return $this->client()->translateText($segmentTexts, null, $mappedTarget, $callerOptions);
            }

            throw $e;
        }
    }

    /**
     * Translate a single text string via DeepL.
     * Pass null for $sourceLang to let DeepL detect the source language (omit source_lang in the API request).
     */
    public function translateText(string $text, ?string $sourceLang, string $targetLang, array $options = []): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $results = $this->translateBatch([$text], $sourceLang, $targetLang, $options);

        return $results[0] ?? $text;
    }

    /**
     * When DeepL returns HTTP 400 for an invalid source_lang, retry once with auto-detection.
     */
    protected function shouldRetryTranslationWithAutoDetectedSource(DeepLException $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'source_lang')
            || str_contains($msg, 'source language');
    }

    /**
     * Glossary/style errors that should downgrade to a plain translation instead of failing.
     */
    protected function shouldRetryWithoutEnhancements(DeepLException $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'glossary')
            || str_contains($msg, 'style')
            || str_contains($msg, 'not supported')
            || str_contains($msg, 'bad request');
    }

    // -----------------------------------------------------------------
    //  DeepL v3 glossary + style-rule API wrappers. Kept here so the
    //  storage services (TranslationGlossaryService / TranslationStyleRulesService)
    //  never touch the SDK client directly and stay trivially fakeable in tests.
    // -----------------------------------------------------------------

    /**
     * Create a fresh multilingual glossary and return its id. When $previousId is
     * given, the old glossary is deleted first (recreate-on-save keeps sync trivial;
     * CP glossaries are small).
     *
     * @param  array<int, array{source_lang: string, target_lang: string, entries: array<string, string>}>  $dictionaries
     */
    public function createGlossaryOnDeepL(string $name, array $dictionaries, ?string $previousId = null): string
    {
        if ($previousId !== null) {
            $this->deleteGlossaryOnDeepL($previousId);
        }

        $sdkDictionaries = array_map(
            fn (array $d) => new MultilingualGlossaryDictionaryEntries($d['source_lang'], $d['target_lang'], $d['entries']),
            $dictionaries,
        );

        return (string) $this->client()->createMultilingualGlossary($name, $sdkDictionaries)->glossaryId;
    }

    /**
     * Delete a glossary; a glossary that no longer exists on DeepL is not an error.
     */
    public function deleteGlossaryOnDeepL(string $glossaryId): void
    {
        try {
            $this->client()->deleteMultilingualGlossary($glossaryId);
        } catch (DeepLException $e) {
            Log::info('[deepl] delete glossary skipped', ['id' => $glossaryId, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Create a style rule with one or more custom instructions for a target
     * language and return its id. Deletion of superseded/duplicate rules is
     * orchestrated by TranslationStyleRulesService (reconcile-by-name), so this
     * method only creates.
     *
     * @param  array<int, string>  $instructions  one DeepL custom instruction per entry
     */
    public function createStyleRuleOnDeepL(string $name, string $language, array $instructions): string
    {
        $customInstructions = [];

        foreach (array_values($instructions) as $i => $prompt) {
            $prompt = trim((string) $prompt);

            if ($prompt !== '') {
                $customInstructions[] = ['label' => 'CMS style '.($i + 1), 'prompt' => $prompt];
            }
        }

        $styleRule = $this->client()->createStyleRule($name, $language, null, $customInstructions);

        return (string) $styleRule->styleId;
    }

    /**
     * List all style rules on the DeepL account, so the storage service can
     * reconcile duplicates/orphans it created in earlier recreate-on-save runs.
     *
     * @return array<int, array{style_id: string, name: string, language: string}>
     */
    public function listStyleRulesOnDeepL(): array
    {
        $out = [];

        foreach ($this->client()->getAllStyleRules() as $rule) {
            $out[] = [
                'style_id' => (string) $rule->styleId,
                'name' => (string) $rule->name,
                'language' => strtolower((string) $rule->language),
            ];
        }

        return $out;
    }

    /**
     * Delete a style rule; a rule that no longer exists on DeepL is not an error.
     */
    public function deleteStyleRuleOnDeepL(string $styleRuleId): void
    {
        try {
            $this->client()->deleteStyleRule($styleRuleId);
        } catch (DeepLException $e) {
            Log::info('[deepl] delete style rule skipped', ['id' => $styleRuleId, 'message' => $e->getMessage()]);
        }
    }
}
