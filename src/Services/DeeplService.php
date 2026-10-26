<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use DeepL\DeepLClient;
use DeepL\DeepLException;
use DeepL\LanguageCode;
use DeepL\MultilingualGlossaryDictionaryEntries;
use DeepL\TranslateTextOptions;
use DeepL\Translator;
use DeepL\TranslatorOptions;
use DeepL\Usage;
use DeepL\UsageDetail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Site;

class DeeplService
{
    /**
     * DeepL may return very large character_limit / document_limit values (e.g. 1e12) for
     * Pro keys with no hard cap or internal metering; showing that raw limit in the CP is
     * misleading, so treat it like unlimited for UI purposes.
     */
    private const USAGE_LIMIT_EFFECTIVELY_UNLIMITED = 1_000_000_000_000;

    private ?DeepLClient $client = null;

    /** @var array<string, string> */
    private array $languageMapping;

    public function __construct()
    {
        $this->languageMapping = config('deepl.language_mapping', []);
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
        $targetLang = $this->resolveStatamicLocale($targetLang);
        $mappedSource = $this->mapSourceLanguageForDeepL($sourceLang);
        $mappedTarget = $this->normalizeDeepLTargetLanguage($this->mapLanguage($targetLang));

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

        // Glossary + style rules configured via the CP apply to EVERY translation
        // path (entry, bulk, field, Bard, navigation sync) because they all funnel
        // through this method. Explicit caller options always win. When a glossary
        // term and a style rule both apply, the batch is split so the term is
        // enforced on the classic model while the rest keeps the style rule.
        $segments = $this->planTranslationSegments($nonEmptyTexts, $options, $mappedSource, $mappedTarget);

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
     * @return array<int, \DeepL\TextResult>
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
     * Decide how to split the batch across glossary/style translation profiles.
     *
     * Returns one or more segments, each with its own DeepL options and the list
     * of positions (into $nonEmptyTexts) it covers. The common case is a single
     * segment; the batch is only split when a glossary term AND a style rule both
     * apply, because DeepL cannot enforce a glossary ("hard", classic model) while
     * also applying a style rule (next-gen model, soft glossary) in one request.
     *
     * @param  array<int, string>  $nonEmptyTexts
     * @param  array<string, mixed>  $options
     * @return array<int, array{options: array<string, mixed>, positions: array<int, int>}>
     */
    private function planTranslationSegments(array $nonEmptyTexts, array $options, ?string $mappedSource, string $mappedTarget): array
    {
        $sourceBase = $mappedSource !== null
            ? $this->primaryLanguageSubtag(strtolower(str_replace('_', '-', $mappedSource)))
            : null;
        $targetBase = $this->primaryLanguageSubtag(strtolower(str_replace('_', '-', $mappedTarget)));

        $glossaryId = null;
        $styleId = null;

        if ($targetBase !== null) {
            try {
                if (! array_key_exists('glossary', $options) && $sourceBase !== null) {
                    $glossaryId = app(TranslationGlossaryService::class)->glossaryIdFor($sourceBase, $targetBase);
                }
                if (! array_key_exists('style_id', $options)) {
                    $styleId = app(TranslationStyleRulesService::class)->styleIdFor($targetBase);
                }
            } catch (\Throwable $e) {
                Log::warning('[deepl] could not resolve glossary/style options', ['message' => $e->getMessage()]);
            }
        }

        $allPositions = array_keys($nonEmptyTexts);

        // No auto glossary+style conflict to resolve → one segment with whatever
        // single-request enhancements apply (glossary hard, or style, or neither).
        if ($glossaryId === null || $styleId === null || ! config('statamic-ai-assistant.prefer_glossary_over_style', true)) {
            return [[
                'options' => $this->buildSingleRequestOptions($options, $glossaryId, $styleId),
                'positions' => $allPositions,
            ]];
        }

        // Both a glossary and a style rule apply. Split by whether each text
        // actually carries a glossary term.
        $terms = app(TranslationGlossaryService::class)->sourceTermsFor((string) $sourceBase, $targetBase);

        $termPositions = [];
        $stylePositions = [];

        foreach ($nonEmptyTexts as $pos => $text) {
            if ($this->textContainsGlossaryTerm($text, $terms)) {
                $termPositions[] = $pos;
            } else {
                $stylePositions[] = $pos;
            }
        }

        if ($termPositions === []) {
            // Nothing to enforce — keep everything on the style rule.
            return [[
                'options' => $this->withStyle($options, $styleId, $glossaryId),
                'positions' => $allPositions,
            ]];
        }

        $segments = [[
            'options' => $this->withHardGlossary($options, $glossaryId),
            'positions' => $termPositions,
        ]];

        if ($stylePositions !== []) {
            $segments[] = [
                'options' => $this->withStyle($options, $styleId, $glossaryId),
                'positions' => $stylePositions,
            ];
        }

        return $segments;
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function textContainsGlossaryTerm(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term !== '' && mb_stripos($text, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Options for a single request (no split): a glossary is enforced on the
     * classic model; a lone style rule is applied on the next-gen model.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildSingleRequestOptions(array $options, ?string $glossaryId, ?string $styleId): array
    {
        if ($glossaryId !== null) {
            $options = $this->withHardGlossary($options, $glossaryId);
        }

        if ($styleId !== null && ! array_key_exists('style_id', $options)) {
            $options[TranslateTextOptions::STYLE_ID] = $styleId;
        }

        return $options;
    }

    /**
     * Attach the glossary and pin the classic model so DeepL enforces the terms
     * ("hard" glossary). Removes any auto style_id — style forces the next-gen
     * model, which downgrades glossaries to soft hints.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withHardGlossary(array $options, string $glossaryId): array
    {
        if (! array_key_exists('glossary', $options)) {
            $options[TranslateTextOptions::GLOSSARY] = $glossaryId;
        }

        if (! array_key_exists('model_type', $options)) {
            $options[TranslateTextOptions::MODEL_TYPE] = 'latency_optimized';
        }

        return $options;
    }

    /**
     * Attach the style rule (next-gen model). A glossary can ride along but only
     * as a soft hint on this model.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withStyle(array $options, string $styleId, ?string $glossaryId): array
    {
        if (! array_key_exists('style_id', $options)) {
            $options[TranslateTextOptions::STYLE_ID] = $styleId;
        }

        if ($glossaryId !== null && ! array_key_exists('glossary', $options)) {
            $options[TranslateTextOptions::GLOSSARY] = $glossaryId;
        }

        return $options;
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
     * Current billing-period DeepL API usage (characters, documents) for display in the CP.
     * Uses GET /v2/usage. Pro API returns account totals plus api_key_character_* for the key used;
     * we prefer key-level numbers for the main meter when present.
     *
     * @return array{
     *   character: ?array,
     *   character_account: ?array,
     *   character_is_api_key_scoped: bool,
     *   document: ?array,
     *   team_document: ?array,
     *   any_limit_reached: bool,
     *   billing: array{period_start?: string, period_end?: string},
     *   estimated_cost: array{
     *     enabled: bool,
     *     base_fee_eur?: float,
     *     per_million_chars_eur?: float,
     *     account_character_count?: int,
     *     estimated_total_eur?: float
     *   }
     * }
     */
    public function getUsageForApi(): array
    {
        $apiKey = config('deepl.api_key');
        if (empty($apiKey)) {
            throw new \RuntimeException('DeepL API key is not configured. Set DEEPL_API_KEY in your .env file.');
        }

        $body = $this->fetchUsageResponseBody($apiKey);
        $usage = new Usage($body);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        // Per DeepL: start_time / end_time are only in some Pro responses.
        // https://developers.deepl.com/api-reference/usage-and-quota
        $billing = [];
        $endRaw = $decoded['end_time'] ?? null;
        $startRaw = $decoded['start_time'] ?? null;
        if (is_string($endRaw) && trim($endRaw) !== '') {
            $billing['period_end'] = trim($endRaw);
        }
        if (is_string($startRaw) && trim($startRaw) !== '') {
            $billing['period_start'] = trim($startRaw);
        }

        $primaryChar = null;
        $fromApiKey = isset($decoded['api_key_character_count'], $decoded['api_key_character_limit'])
            && is_numeric($decoded['api_key_character_count'])
            && is_numeric($decoded['api_key_character_limit']);
        if ($fromApiKey) {
            $primaryChar = $this->serializeUsagePair(
                (int) $decoded['api_key_character_count'],
                (int) $decoded['api_key_character_limit']
            );
        }
        if ($primaryChar === null && $usage->character !== null) {
            $primaryChar = $this->serializeUsageDetail($usage->character);
        }

        $accountChar = null;
        if (isset($decoded['character_count'], $decoded['character_limit'])
            && is_numeric($decoded['character_count'])
            && is_numeric($decoded['character_limit'])) {
            $accountChar = $this->serializeUsagePair(
                (int) $decoded['character_count'],
                (int) $decoded['character_limit']
            );
        }

        $characterIsApiKeyScoped = $fromApiKey && $accountChar !== null;

        $anyReached = $usage->anyLimitReached();
        if ($primaryChar !== null && ! $primaryChar['unlimited']
            && isset($primaryChar['limit']) && $primaryChar['limit'] !== null
            && $primaryChar['count'] >= $primaryChar['limit']) {
            $anyReached = true;
        }

        $estimatedCost = ['enabled' => false];
        if (config('deepl.estimated_cost_enabled', true)
            && isset($decoded['character_count'])
            && is_numeric($decoded['character_count'])) {
            $accountCount = (int) $decoded['character_count'];
            $base = (float) config('deepl.estimated_monthly_base_fee_eur', 4.99);
            $perM = (float) config('deepl.estimated_per_million_chars_eur', 20);
            $variable = ($accountCount / 1_000_000.0) * $perM;
            $estimatedCost = [
                'enabled' => true,
                'base_fee_eur' => $base,
                'per_million_chars_eur' => $perM,
                'account_character_count' => $accountCount,
                'estimated_total_eur' => round($base + $variable, 2),
            ];
        }

        return [
            'character' => $primaryChar,
            'character_account' => $characterIsApiKeyScoped ? $accountChar : null,
            'character_is_api_key_scoped' => $characterIsApiKeyScoped,
            'document' => $this->serializeUsageDetail($usage->document),
            'team_document' => $this->serializeUsageDetail($usage->teamDocument),
            'any_limit_reached' => $anyReached,
            'billing' => $billing,
            'estimated_cost' => $estimatedCost,
        ];
    }

    /**
     * Raw JSON body from GET /v2/usage (same host as the DeepL PHP client uses for free vs Pro keys).
     */
    protected function fetchUsageResponseBody(string $apiKey): string
    {
        $baseUrl = Translator::isAuthKeyFreeAccount($apiKey)
            ? TranslatorOptions::DEFAULT_SERVER_URL_FREE
            : TranslatorOptions::DEFAULT_SERVER_URL;

        $response = Http::withHeaders([
            'Authorization' => 'DeepL-Auth-Key '.$apiKey,
            'User-Agent' => 'BoldWebStatamicAiAssistant/1.0',
        ])->timeout(20)->get($baseUrl.'/v2/usage');

        if (! $response->successful()) {
            throw new \RuntimeException('DeepL usage request failed (HTTP '.$response->status().').');
        }

        return $response->body();
    }

    /**
     * @return array{count: int, limit: ?int, unlimited: bool, percent: ?int}|null
     */
    protected function serializeUsageDetail(?UsageDetail $detail): ?array
    {
        if ($detail === null) {
            return null;
        }

        return $this->serializeUsagePair($detail->count, $detail->limit);
    }

    /**
     * @return array{count: int, limit: ?int, unlimited: bool, percent: ?int}|null
     */
    protected function serializeUsagePair(int $count, int $limit): ?array
    {
        if ($limit <= 0 || $limit >= self::USAGE_LIMIT_EFFECTIVELY_UNLIMITED) {
            return [
                'count' => $count,
                'limit' => null,
                'unlimited' => true,
                'percent' => null,
            ];
        }

        return [
            'count' => $count,
            'limit' => $limit,
            'unlimited' => false,
            'percent' => min(100, (int) round(($count / $limit) * 100)),
        ];
    }

    /**
     * Turn a site handle (e.g. default, de_ch) into a Statamic locale (e.g. en, de-CH) when possible.
     */
    public function resolveStatamicLocale(string $handleOrLocale): string
    {
        $trimmed = trim($handleOrLocale);
        if ($trimmed === '') {
            return $trimmed;
        }

        $lower = strtolower($trimmed);
        $normalizedLocale = strtolower(str_replace('_', '-', $trimmed));

        $site = Site::all()->first(fn ($s) => strtolower($s->handle()) === $lower);
        if ($site) {
            return $site->locale();
        }

        $site = Site::all()->first(function ($s) use ($normalizedLocale) {
            $loc = strtolower(str_replace('_', '-', $s->locale()));

            return $loc === $normalizedLocale;
        });
        if ($site) {
            return $site->locale();
        }

        $site = Site::all()->first(function ($s) use ($normalizedLocale) {
            $lang = strtolower(str_replace('_', '-', (string) $s->lang()));

            return $lang === $normalizedLocale;
        });
        if ($site) {
            return $site->locale();
        }

        return $trimmed;
    }

    /**
     * Map Statamic locale / site keys to DeepL language codes.
     * Handles regional tags (de-CH), underscores (de_CH), and site handles via config.
     */
    public function mapLanguage(string $localeOrHandle): string
    {
        $normalized = strtolower(str_replace('_', '-', trim($localeOrHandle)));

        $candidates = array_unique(array_filter([
            $localeOrHandle,
            $normalized,
            $this->primaryLanguageSubtag($normalized),
        ]));

        foreach ($candidates as $key) {
            if ($key !== null && $key !== '' && isset($this->languageMapping[$key])) {
                return $this->languageMapping[$key];
            }
        }

        $primary = $this->primaryLanguageSubtag($normalized);
        if ($primary !== null && $primary !== '') {
            return $primary;
        }

        return $localeOrHandle;
    }

    /**
     * DeepL rejects ambiguous target codes: use en-GB/en-US and pt-PT/pt-BR (see Translator::buildBodyParams).
     */
    protected function normalizeDeepLTargetLanguage(string $mapped): string
    {
        $lower = strtolower(str_replace('_', '-', trim($mapped)));

        if ($lower === 'en') {
            return (string) config('deepl.english_target', 'en-GB');
        }

        if ($lower === 'pt') {
            return (string) config('deepl.portuguese_target', 'pt-PT');
        }

        return $mapped;
    }

    /**
     * Resolve and map source language for DeepL, or null for automatic detection.
     *
     * `language_mapping` may use target-only codes (e.g. en-GB, pt-PT). DeepL rejects those for
     * `source_lang` — only base codes are valid (en, pt, de, …). Strip regional variants for sources.
     *
     * @see \DeepL\LanguageCode (e.g. ENGLISH_BRITISH is target-only)
     */
    protected function mapSourceLanguageForDeepL(?string $sourceLang): ?string
    {
        if ($sourceLang === null || trim($sourceLang) === '') {
            return null;
        }

        $mapped = $this->mapLanguage($this->resolveStatamicLocale($sourceLang));
        if ($mapped === '') {
            return null;
        }

        try {
            return LanguageCode::removeRegionalVariant($mapped);
        } catch (DeepLException) {
            return null;
        }
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

    protected function primaryLanguageSubtag(string $normalized): ?string
    {
        if (preg_match('/^([a-z]{2,3})(?:[-_]|$)/', $normalized, $m)) {
            return $m[1];
        }

        return null;
    }
}
