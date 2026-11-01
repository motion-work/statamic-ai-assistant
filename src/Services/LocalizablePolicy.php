<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Which fields are translated although they are not localizable (configured
 * handles, or all text when translate_non_localizable_text is on), and which
 * text fields were skipped for that reason, so editors get a warning.
 */
final class LocalizablePolicy
{
    /** @var array<string, true> */
    public array $skippedNonLocalizable = [];

    /**
     * Whether a field marked localizable: false should be translated anyway.
     *
     * Two ways in:
     *  - config('deepl.force_translate_handles') — exact handles or wildcard
     *    patterns ("hero_title", "key_facts_*") for targeted overrides;
     *  - config('deepl.translate_non_localizable_text') — site-wide switch that
     *    covers every TEXT-BEARING type (plain text, Bard, and replicator/grid
     *    containers). Shared fieldsets are frequently imported with
     *    localizable: false on fields that clearly carry language ("1.5 Zimmer",
     *    "Gäste") — inheriting them leaks source-language content into every
     *    localization.
     *
     * Non-text types (assets, toggles, dates, references) are never forced —
     * inheriting those from the origin is exactly what non-localizable means.
     * Skipped text-bearing fields are recorded so runs can WARN about them
     * instead of silently leaving source-language content behind.
     */
    public function shouldForceTranslateField(string $handle, ?array $fieldDef): bool
    {
        /** @var array<int, string> $patterns */
        $patterns = config('deepl.force_translate_handles', ['hero_title']);

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && fnmatch($pattern, $handle)) {
                return true;
            }
        }

        $type = $fieldDef['type'] ?? null;
        $textBearing = in_array($type, FieldTextCollector::TRANSLATABLE_TYPES)
            || in_array($type, FieldTextCollector::BARD_TYPES)
            || in_array($type, FieldTextCollector::RECURSIVE_TYPES);

        if (! $textBearing) {
            return false;
        }

        if ((bool) config('deepl.translate_non_localizable_text', false)) {
            return true;
        }

        $this->skippedNonLocalizable[$handle] = true;

        return false;
    }

    /**
     * Text-bearing fields left untranslated because of localizable: false,
     * drained by the caller to surface as warnings.
     *
     * @return array<int, string>
     */
    public function takeSkippedNonLocalizable(): array
    {
        $out = array_keys($this->skippedNonLocalizable);
        $this->skippedNonLocalizable = [];

        return $out;
    }
}
