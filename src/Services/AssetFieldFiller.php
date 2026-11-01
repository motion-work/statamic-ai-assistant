<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

/**
 * Fills a generated entry's empty image fields, through groups, sets and grid
 * rows: images the agent saved from the source first, then library assets.
 */
final class AssetFieldFiller
{
    public function __construct(private readonly EntryGeneratorAssetResolver $assets) {}

    /**
     * Fill every assets field in the blueprint tree (groups, replicator sets, grids)
     * when the value is empty. Assets are drawn from $preferred first (downloaded
     * by the save_remote_image tool), then from random paths in the field's container.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     */
    public function fillEmpty(array &$data, array &$displayData, Blueprint $blueprint, array &$warnings, ?PreferredAssetPaths $preferred = null): void
    {
        foreach ($blueprint->fields()->all() as $field) {
            $this->fillAssetsForField($field, $data, $displayData, $warnings, $preferred);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     */
    private function fillAssetsForField(Field $field, array &$data, array &$displayData, array &$warnings, ?PreferredAssetPaths $preferred): void
    {
        $handle = $field->handle();
        $type = $field->type();

        if ($type === 'section') {
            return;
        }

        if ($type === 'assets') {
            $this->fillSingleAssetField($field, $data, $displayData, $warnings, $preferred);

            return;
        }

        if ($type === 'group') {
            if (! isset($data[$handle]) || ! is_array($data[$handle])) {
                $data[$handle] = [];
            }
            if (! isset($displayData[$handle]) || ! is_array($displayData[$handle])) {
                $displayData[$handle] = $data[$handle];
            }

            foreach ($field->fieldtype()->fields()->all() as $child) {
                $this->fillAssetsForField($child, $data[$handle], $displayData[$handle], $warnings, $preferred);
            }

            return;
        }

        if (in_array($type, FieldTypes::RECURSIVE_TYPES, true)) {
            if (! isset($data[$handle]) || ! is_array($data[$handle])) {
                return;
            }
            if (! isset($displayData[$handle]) || ! is_array($displayData[$handle])) {
                $displayData[$handle] = $data[$handle];
            }

            $fieldtype = $field->fieldtype();

            foreach ($data[$handle] as $idx => &$row) {
                if (! is_array($row)) {
                    continue;
                }

                if (! isset($displayData[$handle][$idx]) || ! is_array($displayData[$handle][$idx])) {
                    $displayData[$handle][$idx] = $row;
                }

                if ($type === 'grid') {
                    $subfields = $fieldtype->fields();

                    foreach ($subfields->all() as $child) {
                        $this->fillAssetsForField($child, $row, $displayData[$handle][$idx], $warnings, $preferred);
                    }

                    continue;
                }

                $setType = $row['type'] ?? null;

                if (! $setType) {
                    continue;
                }

                $setFields = $fieldtype->fields($setType);

                foreach ($setFields->all() as $child) {
                    $this->fillAssetsForField($child, $row, $displayData[$handle][$idx], $warnings, $preferred);
                }
            }
            unset($row);

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     */
    private function fillSingleAssetField(Field $field, array &$data, array &$displayData, array &$warnings, ?PreferredAssetPaths $preferred): void
    {
        $handle = $field->handle();

        $current = $data[$handle] ?? null;

        $empty = $current === null || $current === '' || $current === [];

        if (! $empty) {
            return;
        }

        $config = $field->config();
        $maxFiles = max(1, (int) ($config['max_files'] ?? 1));
        $minFiles = max(0, (int) ($config['min_files'] ?? 0));
        $count = max($maxFiles, $minFiles);

        $picked = [];

        // Drain matching downloaded paths first so migrated entries actually
        // reference the page's own images instead of random container assets.
        $containerHandle = $this->assets->resolveContainerHandle($field);
        if ($preferred !== null && is_string($containerHandle) && $containerHandle !== '') {
            $picked = $preferred->takeForContainer($containerHandle, $count);
        }

        if (count($picked) < $count) {
            $remaining = $count - count($picked);
            $randomPaths = $this->assets->pickRandomPathsForField($field, $warnings);
            $picked = array_merge($picked, array_slice($randomPaths, 0, $remaining));
        }

        if ($picked === []) {
            return;
        }

        $value = $maxFiles === 1 ? $picked[0] : array_slice($picked, 0, $maxFiles);

        $data[$handle] = $value;
        $displayData[$handle] = $value;
    }
}
