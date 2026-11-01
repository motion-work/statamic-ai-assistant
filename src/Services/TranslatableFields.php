<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Facades\AssetContainer;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * A blueprint's fields as the translator needs them: type, localizable, and
 * the nested sets, grid fields and choice options to walk.
 */
final class TranslatableFields
{
    /**
     * @return array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>
     */
    public function getFieldDefinitions(Blueprint $blueprint): array
    {
        $result = [];

        foreach ($blueprint->fields()->all() as $field) {
            $result[$field->handle()] = $this->buildFieldDef($field);
        }

        return $result;
    }

    /**
     * @return array{type: string, localizable: bool, sets?: array, fields?: array}
     */
    private function buildFieldDef(Field $field): array
    {
        $def = [
            'type' => $field->type(),
            'localizable' => $field->isLocalizable(),
            'handle' => $field->handle(),
            'display' => $field->display(),
        ];

        if (in_array($field->type(), ['select', 'button_group', 'checkboxes', 'radio'], true)) {
            $def['options'] = $this->normalizeOptions($field->get('options'));
            $def['multiple'] = $field->type() === 'checkboxes' || (bool) $field->get('multiple');
            $def['taggable'] = (bool) $field->get('taggable');
        }

        if ($field->type() === 'assets') {
            $containers = AssetContainer::all();
            $onlyContainer = $containers->count() === 1 ? $containers->first()->handle() : null;
            $def['container'] = $field->get('container') ?: $onlyContainer;
            $def['folder'] = trim((string) $field->get('folder'), '/');
            $def['restrict'] = (bool) $field->get('restrict');
            $def['max_files'] = (int) $field->get('max_files');
        }

        if (in_array($field->type(), ['replicator', 'components'])) {
            $def['sets'] = [];
            $fieldtype = $field->fieldtype();

            foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
                $setFields = $fieldtype->fields($setHandle);
                $def['sets'][$setHandle] = $this->extractFieldDefs($setFields);
            }
        } elseif ($field->type() === 'grid') {
            $def['fields'] = $this->extractFieldDefs($field->fieldtype()->fields());
        }

        return $def;
    }

    /**
     * Statamic accepts options as a list, a key => label map, or a list of
     * {key, value} pairs. Always key => label here.
     *
     * @return array<string, string>
     */
    private function normalizeOptions(mixed $options): array
    {
        $normalized = [];
        foreach (is_array($options) ? $options : [] as $key => $option) {
            if (is_array($option) && array_key_exists('key', $option)) {
                $normalized[(string) $option['key']] = (string) ($option['value'] ?? $option['key']);
            } elseif (is_int($key) && array_is_list($options)) {
                $normalized[(string) $option] = (string) $option;
            } else {
                $normalized[(string) $key] = (string) $option;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, array{type: string, localizable: bool, sets?: array, fields?: array}>
     */
    private function extractFieldDefs(Fields $fields): array
    {
        $defs = [];

        foreach ($fields->all() as $field) {
            $defs[$field->handle()] = $this->buildFieldDef($field);
        }

        return $defs;
    }
}
