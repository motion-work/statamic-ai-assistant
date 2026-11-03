<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Describes a blueprint's fields to the model: one schema entry per field with
 * its type, limits, options, editor hints and, for replicators and grids, the
 * nested sets and rows it may produce.
 */
final class FieldSchemaBuilder
{
    private ReferenceFieldSchema $references;

    private SetLayoutCatalog $setCatalog;

    private FieldTypeGuidance $guidance;

    public function __construct(
        private SetHintsService $setHints,
        private FieldOptionCatalog $options,
    ) {
        $this->references = new ReferenceFieldSchema($options);
        $this->setCatalog = new SetLayoutCatalog($setHints);
        $this->guidance = new FieldTypeGuidance($options);
    }

    /**
     * Field schema for the CP review step (includes assets; LLM schema excludes them).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getFieldSchemaForPreview(Blueprint $blueprint): array
    {
        $schema = [];
        $siteHandle = Site::selected()?->handle() ?? Site::default()->handle();

        foreach ($blueprint->fields()->all() as $field) {
            $type = $field->type();

            if ($type === 'section') {
                continue;
            }

            if ($type === 'assets') {
                $schema[$field->handle()] = [
                    'label' => $field->display(),
                    'generatable' => true,
                    'type' => 'asset_description',
                ];

                continue;
            }

            $entry = $this->buildFieldSchemaEntry($field, false, $siteHandle);

            if ($entry !== null) {
                $schema[$field->handle()] = $entry;
            }
        }

        return $schema;
    }

    /**
     * Build a JSON-serializable schema from the blueprint for the LLM.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildFieldSchema(Blueprint $blueprint, string $siteHandle): array
    {
        $schema = [];

        foreach ($blueprint->fields()->all() as $field) {
            $entry = $this->buildFieldSchemaEntry($field, false, $siteHandle);

            if ($entry !== null) {
                $schema[$field->handle()] = $entry;
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function finalizeSchemaEntry(Field $field, array $entry): array
    {
        if ($field->isRequired()) {
            $entry['required'] = true;
        }

        // Field hints (AI instructions page): editors can describe what a field
        // like `hero_title` or `lead` is for and add writing guidelines. Hints
        // match by handle wherever the field appears (top level, groups, sets).
        $hint = $this->setHints->forField((string) $field->handle());

        if ($hint !== null) {
            if ($hint['ai_description'] !== '') {
                $entry['ai_description'] = $hint['ai_description'];
            }

            if ($hint['when_to_use'] !== []) {
                $entry['writing_guidelines'] = $hint['when_to_use'];
            }
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildFieldSchemaEntry(Field $field, bool $insideReplicatorComponentsOrGridRow = false, ?string $siteHandle = null): ?array
    {
        $type = $field->type();
        $display = $field->display();
        $instructions = $field->instructions();
        $siteHandle = $siteHandle ?? Site::current()?->handle() ?? Site::default()->handle();

        // The entry address is the entry's slug, which composing generates
        // from the title. A slug field with any other handle is content
        // (a route segment such as client_slug) and is filled like text.
        if ($type === 'slug' && $field->handle() === 'slug' && ! $insideReplicatorComponentsOrGridRow) {
            return null;
        }

        if ($insideReplicatorComponentsOrGridRow && $type === 'assets') {
            return $this->finalizeSchemaEntry($field, [
                'label' => $display,
                'generatable' => true,
                'type' => 'asset_description',
                'description' => ($instructions ? $instructions.' ' : '')
                    .'Short vivid description of the image (subject, setting, mood). You may use an empty string for layout-only blocks; imagery may be auto-selected.',
            ]);
        }

        if (in_array($type, ['terms', 'entries', 'form'], true)) {
            $payload = $this->references->referenceSchemaPayload($field, $siteHandle, $instructions);

            return $payload === null ? null : $this->finalizeSchemaEntry($field, $payload);
        }

        if (in_array($type, FieldTypes::SKIP_TYPES)) {
            return null;
        }

        $entry = [
            'label' => $display,
            'generatable' => true,
        ];

        if ($instructions) {
            $entry['description'] = $instructions;
        }

        if (in_array($type, FieldTypes::GROUP_TYPES)) {
            $entry['type'] = 'group';
            $entry['fields'] = $this->groupSchema($field, $insideReplicatorComponentsOrGridRow, $siteHandle);

            return $this->finalizeSchemaEntry($field, $entry);
        }

        $typeAndGuidance = $this->guidance->typeAndGuidance($field);

        if ($typeAndGuidance === null) {
            return null;
        }

        [$entry['type'], $guidance] = $typeAndGuidance;
        $this->addTypeDetails($entry, $field, $guidance, $siteHandle);

        return $this->finalizeSchemaEntry($field, $entry);
    }

    /**
     * Options, guidance, length limit, grid rows or sets, by fieldtype.
     *
     * @param  array<string, mixed>  $entry
     */
    private function addTypeDetails(array &$entry, Field $field, ?string $guidance, string $siteHandle): void
    {
        $type = $field->type();
        $instructions = $field->instructions();

        if (in_array($type, FieldTypes::CHOICE_TYPES)) {
            $entry['options'] = $this->options->choiceFieldOptionValues($field);
        }

        if ($guidance !== null) {
            $entry['description'] = ($instructions ? $instructions.' ' : '').$guidance;
        }

        if (in_array($type, FieldTypes::TEXT_TYPES) && isset($field->config()['character_limit'])) {
            $entry['max_length'] = $field->config()['character_limit'];
        }

        if ($type === 'grid') {
            $rowFields = $this->buildGridRowSchema($field, $siteHandle);

            if (! empty($rowFields)) {
                $entry['row_fields'] = $rowFields;
            }
        } elseif (in_array($type, FieldTypes::RECURSIVE_TYPES)) {
            $this->addSets($entry, $field, $siteHandle);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function groupSchema(Field $field, bool $insideReplicatorComponentsOrGridRow, string $siteHandle): array
    {
        $fields = [];

        foreach ($field->fieldtype()->fields()->all() as $sub) {
            $subEntry = $this->buildFieldSchemaEntry($sub, $insideReplicatorComponentsOrGridRow, $siteHandle);

            if ($subEntry !== null) {
                $fields[$sub->handle()] = $subEntry;
            }
        }

        return $fields;
    }

    /**
     * A replicator's sets and, for replicator/components, the catalog of what
     * each set is for.
     *
     * @param  array<string, mixed>  $entry
     */
    private function addSets(array &$entry, Field $field, string $siteHandle): void
    {
        $sets = $this->buildRecursiveSchema($field, $siteHandle);

        if (! empty($sets)) {
            $entry['sets'] = $sets;
        }

        if (in_array($field->type(), ['replicator', 'components'], true)) {
            $catalog = $this->setCatalog->buildSetLayoutCatalog($field);

            if ($catalog !== []) {
                $entry['set_layout_catalog'] = $catalog;
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildRecursiveSchema(Field $field, string $siteHandle): array
    {
        $sets = [];
        $fieldtype = $field->fieldtype();

        if (in_array($field->type(), ['replicator', 'components'])) {
            foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
                $setFields = $fieldtype->fields($setHandle);
                $setSchema = [];

                foreach ($setFields->all() as $subField) {
                    $subEntry = $this->buildFieldSchemaEntry($subField, true, $siteHandle);

                    if ($subEntry !== null) {
                        $setSchema[$subField->handle()] = $subEntry;
                    }
                }

                $sets[$setHandle] = $setSchema;
            }
        }

        return $sets;
    }

    /**
     * Schema for a single grid row: the grid's sub-fields keyed by handle.
     * Grids are a repeating list of identically-shaped rows (unlike
     * replicator/components, which pick between named sets), so this is
     * presented to the model under "row_fields" rather than "sets".
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildGridRowSchema(Field $field, string $siteHandle): array
    {
        $rowSchema = [];

        foreach ($field->fieldtype()->fields()->all() as $subField) {
            $subEntry = $this->buildFieldSchemaEntry($subField, true, $siteHandle);

            if ($subEntry !== null) {
                $rowSchema[$subField->handle()] = $subEntry;
            }
        }

        return $rowSchema;
    }
}
