<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Fields\Field;

/**
 * The schema type of a field and the guidance the model gets for it after the
 * editor's instructions.
 */
final class FieldTypeGuidance
{
    /**
     * Schema type and the guidance the model gets after the editor's
     * instructions, for fieldtypes that need nothing else.
     */
    private const TYPE_GUIDANCE = [
        'slug' => ['text', 'A URL slug: lowercase words separated by hyphens, as the Control Panel would generate it.'],
        'link' => ['link', 'Provide a full URL (https://...), a path starting with /, entry::UUID for an internal entry, asset::... for an asset, or @child when applicable.'],
        'video' => ['video_url', 'YouTube or video page URL (https://...) or empty string.'],
        'markdown' => ['markdown', 'Plain markdown string (**bold**, *italic*, [links](url), lists, ## headings). Do NOT return HTML tags or a JSON node tree — a string only.'],
        'bard' => ['html', 'Provide as HTML using only: p, h2, h3, h4, ul, ol, li, a, strong, em, blockquote, br tags.'],
        'integer' => ['number', 'Whole number as a JSON number (no quotes).'],
        'float' => ['number', 'Number as a JSON number (no quotes).'],
        'time' => ['time', '24-hour time string "HH:MM".'],
        'table' => ['table', 'Array of rows; EACH row is {"cells": [...]} with plain string/number cell values in column order (first row = header row when the table has one). '
            .'Do NOT wrap cells as {"value": ...} objects and do NOT omit the "cells" key.'],
        'grid' => ['grid', 'Repeating grid (a list/table). Provide an ARRAY OF ROWS where EACH row represents ONE discrete item. '
            .'Every row is a JSON object using the handles under "row_fields" as its keys, and has NO "type" key. '
            .'When the source material for this field is a list of discrete items (bullet points, key/value pairs, dates, features, steps, FAQ entries), create ONE row per item — never combine several items into a single row field. '
            .'Use a single row only when the content is genuinely one item.'],
        'replicator' => ['structured', self::BLOCKS_GUIDANCE],
        'components' => ['structured', self::BLOCKS_GUIDANCE],
    ];

    private const BLOCKS_GUIDANCE = 'Provide as an array of ordered blocks. Each object must include a "type" key matching a set handle from the schema "sets" keys (see set_layout_catalog when present for titles, ai_description, and when_to_use rules). '
        .'Choose each block based on its ai_description (what the block is) and when_to_use (the scenarios it was designed for) — pick the block whose purpose genuinely matches the content of that section, rather than reusing a safe default. '
        .'Use several different block types across the page when the schema offers them — do not default the whole page to one or two repetitive types (for example only plain text or teaser blocks) if other sets exist. '
        .'Include visual or image-led sets where they fit the narrative, not only text-heavy blocks.';

    public function __construct(private readonly FieldOptionCatalog $options) {}

    /**
     * [schema type, guidance after the instructions (null: instructions only)],
     * or null for a fieldtype the model is not asked to fill.
     *
     * @return array{0: string, 1: ?string}|null
     */
    public function typeAndGuidance(Field $field): ?array
    {
        $type = $field->type();

        return match (true) {
            in_array($type, FieldTypes::TEXT_TYPES) => ['text', null],
            in_array($type, FieldTypes::BOOLEAN_TYPES) => ['boolean', null],
            in_array($type, FieldTypes::CHOICE_TYPES) => $this->options->choiceFieldIsMultiple($field)
                ? ['multi_select', 'Return an ARRAY of values copied EXACTLY from "options" (the stored values, not display labels).']
                : ['select', 'Return ONE value copied EXACTLY from "options" (the stored value, not a display label).'],
            in_array($type, FieldTypes::DATE_TYPES) => ['date', $this->statamicDateFieldSchemaDescriptionSuffix()],
            isset(self::TYPE_GUIDANCE[$type]) => self::TYPE_GUIDANCE[$type],
            default => null,
        };
    }

    /**
     * Appended to each date field's schema `description` so the model sees the contract next to the handle.
     */
    private function statamicDateFieldSchemaDescriptionSuffix(): string
    {
        return 'Value: JSON string `YYYY-MM-DD` (append ` HH:MM` only when a time matters). No regional numeric dates, no "today" — normalize from source text if needed.';
    }
}
