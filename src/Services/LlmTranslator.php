<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\JsonObjectExtractor;
use Illuminate\Support\Facades\Log;

/**
 * Translation through the configured LLM, used by DeeplService when no DeepL
 * API key is set (translation_llm_fallback). Same contract as DeepL: a list of
 * texts in, the translated list out, same order and length.
 *
 * The CP glossary and style rules (local YAML) are applied through the prompt:
 * glossary terms found in a chunk become hard rules, style instructions for the
 * target language are passed through verbatim.
 */
class LlmTranslator
{
    /** Keep one request well inside model output limits. */
    private const CHUNK_MAX_CHARS = 6000;

    private const CHUNK_MAX_TEXTS = 40;

    public function __construct(
        private AbstractAiService $ai,
        private TranslationGlossaryService $glossary,
        private TranslationStyleRulesService $styleRules,
    ) {}

    /**
     * @param  array<int, string>  $texts  non-empty texts, list-indexed
     * @param  string|null  $sourceLocale  Statamic locale (e.g. de-CH), null to auto-detect
     * @param  string  $targetLocale  Statamic locale (e.g. en)
     * @param  bool  $html  texts are HTML (Bard): keep markup, translate text nodes only
     * @return array<int, string>
     */
    public function translate(array $texts, ?string $sourceLocale, string $targetLocale, bool $html = false): array
    {
        $translated = [];

        foreach ($this->chunk(array_values($texts)) as $chunk) {
            array_push($translated, ...$this->translateChunk($chunk, $sourceLocale, $targetLocale, $html));
        }

        return $translated;
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, string>>
     */
    private function chunk(array $texts): array
    {
        $chunks = [];
        $current = [];
        $chars = 0;

        foreach ($texts as $text) {
            $length = mb_strlen($text);

            if ($current !== [] && ($chars + $length > self::CHUNK_MAX_CHARS || count($current) >= self::CHUNK_MAX_TEXTS)) {
                $chunks[] = $current;
                $current = [];
                $chars = 0;
            }

            $current[] = $text;
            $chars += $length;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, string>
     */
    private function translateChunk(array $texts, ?string $sourceLocale, string $targetLocale, bool $html): array
    {
        $result = $this->requestTranslations($texts, $sourceLocale, $targetLocale, $html);

        if ($result !== null) {
            return $result;
        }

        if (count($texts) > 1) {
            // A malformed or short batch reply: retry one text at a time so a
            // single tricky string cannot fail the whole entry.
            Log::warning('[llm-translator] batch reply unusable; retrying texts one by one', ['count' => count($texts)]);

            return array_merge(...array_map(
                fn (string $text) => $this->translateChunk([$text], $sourceLocale, $targetLocale, $html),
                $texts,
            ));
        }

        throw new \RuntimeException(__('The AI model returned no usable translation.'));
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, string>|null  null when the reply is not a JSON list of the right length
     */
    private function requestTranslations(array $texts, ?string $sourceLocale, string $targetLocale, bool $html): ?array
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($texts, $sourceLocale, $targetLocale, $html)],
            ['role' => 'user', 'content' => json_encode(['texts' => $texts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ];

        $chars = array_sum(array_map('mb_strlen', $texts));
        $maxTokens = min(16000, 1000 + $chars);

        $raw = $this->ai->withoutThinking(fn () => $this->ai->generateFromMessages($messages, $maxTokens));

        $json = JsonObjectExtractor::firstObject($raw);
        $decoded = $json !== null ? json_decode($json, true) : null;
        $translations = is_array($decoded) ? ($decoded['translations'] ?? null) : null;

        if (! is_array($translations) || count($translations) !== count($texts)) {
            return null;
        }

        $out = [];

        foreach (array_values($translations) as $translation) {
            if (! is_string($translation)) {
                return null;
            }

            $out[] = $translation;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $texts
     */
    private function systemPrompt(array $texts, ?string $sourceLocale, string $targetLocale, bool $html): string
    {
        $source = $sourceLocale !== null ? $this->languageName($sourceLocale) : 'the language of each text (detect it)';
        $target = $this->languageName($targetLocale);

        $lines = [
            "You are a professional translator. Translate every text from {$source} into {$target}.",
            'Input is a JSON object {"texts": [...]}. Reply with ONLY a JSON object {"translations": [...]}: exactly '.count($texts).' strings, same order, one translation per input text. No commentary, no markdown fences.',
            'Translate meaning and tone naturally for a website, not word for word. Keep proper names, brand names, URLs, e-mail addresses and placeholders (e.g. :name, {{ value }}) unchanged.',
        ];

        $lines[] = $html
            ? 'The texts are HTML. Keep every tag, attribute and entity exactly as is and translate only the human-readable text between tags.'
            : 'The texts are plain text. Do not add HTML or markdown, and do not escape characters as HTML entities.';

        if ($this->isSwissGerman($targetLocale)) {
            $lines[] = 'Use Swiss Standard German spelling: always write "ss", never "ß".';
        }

        $glossary = $this->glossaryRules($texts, $sourceLocale, $targetLocale);
        if ($glossary !== []) {
            $lines[] = "Glossary — always translate these terms exactly like this:\n".implode("\n", $glossary);
        }

        $style = $this->styleRules->rules()[$this->base($targetLocale)]['instructions'] ?? [];
        if ($style !== []) {
            $lines[] = "Style rules for {$target}:\n".implode("\n", array_map(fn ($rule) => '- '.$rule, $style));
        }

        return implode("\n\n", $lines);
    }

    /**
     * Glossary pairs whose source term occurs in this chunk.
     *
     * @param  array<int, string>  $texts
     * @return array<int, string>
     */
    private function glossaryRules(array $texts, ?string $sourceLocale, string $targetLocale): array
    {
        $targetBase = $this->base($targetLocale);
        $sourceBase = $sourceLocale !== null ? $this->base($sourceLocale) : null;
        $haystack = mb_strtolower(implode("\n", $texts));
        $rules = [];

        try {
            $entries = $this->glossary->entries();
        } catch (\Throwable $e) {
            Log::warning('[llm-translator] glossary unavailable', ['message' => $e->getMessage()]);

            return [];
        }

        foreach ($entries as $entry) {
            $targetTerm = $entry['terms'][$targetBase] ?? '';
            if ($targetTerm === '') {
                continue;
            }

            foreach ($entry['terms'] as $lang => $term) {
                if ($lang === $targetBase || $term === '' || ($sourceBase !== null && $lang !== $sourceBase)) {
                    continue;
                }

                if (str_contains($haystack, mb_strtolower($term))) {
                    $rules["{$term}|{$targetTerm}"] = "- \"{$term}\" → \"{$targetTerm}\"";
                }
            }
        }

        return array_values($rules);
    }

    private function languageName(string $locale): string
    {
        $normalized = str_replace('-', '_', $locale);

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName($normalized, 'en');
            if (is_string($name) && $name !== '' && strcasecmp($name, $normalized) !== 0) {
                return "{$name} ({$locale})";
            }
        }

        return $locale;
    }

    private function isSwissGerman(string $locale): bool
    {
        return strtolower(str_replace('_', '-', $locale)) === 'de-ch';
    }

    private function base(string $locale): string
    {
        return strtolower((string) preg_replace('/[-_].*$/', '', trim($locale)));
    }
}
