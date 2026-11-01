<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;

/**
 * Asset fields on the update path: the create path hides them from the model,
 * so updates add them to the schema (also inside groups) and then resolve the
 * replacement images the model asked for. Fields it did not mention keep
 * their stored image.
 */
final class UpdateAssetFields
{
    public function __construct(private EntryGeneratorAssetResolver $assetResolver) {}

    /**
     * Walk the blueprint recursively (top level + groups) and inject
     * asset_description schema entries with `available_assets` for every assets
     * field at any nesting depth. Returns one {field, path} pair per asset
     * field so the resolver step knows where to write the picked path.
     *
     * Replicator/components/grid are intentionally NOT walked here: their dynamic
     * sets are already exposed via the (inside-sets) asset_description rule in
     * buildSystemMessage, and resolving inside dynamic blocks needs different
     * indexing. That can be a follow-up if needed.
     *
     * @return array<int, array{field: Field, path: array<int, string>}>
     */
    public function augmentSchemaWithAssetFields(array &$fieldSchema, Blueprint $blueprint): array
    {
        $assetFields = [];
        $listingCap = max(0, (int) config('statamic-ai-assistant.agent_asset_listing_cap', 100));

        $this->walkAndInjectAssets(
            $blueprint->fields()->all(),
            $fieldSchema,
            [],
            $listingCap,
            $assetFields,
        );

        return $assetFields;
    }

    /**
     * @param  iterable<int, Field>  $fields  Array or Statamic Fields collection
     * @param  array<int, string>  $path
     * @param  array<int, array{field: Field, path: array<int, string>}>  $assetFields
     */
    private function walkAndInjectAssets(iterable $fields, array &$schemaSlice, array $path, int $listingCap, array &$assetFields): void
    {
        foreach ($fields as $field) {
            $handle = $field->handle();
            $type = $field->type();
            $thisPath = array_merge($path, [$handle]);

            if ($type === 'assets') {
                $availablePaths = $listingCap > 0
                    ? $this->assetResolver->listFieldAssetPaths($field, $listingCap)
                    : [];
                $instructions = $field->instructions();

                $entry = [
                    'label' => $field->display(),
                    'generatable' => true,
                    'type' => 'asset_description',
                    'description' => ($instructions ? $instructions.' ' : '')
                        .'OMIT this key entirely to keep the current asset. '
                        .'To replace the image, return EITHER (a) one exact path from `available_assets` below '
                        .'(preferred — lets you respect user constraints like "not from the X folder"), '
                        .'OR (b) a short description if nothing in the list fits, in which case the system picks a fitting asset randomly. '
                        .'Paths are relative to the field\'s asset container; do not prefix with a slash.',
                    'available_assets' => $availablePaths,
                ];

                if ($listingCap > 0 && count($availablePaths) === $listingCap) {
                    $entry['available_assets_note'] = "Only the first {$listingCap} paths are listed; the container may hold more. If none of the listed paths fits, return a description and the system picks from the full set.";
                }

                $schemaSlice[$handle] = $entry;
                $assetFields[] = ['field' => $field, 'path' => $thisPath];

                Log::info('[entry-update] asset field listing', [
                    'field_path' => implode('.', $thisPath),
                    'container' => $field->config()['container'] ?? null,
                    'folder' => $field->config()['folder'] ?? null,
                    'available_assets_count' => count($availablePaths),
                    'sample' => array_slice($availablePaths, 0, 5),
                ]);

                continue;
            }

            if (in_array($type, FieldTypes::GROUP_TYPES, true)) {
                // The group's schema entry already exists from buildFieldSchema
                // — we just need to ensure its `fields` slice is mutable. If
                // every sub-field was previously skipped (e.g. all were assets),
                // the group entry might be missing — synthesize a minimal one.
                if (! isset($schemaSlice[$handle]) || ! is_array($schemaSlice[$handle])) {
                    $schemaSlice[$handle] = [
                        'label' => $field->display(),
                        'generatable' => true,
                        'type' => 'group',
                        'fields' => [],
                    ];
                }
                if (! isset($schemaSlice[$handle]['fields']) || ! is_array($schemaSlice[$handle]['fields'])) {
                    $schemaSlice[$handle]['fields'] = [];
                }

                $this->walkAndInjectAssets(
                    $field->fieldtype()->fields()->all(),
                    $schemaSlice[$handle]['fields'],
                    $thisPath,
                    $listingCap,
                    $assetFields,
                );
            }
        }
    }

    /**
     * @param  array{data: array<string, mixed>, displayData: array<string, mixed>, warnings: string[]}  $result
     * @param  array<int, array{field: Field, path: array<int, string>}>  $assetFields
     * @param  array<string, mixed>  $parsedData
     */
    public function resolveUpdateAssetReplacements(array &$result, StatamicEntry $entry, array $assetFields, array $parsedData): void
    {
        if ($assetFields === []) {
            return;
        }

        $entryData = is_array($entry->data()->all()) ? $entry->data()->all() : [];

        $directPicks = [];   // [pathStr => ['path' => array, 'value' => resolvedValue]]
        $randomPicks = [];   // [['field' => ..., 'path' => array]]
        $rawByPath = [];     // [pathStr => llm value] for logging

        foreach ($assetFields as $info) {
            $field = $info['field'];
            $path = $info['path'];
            $pathStr = implode('.', $path);

            $value = $this->getNestedValue($parsedData, $path);
            if ($value === null) {
                continue; // LLM omitted this field — preserve existing
            }
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $rawByPath[$pathStr] = $value;

            $candidate = ltrim(trim($value), '/');
            if ($candidate !== '' && $this->assetResolver->fieldHasAssetPath($field, $candidate)) {
                $config = $field->config();
                $maxFiles = max(1, (int) ($config['max_files'] ?? 1));
                $resolved = $maxFiles === 1 ? $candidate : [$candidate];
                $directPicks[$pathStr] = ['path' => $path, 'value' => $resolved];
            } else {
                $randomPicks[] = ['field' => $field, 'path' => $path];
            }
        }

        Log::info('[entry-update] asset replacement resolution', [
            'direct_picks' => array_map(fn ($p) => ['path' => implode('.', $p['path']), 'value' => $p['value']], array_values($directPicks)),
            'random_picks' => array_map(fn ($p) => implode('.', $p['path']), $randomPicks),
            'llm_raw_for_assets' => $rawByPath,
        ]);

        foreach ($directPicks as $info) {
            $this->seedNestedFromExisting($result['data'], $info['path'], $entryData);
            $this->seedNestedFromExisting($result['displayData'], $info['path'], $entryData);
            $this->setNestedValue($result['data'], $info['path'], $info['value']);
            $this->setNestedValue($result['displayData'], $info['path'], $info['value']);
        }

        foreach ($randomPicks as $pick) {
            $picked = $this->assetResolver->pickReplacementForField($pick['field'], $result['warnings'], null);
            if ($picked === null || $picked === '' || $picked === []) {
                $result['warnings'][] = __(':field: no replacement image was found in the asset container; current image kept.', [
                    'field' => implode('.', $pick['path']),
                ]);

                continue;
            }
            $this->seedNestedFromExisting($result['data'], $pick['path'], $entryData);
            $this->seedNestedFromExisting($result['displayData'], $pick['path'], $entryData);
            $this->setNestedValue($result['data'], $pick['path'], $picked);
            $this->setNestedValue($result['displayData'], $pick['path'], $picked);
        }
    }

    /**
     * Read a value from $data following a path of keys; returns null if any key is missing.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $path
     */
    private function getNestedValue(array $data, array $path): mixed
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (! is_array($cursor) || ! array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * Ensure each parent key along the path exists in $data, copying any
     * existing slice from $entryData so siblings (e.g. video_enabled in an
     * image_or_video group) survive the eventual save.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $path
     * @param  array<string, mixed>  $entryData
     */
    private function seedNestedFromExisting(array &$data, array $path, array $entryData): void
    {
        if (count($path) <= 1) {
            return;
        }

        $cursor = &$data;
        for ($i = 0; $i < count($path) - 1; $i++) {
            $key = $path[$i];
            if (! isset($cursor[$key]) || ! is_array($cursor[$key])) {
                $existing = $this->getNestedValue($entryData, array_slice($path, 0, $i + 1));
                $cursor[$key] = is_array($existing) ? $existing : [];
            }
            $cursor = &$cursor[$key];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $path
     */
    private function setNestedValue(array &$data, array $path, mixed $value): void
    {
        $cursor = &$data;
        for ($i = 0; $i < count($path) - 1; $i++) {
            $key = $path[$i];
            if (! isset($cursor[$key]) || ! is_array($cursor[$key])) {
                $cursor[$key] = [];
            }
            $cursor = &$cursor[$key];
        }
        $cursor[end($path)] = $value;
    }
}
