<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;

/**
 * Turns the CP-managed DeepL glossary and style rules into an editorial-guidance
 * prompt block, scoped to one target language, so the AI agent WRITES new
 * content with the same site-approved terminology and tone that DeepL enforces
 * when it TRANSLATES. Editors maintain the vocabulary and voice once (the
 * "Glossary & style rules" screen); both the translation path (DeeplService via
 * glossary_id / style_id) and the generation path (EntryGeneratorService via
 * this block) consume it — so generated and translated copy stay consistent.
 *
 * The block is language-scoped: only glossary terms that have wording in the
 * target language, and only style rules written for it, are injected — keyed by
 * the same base-language codes the CP screen stores (TranslationGlossaryService::
 * languages()).
 */
class EditorialGuidanceService
{
    /** Caps mirror siteInstructions: never let guidance crowd out the field schema. */
    private const MAX_TERMS = 200;

    private const MAX_BLOCK_CHARS = 4000;

    public function __construct(
        private TranslationGlossaryService $glossary,
        private TranslationStyleRulesService $styleRules,
        private DeeplService $deepl,
    ) {}

    /**
     * Combined terminology + style-rules block for the given site handle or
     * locale. Empty string when neither is configured for that language.
     */
    public function promptBlock(string $localeOrHandle): string
    {
        $base = $this->baseLanguage($localeOrHandle);

        if ($base === '') {
            return '';
        }

        return $this->terminologyBlock($base).$this->styleBlock($base);
    }

    private function terminologyBlock(string $base): string
    {
        $lines = [];

        foreach ($this->glossary->entries() as $entry) {
            $terms = is_array($entry['terms'] ?? null) ? $entry['terms'] : [];
            $target = trim((string) ($terms[$base] ?? ''));

            if ($target === '') {
                continue; // no approved wording for this language → nothing to enforce
            }

            // Cross-language equivalents help the model recognise WHEN the term
            // applies, without turning the row into a translation instruction.
            $equivalents = [];
            foreach ($terms as $lang => $term) {
                $lang = strtolower((string) $lang);
                $term = trim((string) $term);
                if ($lang !== $base && $term !== '') {
                    $equivalents[] = "{$lang}: {$term}";
                }
            }

            $line = '- "'.$target.'"';
            if ($equivalents !== []) {
                $line .= ' ('.implode(', ', $equivalents).')';
            }

            $lines[] = $line;

            if (count($lines) >= self::MAX_TERMS) {
                break;
            }
        }

        if ($lines === []) {
            return '';
        }

        return "\n\nBRAND TERMINOLOGY (site-approved wording — when your content refers to any of these concepts, use the exact term and spelling shown; never substitute a synonym or alternative spelling):\n"
            .Str::limit(implode("\n", $lines), self::MAX_BLOCK_CHARS);
    }

    private function styleBlock(string $base): string
    {
        $rule = $this->styleRules->rules()[$base] ?? null;
        $instructions = is_array($rule) && is_array($rule['instructions'] ?? null) ? $rule['instructions'] : [];

        if ($instructions === []) {
            return '';
        }

        $lines = [];
        foreach ($instructions as $instruction) {
            $instruction = trim((string) $instruction);
            if ($instruction !== '') {
                $lines[] = '- '.$instruction;
            }
        }

        if ($lines === []) {
            return '';
        }

        return "\n\nSTYLE & TONE RULES (site-approved; always follow them when writing this language):\n"
            .Str::limit(implode("\n", $lines), self::MAX_BLOCK_CHARS);
    }

    /**
     * Base language code (de, en, fr, …) for a site handle or locale, derived
     * the SAME way the CP glossary screen keys its columns (DeepL mapping, then
     * region/script stripped) so the lookups line up.
     */
    private function baseLanguage(string $localeOrHandle): string
    {
        if (trim($localeOrHandle) === '') {
            return '';
        }

        $mapped = $this->deepl->mapLanguage($localeOrHandle);

        return strtolower((string) preg_replace('/[-_].*$/', '', $mapped));
    }
}
