<?php

namespace MotionWork\StatamicAiAssistant\Services;

use DeepL\TranslateTextOptions;
use Illuminate\Support\Facades\Log;

/**
 * Splits a translation batch into DeepL requests: which texts get the
 * glossary and the style rule, so glossary terms stay enforced where style
 * rules would otherwise override them.
 */
final class DeeplRequestPlanner
{
    public function __construct(private DeeplLanguages $languages) {}

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
    public function planTranslationSegments(array $nonEmptyTexts, array $options, ?string $mappedSource, string $mappedTarget): array
    {
        $sourceBase = $mappedSource !== null
            ? $this->languages->primaryLanguageSubtag(strtolower(str_replace('_', '-', $mappedSource)))
            : null;
        $targetBase = $this->languages->primaryLanguageSubtag(strtolower(str_replace('_', '-', $mappedTarget)));

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
}
