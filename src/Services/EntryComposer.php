<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * What the Claude connector needs to compose an entry itself: the blueprint
 * brief and block schemas the agent's prompt is built from, and the mapping of
 * the composed JSON onto stored field data. Shares the generator's schema,
 * prompt and mapper, so a composed entry is stored like a generated one.
 */
final class EntryComposer
{
    public function __construct(
        private readonly FieldSchemaBuilder $schema,
        private readonly GenerationPrompt $prompt,
        private readonly FieldValueMapper $mapper,
        private readonly EntryGeneratorAssetResolver $assetResolver,
    ) {}

    /**
     * For a model that writes the entry itself (the Claude connector): the
     * same field schema, block hints, site instructions, glossary and style
     * rules the agent's own prompt carries, as one brief. Block fields are
     * listed only for $blocksWithFields; the catalog still describes every
     * block, and {@see blockSchemas()} returns the fields of the ones chosen.
     * Descriptions repeated across fields are written once, as notes.
     *
     * @param  array<int, string>  $blocksWithFields
     */
    public function compositionBrief(Blueprint $blueprint, string $siteHandle, array $blocksWithFields = []): string
    {
        $schema = $this->schema->buildFieldSchema($blueprint, $siteHandle);
        foreach ($schema as &$field) {
            if (is_array($field['sets'] ?? null)) {
                $field['sets'] = array_intersect_key($field['sets'], array_flip($blocksWithFields));
                $field['sets_note'] = 'set_layout_catalog describes every block. "sets" lists the fields only of blocks already requested; call get_block_schemas for the fields of the blocks you choose.';
            }
        }
        unset($field);

        [$schema, $notes] = $this->prompt->hoistRepeatedDescriptions($this->prompt->stripSchemaNoise($schema));

        return $this->prompt->buildSystemMessage($schema, $siteHandle).$this->prompt->fieldNotesBlock($notes);
    }

    /**
     * The fields of the named blocks, per replicator/components field.
     *
     * @param  array<int, string>  $handles
     * @return array{blocks: array<string, array<string, mixed>>, notes: array<int, string>, unknown: array<int, string>}
     */
    public function blockSchemas(Blueprint $blueprint, string $siteHandle, array $handles): array
    {
        $blocks = [];
        $found = [];
        foreach ($this->schema->buildFieldSchema($blueprint, $siteHandle) as $fieldHandle => $field) {
            foreach ($handles as $handle) {
                if (isset($field['sets'][$handle])) {
                    $blocks[$fieldHandle][$handle] = $field['sets'][$handle];
                    $found[] = $handle;
                }
            }
        }

        [$blocks, $notes] = $this->prompt->hoistRepeatedDescriptions($this->prompt->stripSchemaNoise($blocks));

        return [
            'blocks' => $blocks,
            'notes' => $notes,
            'unknown' => array_values(array_diff($handles, $found)),
        ];
    }

    /**
     * Map composed JSON onto stored field data, as for a generated entry.
     * With $partial only the given fields are mapped and no image is filled in.
     *
     * @param  array<string, mixed>  $composed
     * @return array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: array<int, string>}
     */
    public function mapComposedData(array $composed, Blueprint $blueprint, string $siteHandle, bool $partial): array
    {
        $mapped = $this->mapper->mapToFieldData($composed, $blueprint, $siteHandle, '', null, true);
        $mapped['data'] = $this->carryAssetPaths($composed, $mapped['data'], $blueprint->fields()->all());
        if ($partial) {
            return $mapped;
        }

        // Unlike a generated entry: a library image only fills a required
        // image field left empty (an optional slot with a random picture
        // changes how a block renders), empty links stay empty, and other
        // required fields are not filled with placeholders but reported by
        // validation, so the model writes them.
        ['data' => $data, 'displayData' => $displayData, 'warnings' => $warnings] = $mapped;
        foreach ($blueprint->fields()->all() as $field) {
            $handle = $field->handle();
            if ($field->type() === 'assets' && $field->isRequired() && empty($data[$handle])) {
                $picked = $this->assetResolver->pickReplacementForField($field, $warnings);
                if ($picked !== null) {
                    $data[$handle] = $picked;
                    $displayData[$handle] = $picked;
                }
            }
        }

        return ['data' => $data, 'displayData' => $displayData, 'warnings' => $warnings];
    }

    /**
     * The mapper skips image fields, since the agent describes images rather
     * than naming them. A model that names an existing file keeps it, at the
     * top level, in a group, a set or a grid row, if the field may use it.
     *
     * @param  array<string, mixed>  $composed
     * @param  array<string, mixed>  $mapped
     * @param  iterable<Field>  $fields
     * @return array<string, mixed>
     */
    private function carryAssetPaths(array $composed, array $mapped, iterable $fields): array
    {
        foreach ($fields as $field) {
            $handle = $field->handle();
            if (! array_key_exists($handle, $composed)) {
                continue;
            }
            $value = $composed[$handle];

            if ($field->type() === 'assets') {
                $paths = $this->usableAssetPaths($field, $value);
                if ($paths !== []) {
                    $mapped[$handle] = (int) ($field->config()['max_files'] ?? 0) === 1 ? $paths[0] : $paths;
                }

                continue;
            }

            if (is_array($value) && is_array($mapped[$handle] ?? null)) {
                $mapped[$handle] = $this->carryNestedAssetPaths($field, $value, $mapped[$handle]);
            }
        }

        return $mapped;
    }

    /**
     * @return array<int, string>
     */
    private function usableAssetPaths(Field $field, mixed $value): array
    {
        $paths = [];
        foreach ((array) $value as $path) {
            if (is_string($path) && $path !== '' && $this->assetResolver->fieldHasAssetPath($field, ltrim($path, '/'))) {
                $paths[] = ltrim($path, '/');
            }
        }

        return $paths;
    }

    /**
     * Into a group, each grid row, or each set that kept its type.
     *
     * @param  array<int|string, mixed>  $value
     * @param  array<int|string, mixed>  $mapped
     * @return array<int|string, mixed>
     */
    private function carryNestedAssetPaths(Field $field, array $value, array $mapped): array
    {
        $type = $field->type();

        if (in_array($type, FieldTypes::GROUP_TYPES, true)) {
            return $this->carryAssetPaths($value, $mapped, $field->fieldtype()->fields()->all());
        }

        foreach ($value as $i => $row) {
            if (! is_array($row) || ! is_array($mapped[$i] ?? null)) {
                continue;
            }

            if ($type === 'grid') {
                $mapped[$i] = $this->carryAssetPaths($row, $mapped[$i], $field->fieldtype()->fields()->all());
            } elseif (in_array($type, ['replicator', 'components'], true) && is_string($row['type'] ?? null) && ($mapped[$i]['type'] ?? null) === $row['type']) {
                $mapped[$i] = $this->carryAssetPaths($row, $mapped[$i], $field->fieldtype()->fields($row['type'])->all());
            }
        }

        return $mapped;
    }
}
