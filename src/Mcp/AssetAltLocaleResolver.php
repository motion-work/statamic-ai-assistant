<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Statamic\Facades\Site;

/**
 * Maps requested language codes (de, fr, en_US) to asset blueprint meta handles
 * (alt, alt_fr, alt_text_fr, …).
 */
final class AssetAltLocaleResolver
{
    /**
     * @param  array<int, string>  $requestedLocales
     * @param  array<int, string>  $metaFieldHandles
     * @return array{locale_handles: array<string, string>, unmapped_locales: array<int, string>, alt_fields: array<int, string>}
     */
    public function map(array $requestedLocales, array $metaFieldHandles): array
    {
        $altFields = array_values(array_filter(
            $metaFieldHandles,
            fn (string $handle) => $handle === 'alt' || str_starts_with($handle, 'alt_') || str_starts_with($handle, 'alt_text_'),
        ));

        $defaultLang = $this->languageCode((string) Site::default()->locale());
        $localeHandles = [];
        $unmapped = [];

        foreach ($requestedLocales as $locale) {
            $locale = trim($locale);
            if ($locale === '') {
                continue;
            }

            $lang = $this->languageCode($locale);
            $handle = $this->matchHandle($lang, $defaultLang, $altFields);
            if ($handle !== null) {
                $localeHandles[$locale] = $handle;
            } else {
                $unmapped[] = $locale;
            }
        }

        return [
            'locale_handles' => $localeHandles,
            'unmapped_locales' => array_values($unmapped),
            'alt_fields' => $altFields,
        ];
    }

    /**
     * @param  array<int, string>  $altFields
     */
    private function matchHandle(string $lang, string $defaultLang, array $altFields): ?string
    {
        if ($altFields === []) {
            return null;
        }

        if ($lang === $defaultLang && in_array('alt', $altFields, true)) {
            return 'alt';
        }

        $exact = ['alt_'.$lang, 'alt_text_'.$lang, 'alt_'.strtoupper($lang)];
        foreach ($exact as $candidate) {
            if (in_array($candidate, $altFields, true)) {
                return $candidate;
            }
        }

        $suffix = '_'.$lang;
        $matches = array_values(array_filter(
            $altFields,
            fn (string $h) => $h !== 'alt' && (str_ends_with($h, $suffix) || str_contains($h, '_text'.$suffix)),
        ));

        if (count($matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function languageCode(string $locale): string
    {
        $locale = str_replace('-', '_', strtolower(trim($locale)));

        return explode('_', $locale)[0] ?? $locale;
    }
}
