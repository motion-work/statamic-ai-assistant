<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use Illuminate\Support\Collection;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

class EntryGeneratorAssetResolver
{
    /** @var array<int, string> */
    private const RECURSIVE_CONTAINER_TYPES = ['replicator', 'components', 'grid'];

    /**
     * Fill every assets field in the blueprint tree (groups, replicator sets, grids)
     * when the value is empty. Assets are drawn from $preferred first (downloaded
     * by the save_remote_image tool), then from random paths in the field's container.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $displayData
     */
    public function fillAssetFieldsWithRandom(array &$data, array &$displayData, Blueprint $blueprint, array &$warnings, ?PreferredAssetPaths $preferred = null): void
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

        if (in_array($type, self::RECURSIVE_CONTAINER_TYPES, true)) {
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

                try {
                    $setFields = $fieldtype->fields($setType);
                } catch (\Exception) {
                    continue;
                }

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
        $containerHandle = $config['container'] ?? null;
        if ($preferred !== null && is_string($containerHandle) && $containerHandle !== '') {
            $picked = $preferred->takeForContainer($containerHandle, $count);
        }

        if (count($picked) < $count) {
            $remaining = $count - count($picked);
            $randomPaths = $this->pickRandomPathsForField($field, $warnings);
            $picked = array_merge($picked, array_slice($randomPaths, 0, $remaining));
        }

        if ($picked === []) {
            return;
        }

        $value = $maxFiles === 1 ? $picked[0] : array_slice($picked, 0, $maxFiles);

        $data[$handle] = $value;
        $displayData[$handle] = $value;
    }

    /**
     * Handle of the first assets-field container found anywhere in the blueprint
     * tree (top-level fields, groups, replicator/components sets, grids). Used to
     * steer images saved by the save_remote_image tool into the container the
     * entry's own image fields point at, so they actually get assigned. Returns
     * null when the blueprint has no assets fields.
     */
    public function primaryAssetContainer(Blueprint $blueprint): ?string
    {
        foreach ($blueprint->fields()->all() as $field) {
            $handle = $this->findAssetContainerInField($field);

            if ($handle !== null) {
                return $handle;
            }
        }

        return null;
    }

    private function findAssetContainerInField(Field $field): ?string
    {
        $type = $field->type();

        if ($type === 'assets') {
            $handle = $field->config()['container'] ?? null;

            return is_string($handle) && $handle !== '' ? $handle : null;
        }

        if ($type === 'group') {
            foreach ($field->fieldtype()->fields()->all() as $child) {
                $handle = $this->findAssetContainerInField($child);

                if ($handle !== null) {
                    return $handle;
                }
            }

            return null;
        }

        if (in_array($type, self::RECURSIVE_CONTAINER_TYPES, true)) {
            $fieldtype = $field->fieldtype();

            if ($type === 'grid') {
                foreach ($fieldtype->fields()->all() as $child) {
                    $handle = $this->findAssetContainerInField($child);

                    if ($handle !== null) {
                        return $handle;
                    }
                }

                return null;
            }

            foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
                try {
                    $setFields = $fieldtype->fields($setHandle);
                } catch (\Exception) {
                    continue;
                }

                foreach ($setFields->all() as $child) {
                    $handle = $this->findAssetContainerInField($child);

                    if ($handle !== null) {
                        return $handle;
                    }
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function pickRandomPathsForField(Field $field, array &$warnings): array
    {
        $config = $field->config();
        $maxFiles = max(1, (int) ($config['max_files'] ?? 1));
        $minFiles = max(0, (int) ($config['min_files'] ?? 0));
        $count = max($maxFiles, $minFiles);

        $candidates = $this->getCandidateAssets($field);

        if ($candidates->isEmpty()) {
            $warnings[] = __('No usable assets found in the container for :field.', ['field' => $field->display()]);

            return [];
        }

        $pool = $candidates->map(fn ($a) => $a->path())->values()->all();

        if ($pool === []) {
            return [];
        }

        if (count($pool) >= $count) {
            shuffle($pool);

            return array_slice($pool, 0, $count);
        }

        $out = [];

        for ($i = 0; $i < $count; $i++) {
            $out[] = $pool[array_rand($pool)];
        }

        return $out;
    }

    /**
     * Public listing of asset paths in the field's container — used by the BOLD
     * agent UPDATE flow so the LLM can pick a specific image (e.g. respecting
     * user constraints like "not from the set previews folder") instead of
     * relying on the random pick fallback.
     *
     * @return array<int, string>
     */
    public function listFieldAssetPaths(Field $field, int $limit = 100): array
    {
        $paths = $this->getCandidateAssets($field)
            ->map(fn ($a) => $a->path())
            ->values()
            ->all();

        if ($limit > 0 && count($paths) > $limit) {
            $paths = array_slice($paths, 0, $limit);
        }

        return $paths;
    }

    public function fieldHasAssetPath(Field $field, string $path): bool
    {
        foreach ($this->getCandidateAssets($field) as $asset) {
            if ($asset->path() === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pick a replacement value for ONE asset field, respecting its max_files /
     * min_files config. Single-file fields return a string path; multi-file
     * fields return an array of paths. Returns null when the container has no
     * candidates. Used by the BOLD agent UPDATE flow as a safe alternative to
     * fillAssetFieldsWithRandom (which iterates the whole blueprint and would
     * over-fill nested fields the user did not ask about).
     */
    public function pickReplacementForField(Field $field, array &$warnings, ?PreferredAssetPaths $preferred = null): mixed
    {
        $config = $field->config();
        $maxFiles = max(1, (int) ($config['max_files'] ?? 1));
        $minFiles = max(0, (int) ($config['min_files'] ?? 0));
        $count = max($maxFiles, $minFiles);

        $picked = [];

        $containerHandle = $config['container'] ?? null;
        if ($preferred !== null && is_string($containerHandle) && $containerHandle !== '') {
            $picked = $preferred->takeForContainer($containerHandle, $count);
        }

        if (count($picked) < $count) {
            $remaining = $count - count($picked);
            $randomPaths = $this->pickRandomPathsForField($field, $warnings);
            $picked = array_merge($picked, array_slice($randomPaths, 0, $remaining));
        }

        if ($picked === []) {
            return null;
        }

        return $maxFiles === 1 ? $picked[0] : array_slice($picked, 0, $maxFiles);
    }

    /**
     * @return Collection<int, StatamicAsset|AssetContract>
     */
    private function getCandidateAssets(Field $field): Collection
    {
        $config = $field->config();
        $handle = $config['container'] ?? null;

        if (! $handle) {
            return collect();
        }

        $container = AssetContainer::find($handle);

        if (! $container) {
            return collect();
        }

        $folder = $config['folder'] ?? null;

        if ($folder) {
            $assets = $container->assets($folder, true);
        } else {
            $assets = $container->assets();
        }

        $images = $assets->filter(fn (AssetContract $a) => $a->isImage())->values();

        if ($images->isNotEmpty()) {
            $only = $images->filter(fn ($a) => $a instanceof StatamicAsset)->values();

            return $only->isNotEmpty() ? $only : $images;
        }

        $only = $assets->filter(fn ($a) => $a instanceof StatamicAsset)->values();

        return $only->isNotEmpty() ? $only : $assets->values();
    }
}
