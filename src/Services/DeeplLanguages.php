<?php

namespace MotionWork\StatamicAiAssistant\Services;

use DeepL\LanguageCode;
use DeepL\Translator;
use Statamic\Facades\Site;

/**
 * Statamic site locales as DeepL language codes: the configured mapping,
 * regional variants DeepL requires for targets (EN-GB, PT-BR) and strips for
 * sources.
 */
final class DeeplLanguages
{
    /** @var array<string, string> */
    private array $languageMapping;

    public function __construct()
    {
        $this->languageMapping = config('deepl.language_mapping', []);
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
    public function normalizeDeepLTargetLanguage(string $mapped): string
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
     * @see LanguageCode (e.g. ENGLISH_BRITISH is target-only)
     */
    public function mapSourceLanguageForDeepL(?string $sourceLang): ?string
    {
        if ($sourceLang === null || trim($sourceLang) === '') {
            return null;
        }

        $mapped = $this->mapLanguage($this->resolveStatamicLocale($sourceLang));
        if ($mapped === '') {
            return null;
        }

        return LanguageCode::removeRegionalVariant($mapped);
    }

    public function primaryLanguageSubtag(string $normalized): ?string
    {
        if (preg_match('/^([a-z]{2,3})(?:[-_]|$)/', $normalized, $m)) {
            return $m[1];
        }

        return null;
    }
}
