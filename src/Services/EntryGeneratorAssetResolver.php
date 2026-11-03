<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Collection;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\AssetContainer;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;

class EntryGeneratorAssetResolver
{
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
        if ($field->type() === 'assets') {
            $handle = $this->resolveContainerHandle($field);

            return is_string($handle) && $handle !== '' ? $handle : null;
        }

        foreach ($this->nestedFields($field) as $child) {
            $handle = $this->findAssetContainerInField($child);

            if ($handle !== null) {
                return $handle;
            }
        }

        return null;
    }

    /**
     * A group's fields, a grid's row fields, or every set's fields in order.
     *
     * @return iterable<Field>
     */
    private function nestedFields(Field $field): iterable
    {
        $type = $field->type();

        if ($type === 'group' || $type === 'grid') {
            yield from $field->fieldtype()->fields()->all();
        } elseif (in_array($type, FieldTypes::RECURSIVE_TYPES, true)) {
            $fieldtype = $field->fieldtype();
            foreach ($fieldtype->flattenedSetsConfig() as $setHandle => $setConfig) {
                yield from $fieldtype->fields($setHandle)->all();
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function pickRandomPathsForField(Field $field, array &$warnings): array
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
     * Public listing of asset paths in the field's container — used by the AI
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
     * A path named for an assets field, when the container's disk has that
     * file and the field allows it. The field's folder is only a limit when
     * the field restricts to it; otherwise it is where the Control Panel
     * browser starts. Images and other files (a GLB, a PDF) both count.
     * Returns null when this field cannot store that path.
     */
    public function usableAssetPath(Field $field, string $path): ?string
    {
        $path = ltrim(trim($path), '/');
        $handle = $this->resolveContainerHandle($field);
        if ($path === '' || str_contains($path, '..') || ! is_string($handle)) {
            return null;
        }

        $container = AssetContainer::find($handle);
        if (! $container) {
            return null;
        }

        $prefix = $container->handle().'::';
        if (str_starts_with($path, $prefix)) {
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }
        if ($path === '' || ! $container->disk()->exists($path) || $container->disk()->isDirectory($path)) {
            return null;
        }

        $folder = $field->config()['folder'] ?? '';
        if (($field->config()['restrict'] ?? false) === true && is_string($folder) && trim($folder, '/') !== '') {
            $folder = trim($folder, '/');
            if (! str_starts_with($path, $folder.'/')) {
                return null;
            }
        }

        return $path;
    }

    /**
     * Pick a replacement value for ONE asset field, respecting its max_files /
     * min_files config. Single-file fields return a string path; multi-file
     * fields return an array of paths. Returns null when the container has no
     * candidates. Used by the AI agent UPDATE flow as a safe alternative to
     * AssetFieldFiller::fillEmpty() (which iterates the whole blueprint and would
     * over-fill nested fields the user did not ask about).
     */
    public function pickReplacementForField(Field $field, array &$warnings, ?PreferredAssetPaths $preferred = null): mixed
    {
        $config = $field->config();
        $maxFiles = max(1, (int) ($config['max_files'] ?? 1));
        $minFiles = max(0, (int) ($config['min_files'] ?? 0));
        $count = max($maxFiles, $minFiles);

        $picked = [];

        $containerHandle = $this->resolveContainerHandle($field);
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
     * Resolve the asset container handle for a field the same way Statamic's own
     * Assets fieldtype does: an explicit `container` config wins; otherwise, when
     * the site has exactly one asset container, that sole container is used.
     * Returns null only when nothing resolves (no config and 0 or >1 containers).
     *
     * Statamic lets an assets field OMIT `container` whenever a single container
     * exists — a common single-container setup, and the usual shape of fields
     * imported into replicator/components sets. Reading `config('container')`
     * directly (as the fill + random-fallback paths used to) then resolves to
     * null and the field silently stays empty. Routing every container lookup
     * through here fixes that for EVERY assets field in the blueprint tree
     * (top-level, groups, replicator/components sets, grids) — not one section.
     */
    public function resolveContainerHandle(Field $field): ?string
    {
        $handle = $field->config()['container'] ?? null;

        if (is_string($handle) && $handle !== '') {
            return $handle;
        }

        $containers = AssetContainer::all();

        return $containers->count() === 1
            ? (string) $containers->first()->handle()
            : null;
    }

    /**
     * @return Collection<int, StatamicAsset|AssetContract>
     */
    private function getCandidateAssets(Field $field): Collection
    {
        $config = $field->config();
        $handle = $this->resolveContainerHandle($field);

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
