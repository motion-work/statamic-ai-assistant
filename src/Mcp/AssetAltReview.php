<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Support\Collection;
use MotionWork\StatamicAiAssistant\Mcp\Concerns\ResolvesViewableAssetContainer;

/**
 * Batches library images for the connected model to write alt text.
 */
final class AssetAltReview
{
    use ResolvesViewableAssetContainer;

    private const BATCH_SIZE = 6;

    public function __construct(
        private ConnectorContext $context,
        private AssetAltLocaleResolver $locales,
        private AssetReviewThumbnail $thumbnails,
    ) {}

    /**
     * @param  array<int, string>|null  $locales
     * @return array<string, mixed>
     */
    public function review(?string $containerHandle, ?string $folder, ?array $locales, ?string $scope, ?string $cursor): array
    {
        $container = $this->viewableAssetContainer($containerHandle);
        if (is_array($container)) {
            return $container;
        }

        $folder = trim((string) $folder, '/');
        $scope = strtolower(trim((string) ($scope ?? 'missing')));
        if (! in_array($scope, ['missing', 'all'], true)) {
            return ['error' => 'invalid_scope', 'message' => 'scope must be "missing" or "all".'];
        }

        $requestedLocales = $this->normalizeLocales($locales);
        if ($requestedLocales === []) {
            return ['error' => 'missing_locales', 'message' => 'Pass at least one locale (e.g. de, fr) to generate alt text for.'];
        }

        $metaFields = $container->blueprint()?->fields()->all()->keys()->all() ?? [];
        $localeMap = $this->locales->map($requestedLocales, $metaFields);
        if ($localeMap['locale_handles'] === []) {
            return [
                'error' => 'no_alt_fields',
                'message' => 'This asset container has no alt meta fields matching the requested locales.',
                'requested_locales' => $requestedLocales,
                'alt_fields' => $localeMap['alt_fields'],
            ];
        }

        $targetHandles = array_values(array_unique($localeMap['locale_handles']));
        $filtered = $this->imageAssets($container, $folder)
            ->filter(fn ($asset) => $this->needsReview($asset, $targetHandles, $scope))
            ->values();

        $offset = max(0, (int) $cursor);
        $batch = $filtered->slice($offset, self::BATCH_SIZE)->values();
        $remaining = max(0, $filtered->count() - $offset - $batch->count());
        $nextCursor = $remaining > 0 ? (string) ($offset + $batch->count()) : null;

        [$items, $imageBlocks] = $this->batchRows($batch, $targetHandles);

        $payload = [
            'ok' => true,
            'container' => $container->handle(),
            'folder' => $folder,
            'scope' => $scope,
            'brief' => AssetAltBrief::TEXT,
            'locale_handles' => $localeMap['locale_handles'],
            'unmapped_locales' => $localeMap['unmapped_locales'],
            'images' => $items,
            'cursor' => $nextCursor,
            'remaining' => $remaining,
            'total_matching' => $filtered->count(),
            'message' => $batch->isEmpty()
                ? 'No images match this scope and locale set.'
                : 'Write alt text for each image, then call preview_asset_alts with ref and values (blueprint handles from locale_handles). '
                    .($nextCursor !== null ? 'Call review_images again with cursor '.$nextCursor.' for the next batch.' : 'No further batches.'),
        ];

        return ['_content_blocks' => $this->contentBlocks($payload, $imageBlocks)];
    }

    /**
     * @param  array<int, string>  $targetHandles
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function batchRows(Collection $batch, array $targetHandles): array
    {
        $items = [];
        $imageBlocks = [];

        foreach ($batch as $asset) {
            $ref = $asset->containerHandle().'::'.$asset->path();
            $row = [
                'ref' => $ref,
                'path' => $asset->path(),
                'current' => $this->currentMeta($asset, $targetHandles),
            ];
            $note = $this->previewNote($asset);
            if ($note !== null) {
                $row['note'] = $note;
            } else {
                $thumb = $this->thumbnails->jpegBase64(
                    (string) $asset->contents(),
                    (string) ($asset->mimeType() ?? 'image/jpeg'),
                );
                if ($thumb === null) {
                    $row['note'] = 'No inline preview (format/size/GD). Use list_assets meta and context.';
                } else {
                    $imageBlocks[] = [
                        'type' => 'image',
                        'mimeType' => $thumb['mime'],
                        'data' => $thumb['data'],
                        'ref' => $ref,
                    ];
                }
            }
            $items[] = $row;
        }

        return [$items, $imageBlocks];
    }

    private function previewNote($asset): ?string
    {
        if ($asset->isSvg()) {
            return 'SVG skipped for inline preview — describe from filename/context or ask the user.';
        }

        $contents = $asset->contents();
        if (! is_string($contents) || $contents === '') {
            return 'Could not read file bytes for preview.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $imageBlocks
     * @return array<int, array<string, mixed>>
     */
    private function contentBlocks(array $payload, array $imageBlocks): array
    {
        $blocks = [
            ['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ];

        foreach ($imageBlocks as $block) {
            $ref = $block['ref'];
            unset($block['ref']);
            $blocks[] = $block;
            $blocks[] = ['type' => 'text', 'text' => 'Image above: '.$ref];
        }

        return $blocks;
    }

    /**
     * @param  array<int, string>|null  $locales
     * @return array<int, string>
     */
    private function normalizeLocales(?array $locales): array
    {
        if ($locales === null) {
            return [];
        }

        $out = [];
        foreach ($locales as $locale) {
            if (is_string($locale) && trim($locale) !== '') {
                $out[] = trim($locale);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<int, string>  $handles
     * @return array<string, string>
     */
    private function currentMeta($asset, array $handles): array
    {
        $current = [];
        foreach ($handles as $handle) {
            $value = (string) ($asset->get($handle) ?? '');
            if ($value !== '') {
                $current[$handle] = $value;
            }
        }

        return $current;
    }

    private function needsReview($asset, array $targetHandles, string $scope): bool
    {
        if (! $asset->isImage() && ! $asset->isSvg()) {
            return false;
        }

        if ($scope === 'all') {
            return true;
        }

        foreach ($targetHandles as $handle) {
            if (trim((string) $asset->get($handle)) === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, mixed>
     */
    private function imageAssets(mixed $container, string $folder): Collection
    {
        return $container->assets($folder === '' ? '/' : $folder, true)
            ->filter(fn ($asset) => $asset->isImage() || $asset->isSvg())
            ->sortBy(fn ($asset) => $asset->path())
            ->values();
    }
}
