<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Str;
use Statamic\Facades\Collection;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * What the "suggest" prompt needs about one set or field: its title,
 * instructions, inner fields with their options, and where it is used.
 */
final class HintSuggestionContext
{
    public function __construct(private BlueprintHintTargets $targets) {}

    /**
     * Inspect a single field handle across all blueprints and gather what the
     * LLM needs to suggest a description and writing guidelines for it.
     *
     * @return array{
     *   handle: string,
     *   title: string,
     *   type: string,
     *   instructions: string,
     *   options: array<int, string>,
     *   character_limit: ?int,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }|null
     */
    public function collectFieldContext(string $fieldHandle): ?array
    {
        if ($fieldHandle === '') {
            return null;
        }

        $found = null;

        foreach ($this->targets->discoverFields() as $row) {
            if ($row['handle'] === $fieldHandle) {
                $found = $row;
                break;
            }
        }

        if ($found === null) {
            return null;
        }

        // Sample config details (instructions, options, character limit) from
        // the first blueprint that carries the field.
        $instructions = '';
        $options = [];
        $characterLimit = null;

        foreach (Collection::all() as $collection) {
            foreach ($collection->entryBlueprints()->reject->hidden() as $blueprint) {
                $field = $blueprint->fields()->all()->get($fieldHandle);

                if ($field === null) {
                    continue;
                }

                $instructions = trim((string) ($field->instructions() ?? ''));
                $options = $this->extractFieldOptions($field);
                $limit = $field->get('character_limit');
                $characterLimit = is_numeric($limit) ? (int) $limit : null;

                break 2;
            }
        }

        return [
            'handle' => $found['handle'],
            'title' => $found['title'],
            'type' => $found['type'],
            'instructions' => $instructions,
            'options' => $options,
            'character_limit' => $characterLimit,
            'locations' => $found['locations'],
        ];
    }

    /**
     * Inspect a single set across all blueprints and gather everything
     * the LLM needs to suggest a description and usage tips.
     *
     * Returns null when the handle is not found in any blueprint.
     *
     * @return array{
     *   handle: string,
     *   title: string,
     *   instructions: string,
     *   inner_fields: array<int, array{handle: string, type: string, display: string, instructions: string, options: array<int, string>}>,
     *   locations: array<int, array{collection: string, blueprint: string, field: string}>
     * }|null
     */
    public function collectSetContext(string $setHandle): ?array
    {
        if ($setHandle === '') {
            return null;
        }

        $title = '';
        $instructions = '';
        $innerFields = [];
        $locations = [];

        $this->targets->eachTopLevelField(function ($field, array $where) use ($setHandle, &$title, &$instructions, &$innerFields, &$locations) {
            $this->targets->eachSetIn($field, function ($owner, string $handle, array $setConfig, $setFields) use ($setHandle, $where, &$title, &$instructions, &$innerFields, &$locations) {
                if ($handle !== $setHandle) {
                    return;
                }

                if ($title === '' && is_string($setConfig['display'] ?? null)) {
                    $title = $setConfig['display'];
                }

                if ($instructions === '' && is_string($setConfig['instructions'] ?? null)) {
                    $instructions = trim($setConfig['instructions']);
                }

                $this->targets->addLocation($locations, $where, (string) ($owner->display() ?: $owner->handle()));

                // Every use of a set shares one config; sample its fields once.
                if ($innerFields === []) {
                    foreach ($setFields->all() as $f) {
                        $innerFields[] = [
                            'handle' => (string) $f->handle(),
                            'type' => (string) $f->type(),
                            'display' => (string) ($f->display() ?: $f->handle()),
                            'instructions' => (string) ($f->instructions() ?? ''),
                            'options' => $this->extractFieldOptions($f),
                        ];
                    }
                }
            });
        });

        if ($locations === [] && $title === '') {
            return null;
        }

        if ($title === '') {
            $title = Str::headline(str_replace('_', ' ', $setHandle));
        }

        return [
            'handle' => $setHandle,
            'title' => $title,
            'instructions' => $instructions,
            'inner_fields' => $innerFields,
            'locations' => array_values($locations),
        ];
    }

    /**
     * Pull human-readable option labels (or values) from a field config.
     *
     * @return array<int, string>
     */
    private function extractFieldOptions(Field $field): array
    {
        $options = $field->get('options', []);

        if (! is_array($options) || $options === []) {
            return [];
        }

        $out = [];

        foreach ($options as $key => $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            } elseif (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 12);
    }
}
