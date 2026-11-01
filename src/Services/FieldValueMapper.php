<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Statamic\Fieldtypes\Terms;

/**
 * Turns the model's JSON into storable field data for a blueprint: each value
 * coerced to its fieldtype (Bard, choices, terms, entries, links, tables,
 * replicator sets, ...), unknown or invalid values dropped with a warning, and
 * required fields the model left empty filled where that is safe.
 */
final class FieldValueMapper
{
    private ReferenceValueMapper $references;

    private ScalarValueMapper $scalars;

    private BardValueMapper $bard;

    private RequiredFieldFallback $requiredFallback;

    public function __construct(
        AbstractAiService $aiService,
        private EntryGeneratorAssetResolver $assetResolver,
        private EntryGeneratorLinkFallback $linkFallback,
        FieldOptionCatalog $options,
        EntrySearch $search,
    ) {
        $this->references = new ReferenceValueMapper($options, $search);
        $this->scalars = new ScalarValueMapper($aiService, $options);
        $this->bard = new BardValueMapper($this->scalars);
        $this->requiredFallback = new RequiredFieldFallback($aiService, $options, $this->references, $this);
    }

    /**
     * Map parsed LLM data to Statamic field values.
     *
     * Returns both the Statamic-ready data and a display-friendly version
     * (e.g., HTML strings for Bard fields instead of ProseMirror JSON).
     *
     * @return array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[]}
     */
    public function mapToFieldData(array $parsedData, Blueprint $blueprint, string $locale, string $prompt = '', ?PreferredAssetPaths $preferredAssets = null, bool $partial = false): array
    {
        $data = [];
        $displayData = [];
        $warnings = [];

        foreach ($blueprint->fields()->all() as $field) {
            $handle = $field->handle();
            $type = $field->type();

            if (! array_key_exists($handle, $parsedData)) {
                continue;
            }

            $value = $parsedData[$handle];

            if ($value === null || $value === '') {
                continue;
            }

            // For Bard fields, keep the raw HTML for display
            if (in_array($type, FieldTypes::HTML_TYPES) && is_string($value)) {
                $displayData[$handle] = $value;
            }

            $mapped = $this->mapFieldValue($value, $field, $warnings, $locale);

            if ($mapped !== null) {
                $data[$handle] = $mapped;

                // For non-bard fields, display value is same as mapped value
                if (! isset($displayData[$handle])) {
                    $displayData[$handle] = $mapped;
                }
            }
        }

        // Update mode: only carry forward what the LLM actually returned. Skip
        // the asset/link/required autofillers that exist for fresh-entry creation
        // — the existing entry already satisfies blueprint validation.
        if (! $partial) {
            (new AssetFieldFiller($this->assetResolver))->fillEmpty($data, $displayData, $blueprint, $warnings, $preferredAssets);
            $this->linkFallback->fillEmptyLinkFields($data, $displayData, $blueprint, $locale, $warnings);
            $this->requiredFallback->applyMandatoryFieldFallbacks($data, $displayData, $blueprint, $warnings, $prompt, $locale);
        }

        return ['data' => $data, 'displayData' => $displayData, 'warnings' => $warnings];
    }

    /**
     * Fieldtypes and the handler that maps their value: [types, part (null:
     * this mapper), method]. Every handler takes ($value, $field, &$warnings,
     * $siteHandle) and ignores what it does not need; the first match wins.
     */
    private const HANDLERS = [
        [FieldTypes::GROUP_TYPES, null, 'mapGroupValue'],
        [FieldTypes::LINK_TYPES, 'scalars', 'mapLinkFieldValue'],
        [['video'], 'scalars', 'mapVideoFieldValue'],
        [FieldTypes::TEXT_TYPES, 'scalars', 'mapTextValue'],
        [FieldTypes::HTML_TYPES, 'bard', 'mapBardValue'],
        [FieldTypes::CHOICE_TYPES, 'scalars', 'mapChoiceValue'],
        [FieldTypes::BOOLEAN_TYPES, 'scalars', 'mapBooleanValue'],
        [FieldTypes::DATE_TYPES, 'scalars', 'mapDateValue'],
        [['markdown'], 'scalars', 'mapMarkdownValue'],
        [['integer', 'float'], 'scalars', 'mapNumberValue'],
        [['time'], 'scalars', 'mapTimeValue'],
        [['table'], 'scalars', 'mapTableFieldValue'],
        [['terms'], 'references', 'mapTermsFieldValue'],
        [['entries'], 'references', 'mapEntriesFieldValue'],
        [['form'], 'references', 'mapFormFieldValue'],
        [FieldTypes::RECURSIVE_TYPES, null, 'mapRecursiveValue'],
    ];

    public function mapFieldValue(mixed $value, Field $field, array &$warnings, string $siteHandle): mixed
    {
        foreach (self::HANDLERS as [$types, $part, $method]) {
            if (in_array($field->type(), $types)) {
                $handler = $part === null ? $this : $this->{$part};

                return $handler->{$method}($value, $field, $warnings, $siteHandle);
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function mapRecursiveValue(mixed $value, Field $field, array &$warnings, string $siteHandle): ?array
    {
        return is_array($value) ? $this->mapReplicatorData($value, $field, $warnings, $siteHandle) : null;
    }

    /**
     * A group's sub-values, each mapped by its own field.
     */
    private function mapGroupValue(mixed $value, Field $field, array &$warnings, string $siteHandle): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];

        foreach ($field->fieldtype()->fields()->all() as $sub) {
            $sh = $sub->handle();

            if (! array_key_exists($sh, $value)) {
                continue;
            }

            $mapped = $this->mapFieldValue($value[$sh], $sub, $warnings, $siteHandle);

            if ($mapped !== null) {
                $out[$sh] = $mapped;
            }
        }

        return $out;
    }

    /**
     * When the LLM echoes an existing block/row `id` (the update-mode snapshot
     * shows current blocks as raw JSON including ids), keep it instead of
     * generating a fresh UUID. Stable ids keep Statamic revision diffs and the
     * CP UI honest about which blocks actually changed during an update.
     *
     * @param  array<string, mixed>  $set
     */
    private function reusableBlockId(array $set): ?string
    {
        $id = $set['id'] ?? null;

        if (! is_string($id)) {
            return null;
        }

        $id = trim($id);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $id) === 1 ? $id : null;
    }

    /**
     * @return array<mixed>
     */
    private function mapReplicatorData(array $sets, Field $field, array &$warnings, string $siteHandle): array
    {
        $result = [];
        $fieldtype = $field->fieldtype();

        foreach ($sets as $set) {
            if (! is_array($set)) {
                continue;
            }

            $id = $this->reusableBlockId($set) ?? Str::uuid()->toString();

            if ($field->type() === 'grid') {
                // Grid rows don't have a "type" key
                $result[] = $this->mapSubFields($set, $fieldtype->fields(), ['id' => $id], $warnings, $siteHandle);

                continue;
            }

            // Replicator/components — sets have a "type" key
            $setType = $set['type'] ?? null;

            if (! $setType) {
                continue;
            }

            // Cross-blueprint safety: when a new entry is modelled on another
            // entry's layout (read_entry_structure), the LLM may emit a set
            // handle that does not exist in THIS field. Skip it with a warning
            // rather than persisting an empty, invalid block.
            if (! collect($fieldtype->flattenedSetsConfig())->has($setType)) {
                $warnings[] = __('Unknown set type ":type" in :field. Skipped.', [
                    'type' => $setType,
                    'field' => $field->display(),
                ]);

                continue;
            }

            $result[] = $this->mapSubFields(
                $set,
                $fieldtype->fields($setType),
                ['id' => $id, 'type' => $setType, 'enabled' => true],
                $warnings,
                $siteHandle,
            );
        }

        return $result;
    }

    /**
     * Map the values in $source that belong to $fields onto $row; values the
     * mapper drops (null) are left out.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapSubFields(array $source, Fields $fields, array $row, array &$warnings, string $siteHandle): array
    {
        foreach ($fields->all() as $subField) {
            $subHandle = $subField->handle();

            if (! array_key_exists($subHandle, $source)) {
                continue;
            }

            $mapped = $this->mapFieldValue($source[$subHandle], $subField, $warnings, $siteHandle);

            if ($mapped !== null) {
                $row[$subHandle] = $mapped;
            }
        }

        return $row;
    }
}
