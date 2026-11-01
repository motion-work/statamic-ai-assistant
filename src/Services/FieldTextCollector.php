<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\BardTranslationHtml;
use MotionWork\StatamicAiAssistant\Support\SetHeadline;
use MotionWork\StatamicAiAssistant\Support\TranslationValueShapes;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

final class FieldTextCollector
{
    public const TRANSLATABLE_TYPES = ['text', 'textarea', 'ai_text', 'ai_textarea'];

    public const BARD_TYPES = ['bard'];

    public const RECURSIVE_TYPES = ['replicator', 'grid', 'components'];

    public const SKIP_TYPES = ['assets', 'date', 'toggle', 'section', 'color', 'select', 'button_group', 'terms', 'video'];

    /** Non-text fields an in-place edit may change. Never collected for translation. */
    public const VALUE_TYPES = ['toggle', 'select', 'button_group', 'checkboxes', 'radio', 'link', 'assets'];

    /** A value field absent from the data; dropped again unless an edit sets it. */
    public const MISSING_VALUE = '__statamic_ai_assistant_missing__';

    /** In-place edits only: also collect toggles, choices, links and images. */
    public bool $collectingValues = false;

    public readonly TranslationSegmentBuffer $segments;

    public readonly LocalizablePolicy $policy;

    public function __construct()
    {
        $this->segments = new TranslationSegmentBuffer;
        $this->policy = new LocalizablePolicy;
    }

    // ── Phase 1: Collect ─────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>  $fields
     * @return array<string, mixed>
     */
    public function collectFromFields(array $data, array $fields): array
    {
        $result = [];

        foreach ($data as $handle => $value) {
            if ($value === null || $value === '') {
                $result[$handle] = $value;

                continue;
            }

            $fieldDef = $fields[$handle] ?? null;
            $fieldType = $fieldDef['type'] ?? null;
            $isLocalizable = $fieldDef['localizable'] ?? true;

            if (! $isLocalizable && ! $this->policy->shouldForceTranslateField($handle, $fieldDef)) {
                continue;
            }

            $result[$handle] = $this->collectFromField($value, $fieldType, $fieldDef);
        }

        return $result;
    }

    /**
     * @param  array{type: string, localizable: bool, sets?: array, fields?: array}|null  $fieldDef
     */
    public function collectFromField(mixed $value, ?string $fieldType, ?array $fieldDef = null): mixed
    {
        if ($this->collectingValues && in_array($fieldType, self::VALUE_TYPES, true)) {
            return $this->segments->valuePlaceholder($value, $fieldDef ?? []);
        }

        if ($value === null || $value === '' || $value === []) {
            return $value;
        }

        if (in_array($fieldType, FieldTextCollector::TRANSLATABLE_TYPES)) {
            return is_string($value) ? $this->segments->placeholder($value) : $value;
        }

        if (in_array($fieldType, FieldTextCollector::BARD_TYPES) && is_array($value)) {
            return $this->collectFromBard($value);
        }

        if (in_array($fieldType, FieldTextCollector::RECURSIVE_TYPES) && is_array($value)) {
            return $this->collectFromReplicator($value, $fieldDef);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $nodes
     * @return array<mixed>
     */
    private function collectFromBard(array $nodes): array
    {
        foreach ($nodes as &$node) {
            if (! is_array($node)) {
                continue;
            }

            if (isset($node['content']) && is_array($node['content'])) {
                if ((new BardTranslationHtml)->shouldMergeBardContent($node['content'])) {
                    $html = (new BardTranslationHtml)->bardContentToHtml($node['content']);
                    $placeholder = $this->segments->collectText($html);
                    $this->segments->htmlTexts[$placeholder] = true;
                    $node['content'] = ['__bard_merged__' => $placeholder, '__original__' => $node['content']];
                } else {
                    $node['content'] = $this->collectFromBard($node['content']);
                }

                continue;
            }

            if (($node['type'] ?? null) === 'text' && isset($node['text']) && trim($node['text']) !== '') {
                $node['text'] = $this->segments->placeholder($node['text']);
            }
        }

        return $nodes;
    }

    /**
     * @param  array<mixed>  $sets
     * @param  array{sets?: array, fields?: array}|null  $fieldDef
     * @return array<mixed>
     */
    private function collectFromReplicator(array $sets, ?array $fieldDef = null): array
    {
        $setsDefs = $fieldDef['sets'] ?? null;
        $gridFieldsDefs = $fieldDef['fields'] ?? null;

        $position = 0;
        foreach ($sets as &$set) {
            if (! is_array($set)) {
                continue;
            }
            $position++;

            $setType = $set['type'] ?? null;
            $subFields = null;

            if ($setsDefs !== null && $setType !== null) {
                $subFields = $setsDefs[$setType] ?? null;
            } elseif ($gridFieldsDefs !== null) {
                $subFields = $gridFieldsDefs;
            }

            // Where a value sits, for the editor: Komponenten › tiles 3 "Sicherheit".
            $parentSet = $this->segments->currentSet;
            $headline = $this->collectingValues ? (new SetHeadline)->setHeadline($set) : null;
            $this->segments->currentSet = implode(' › ', array_filter([
                $parentSet,
                $fieldDef['display'] ?? $fieldDef['handle'] ?? null,
                (is_string($setType) ? $setType : 'row').' '.$position.($headline !== null ? ' "'.$headline.'"' : ''),
            ]));

            foreach ($set as $key => &$value) {
                if (in_array($key, ['id', 'type', 'enabled'])) {
                    continue;
                }
                $isValueField = $this->collectingValues && in_array($subFields[$key]['type'] ?? null, self::VALUE_TYPES, true);
                if (! $isValueField && ($value === null || $value === '' || $value === [])) {
                    continue;
                }

                if ($subFields !== null) {
                    $subFieldType = $subFields[$key]['type'] ?? null;
                    $subFieldDef = $subFields[$key] ?? null;
                    $value = $this->collectFromField($value, $subFieldType, $subFieldDef);
                } else {
                    $value = $this->collectFromReplicatorFieldFallback($value);
                }
            }
            unset($value);

            if ($this->collectingValues && $subFields !== null) {
                foreach ($subFields as $key => $subFieldDef) {
                    if (! array_key_exists($key, $set) && in_array($subFieldDef['type'] ?? null, self::VALUE_TYPES, true)) {
                        $set[$key] = $this->segments->valuePlaceholder(FieldTextCollector::MISSING_VALUE, $subFieldDef);
                    }
                }
            }

            $this->segments->currentSet = $parentSet;
        }

        return $sets;
    }

    private function collectFromReplicatorFieldFallback(mixed $value): mixed
    {
        if (is_string($value) && ! (new TranslationValueShapes)->looksLikeEntryReference($value)
            && ! (new TranslationValueShapes)->looksLikeFilePath($value) && ! (new TranslationValueShapes)->looksLikeUrl($value)) {
            return $this->segments->placeholder($value);
        }

        if (is_array($value)) {
            if ((new TranslationValueShapes)->isBardContent($value)) {
                return $this->collectFromBard($value);
            }

            if ((new TranslationValueShapes)->isNestedSets($value)) {
                return $this->collectFromReplicator($value);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>  $fields
     * @return array<string, mixed>
     */
    public function collectFromEntryFields(array $data, array $fields, int $currentDepth, int $maxDepth, bool $everyField = false): array
    {
        $result = [];

        foreach ($data as $handle => $value) {
            $isValueField = $this->collectingValues && in_array($fields[$handle]['type'] ?? null, self::VALUE_TYPES, true);
            if (! $isValueField && ($value === null || $value === '')) {
                $result[$handle] = $value;

                continue;
            }

            $fieldDef = $fields[$handle] ?? null;

            // Skip fields not defined in the blueprint (metadata like
            // duplicated_from, updated_by, updated_at) — these should be
            // inherited from the origin, not copied into the localization.
            if ($fieldDef === null) {
                continue;
            }

            $fieldType = $fieldDef['type'] ?? null;
            $isLocalizable = $fieldDef['localizable'] ?? true;

            if (! $everyField && ! $isLocalizable && ! $this->policy->shouldForceTranslateField($handle, $fieldDef)) {
                continue;
            }

            $result[$handle] = $this->collectFromField($value, $fieldType, $fieldDef);
        }

        return $result;
    }

    /**
     * Value fields of the blueprint that the entry has never set, so an edit
     * can set them. A localization does not own fields marked
     * localizable: false, as in the Control Panel.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    public function collectMissingValueFields(array $data, array $fields, bool $isLocalization): array
    {
        $missing = [];
        foreach ($fields as $handle => $fieldDef) {
            if (array_key_exists($handle, $data) || ! in_array($fieldDef['type'] ?? null, self::VALUE_TYPES, true)) {
                continue;
            }
            if ($isLocalization && ! ($fieldDef['localizable'] ?? true)) {
                continue;
            }
            $missing[$handle] = $this->segments->valuePlaceholder(FieldTextCollector::MISSING_VALUE, $fieldDef);
        }

        return $missing;
    }
}
