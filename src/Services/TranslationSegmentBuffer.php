<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\BardTranslationHtml;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * The texts and values a collector pulled out of field data, as numbered
 * placeholders: sent to the translator in one batch, then swapped back into
 * the data with the translations (or the connector's edits).
 */
final class TranslationSegmentBuffer
{
    /** @var array<int, string> Collected texts to translate in one batch */
    public array $collectedTexts = [];

    /** @var array<int, bool> Tracks which collected texts are HTML (from merged Bard content) */
    public array $htmlTexts = [];

    /** @var array<int, string|null> Blueprint handle for each collected text. */
    public array $textFields = [];

    /** The blueprint handle whose text is being collected. */
    public ?string $currentField = null;

    public int $textIndex = 0;

    /** @var array<int, array{value: mixed, def: array<string, mixed>, set: string|null}> */
    public array $collectedValues = [];

    /** @var array<int, mixed> New values by collected value index. */
    public array $valueEdits = [];

    /** The set whose fields are being collected (recorded with each value). */
    public ?string $currentSet = null;

    public function collectText(string $text): int
    {
        $index = $this->textIndex;
        $this->collectedTexts[$index] = $text;
        $this->textFields[$index] = $this->currentField;
        $this->textIndex++;

        return $index;
    }

    /**
     * @param  array<string, mixed>  $fieldDef
     * @return array{__value__: int}
     */
    public function valuePlaceholder(mixed $value, array $fieldDef): array
    {
        $index = count($this->collectedValues);
        $this->collectedValues[$index] = ['value' => $value, 'def' => $fieldDef, 'set' => $this->currentSet];

        return ['__value__' => $index];
    }

    /**
     * Marks where a collected text goes back. An array, not the bare index,
     * so integers in the data (Bard heading levels, number fields) are never
     * mistaken for a segment.
     *
     * @return array{__segment__: int}
     */
    public function placeholder(string $text): array
    {
        return ['__segment__' => $this->collectText($text)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $translations
     * @return array<string, mixed>
     */
    public function replaceInFields(array $data, array $translations): array
    {
        $result = [];

        foreach ($data as $handle => $value) {
            $replaced = $this->replaceInValue($value, $translations);
            if ($replaced !== FieldTextCollector::MISSING_VALUE) {
                $result[$handle] = $replaced;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $translations
     */
    private function replaceInValue(mixed $value, array $translations): mixed
    {
        if (is_array($value)) {
            if (isset($value['__segment__']) && is_int($value['__segment__']) && count($value) === 1) {
                return $translations[$value['__segment__']] ?? $this->collectedTexts[$value['__segment__']] ?? '';
            }

            if (isset($value['__value__']) && is_int($value['__value__']) && count($value) === 1) {
                $original = $this->collectedValues[$value['__value__']]['value'] ?? FieldTextCollector::MISSING_VALUE;
                if (! array_key_exists($value['__value__'], $this->valueEdits)) {
                    return $original;
                }
                $new = $this->valueEdits[$value['__value__']];

                return $new === null && $original === FieldTextCollector::MISSING_VALUE ? FieldTextCollector::MISSING_VALUE : $new;
            }

            if (isset($value['__bard_merged__']) && is_int($value['__bard_merged__'])) {
                // A block with no replacement keeps its original nodes rather
                // than a round trip through HTML.
                if (! isset($translations[$value['__bard_merged__']]) && isset($value['__original__'])) {
                    return $value['__original__'];
                }

                return (new BardTranslationHtml)->htmlToBardContent($translations[$value['__bard_merged__']] ?? '');
            }

            return array_filter(
                array_map(fn ($v) => $this->replaceInValue($v, $translations), $value),
                fn ($v) => $v !== FieldTextCollector::MISSING_VALUE,
            );
        }

        return $value;
    }

    public function resetCollector(): void
    {
        $this->collectedValues = [];
        $this->valueEdits = [];
        $this->currentSet = null;
        $this->collectedTexts = [];
        $this->htmlTexts = [];
        $this->textFields = [];
        $this->currentField = null;
        $this->textIndex = 0;
    }

    /**
     * @return array<int, string>
     */
    public function prepareTextsForApi(): array
    {
        $textsForApi = $this->collectedTexts;

        foreach ($textsForApi as $index => $text) {
            if (! isset($this->htmlTexts[$index])) {
                $textsForApi[$index] = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }

        return $textsForApi;
    }

    /**
     * @param  array<int, string>  $translatedTexts
     * @return array<int, string>
     */
    public function decodeTranslatedTexts(array $translatedTexts): array
    {
        foreach ($translatedTexts as $index => $text) {
            if (! isset($this->htmlTexts[$index])) {
                $translatedTexts[$index] = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
            }
        }

        return $translatedTexts;
    }
}
