<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;

/**
 * The JSON Schema of the entry JSON the model returns, derived from the field
 * schema FieldSchemaBuilder builds for the prompt. Sent as OpenRouter's
 * `response_format` so the model answers with parseable JSON in the right
 * shape; EntryJsonParser stays the safety net.
 *
 * Rules learned from live tests (Nov 2026), which the shape here follows:
 * - Anthropic drops every property the schema does not name, even with
 *   `additionalProperties: true`. So each replicator set is a full `anyOf`
 *   branch with all its fields, never just `{type}`. A blueprint the schema
 *   cannot describe completely (nesting deeper than MAX_DEPTH) gets no schema
 *   at all rather than one that would strip content.
 * - Anthropic rejects a property without `type`; loose values use a type list.
 * - Gemini leaves out optional arrays (the page's blocks), so on create every
 *   top-level field is required, and optional scalars also accept "" (the
 *   prompt's "empty string when you cannot determine content").
 *
 * Non-strict (`strict: false`): strict mode needs every property required and
 * `additionalProperties: false` at every level, which update mode (omit what
 * you keep) and optional nested fields cannot meet. Descriptions, limits and
 * catalogs stay in the system prompt to keep the schema small.
 */
final class EntryResponseSchema
{
    public const NAME = 'statamic_entry';

    /** Sets inside sets inside groups …: deeper than this, no schema is sent. */
    private const MAX_DEPTH = 6;

    /** Field types whose value is free text. */
    private const STRING_TYPES = ['text', 'html', 'markdown', 'date', 'time', 'link', 'video_url', 'asset_description'];

    /** Field types that link other content: one value or a list. */
    private const REFERENCE_TYPES = ['taxonomy_terms', 'entry_references', 'form_reference'];

    /**
     * The `response_format` for $fieldSchema, or null when the blueprint cannot
     * be described completely.
     *
     * @param  array<string, array<string, mixed>>  $fieldSchema
     * @param  bool  $update  Update mode: the model returns only the keys it changes
     * @return array{type: string, json_schema: array{name: string, strict: bool, schema: array<string, mixed>}}|null
     */
    public function responseFormat(array $fieldSchema, bool $update = false): ?array
    {
        $schema = $this->schema($fieldSchema, $update);

        return $schema === null ? null : [
            'type' => 'json_schema',
            'json_schema' => ['name' => self::NAME, 'strict' => false, 'schema' => $schema],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldSchema
     * @return array<string, mixed>|null
     */
    public function schema(array $fieldSchema, bool $update = false): ?array
    {
        if ($fieldSchema === []) {
            return null;
        }

        try {
            return $this->objectSchema($fieldSchema, 0, requireAll: ! $update, requireNone: $update);
        } catch (\LengthException $e) {
            // Expected for very deep blueprints: generation runs without a schema.
            Log::info('[entry-gen] no response schema for this blueprint', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * An object whose properties are the fields of $fields.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $extraRequired
     * @return array<string, mixed>
     */
    private function objectSchema(array $fields, int $depth, bool $requireAll = false, bool $requireNone = false, array $extraRequired = []): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \LengthException('Field schema nests too deep for a response schema.');
        }

        $properties = [];
        $required = $extraRequired;

        foreach ($fields as $handle => $field) {
            if (! is_array($field) || ! is_string($handle) || $handle === '' || in_array($handle, $extraRequired, true)) {
                continue;
            }

            $isRequired = ! $requireNone && ($requireAll || ($field['required'] ?? false) === true);
            $properties[$handle] = $this->fieldSchema($field, $depth, ($field['required'] ?? false) === true);

            if ($isRequired) {
                $required[] = $handle;
            }
        }

        $schema = ['type' => 'object', 'properties' => (object) $properties];

        if ($required !== []) {
            $schema['required'] = array_values(array_unique($required));
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $field  One field schema entry
     * @param  bool  $required  The blueprint requires a value (optional scalars also accept "")
     * @return array<string, mixed>
     */
    private function fieldSchema(array $field, int $depth, bool $required): array
    {
        $type = (string) ($field['type'] ?? '');

        return match (true) {
            in_array($type, self::STRING_TYPES, true) => ['type' => 'string'],
            in_array($type, self::REFERENCE_TYPES, true) => ['type' => ['array', 'string'], 'items' => ['type' => 'string']],
            $type === 'select' => $this->selectSchema($field, $required),
            $type === 'multi_select' => ['type' => 'array', 'items' => $this->choiceItems($field)],
            $type === 'boolean' => ['type' => 'boolean'],
            $type === 'number' => ['type' => $required ? 'number' : ['number', 'string']],
            $type === 'table' => $this->tableSchema(),
            $type === 'group' => $this->objectSchema(is_array($field['fields'] ?? null) ? $field['fields'] : [], $depth + 1),
            $type === 'grid' => [
                'type' => 'array',
                'items' => $this->objectSchema(is_array($field['row_fields'] ?? null) ? $field['row_fields'] : [], $depth + 1),
            ],
            $type === 'structured' => ['type' => 'array', 'items' => $this->setsSchema(is_array($field['sets'] ?? null) ? $field['sets'] : [], $depth + 1)],
            default => ['type' => 'string'],
        };
    }

    /**
     * One `anyOf` branch per set: `type` is the set handle, plus all its fields.
     *
     * @param  array<string, mixed>  $sets
     * @return array<string, mixed>
     */
    private function setsSchema(array $sets, int $depth): array
    {
        $branches = [];

        foreach ($sets as $setHandle => $setFields) {
            if (! is_string($setHandle) || $setHandle === '') {
                continue;
            }

            $branch = $this->objectSchema(is_array($setFields) ? $setFields : [], $depth, extraRequired: ['type']);
            $properties = (array) $branch['properties'];
            $branch['properties'] = (object) (['type' => ['type' => 'string', 'const' => $setHandle]] + $properties);
            $branches[] = $branch;
        }

        if ($branches === []) {
            return ['type' => 'object', 'properties' => (object) ['type' => ['type' => 'string']], 'required' => ['type']];
        }

        return count($branches) === 1 ? $branches[0] : ['anyOf' => $branches];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function selectSchema(array $field, bool $required): array
    {
        $options = $this->options($field);

        if ($options === []) {
            return ['type' => 'string'];
        }

        return ['type' => 'string', 'enum' => $required ? $options : array_values(array_unique([...$options, '']))];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function choiceItems(array $field): array
    {
        $options = $this->options($field);

        return $options === [] ? ['type' => 'string'] : ['type' => 'string', 'enum' => $options];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function options(array $field): array
    {
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];

        return array_values(array_unique(array_map('strval', array_filter($options, 'is_scalar'))));
    }

    /**
     * Table rows: `{"cells": [...]}` with string or number cells (FieldTypeGuidance).
     *
     * @return array<string, mixed>
     */
    private function tableSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => (object) ['cells' => ['type' => 'array', 'items' => ['type' => ['string', 'number']]]],
                'required' => ['cells'],
            ],
        ];
    }
}
