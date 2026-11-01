<?php

namespace MotionWork\StatamicAiAssistant\Tools;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Statamic\Facades\AssetContainer;

/**
 * Lets the agent browse existing assets: containers → folders → files with
 * their metadata. Resolves "@folder:…" / "@asset:…" mentions from the chat and
 * feeds update_asset / use_assets with exact "container::path" references.
 */
class ListAssetsTool implements ChatTool
{
    private const MAX_ASSETS = 60;

    public function name(): string
    {
        return 'list_assets';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_assets',
                'description' => 'Browse existing assets. Without arguments: lists asset containers and their top-level folders. '
                    .'With container (and optional folder): lists that folder\'s files with their meta values (alt texts etc.), plus subfolders. '
                    .'Use it to resolve @folder:/@asset: references from the user, and to READ current metadata — '
                    .'questions like "does X have a French alt text?" are answered from the returned meta, never via update_asset. '
                    .'Asset references are "container::path".',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'container' => ['type' => 'string', 'description' => 'Asset container handle.'],
                        'folder' => ['type' => 'string', 'description' => 'Folder path within the container (omit for root).'],
                        'recursive' => ['type' => 'boolean', 'description' => 'Include files of all nested folders (default false).'],
                        'search' => ['type' => 'string', 'description' => 'Filter files by filename substring (applied before the listing cap).'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function handle(string $argumentsJson, ToolContext $context): array
    {
        $args = ToolArguments::decode($argumentsJson, $this->name());
        if ($args === null) {
            return ToolArguments::invalid();
        }

        $containerHandle = isset($args['container']) && is_string($args['container']) ? trim($args['container']) : '';

        if ($containerHandle === '') {
            return $this->listContainers();
        }

        $container = AssetContainer::findByHandle($containerHandle);
        if (! $container) {
            return ['ok' => false, 'error' => "Asset container \"{$containerHandle}\" not found. Available: ".AssetContainer::all()->map(fn ($c) => (string) $c->handle())->sort()->values()->implode(', ')];
        }

        $folder = isset($args['folder']) && is_string($args['folder']) ? trim($args['folder'], "/ \t") : '';
        $recursive = ($args['recursive'] ?? false) === true;

        $context->reportActivity((string) __('Browsing assets in :target', ['target' => $containerHandle.($folder !== '' ? '/'.$folder : '')]));

        $assets = $this->folderAssets($container, $folder, $recursive, $args['search'] ?? null);

        $total = $assets->count();

        // Every blueprint meta field (alt, alt_text_fr, …) is readable per row —
        // otherwise the model has no way to ANSWER "does X have a French alt
        // text?" and resorts to probing with update_asset writes.
        $metaFields = $container->blueprint()?->fields()->all()->keys()->values()->all() ?? [];

        $rows = $this->assetRows($assets->take(self::MAX_ASSETS), $container, $metaFields, $context);

        $result = [
            'ok' => true,
            'container' => $containerHandle,
            'folder' => $folder,
            'assets' => $rows,
            'subfolders' => $this->folderNames($container->folders($folder !== '' ? $folder : '/')),
            'meta_fields' => $metaFields,
            'meta_note' => 'Each asset row includes its non-empty meta values under "meta". A handle absent from "meta" is EMPTY for that asset — answer read-only questions from this, never by writing.',
        ];

        if ($total > self::MAX_ASSETS) {
            $result['note'] = 'Showing '.self::MAX_ASSETS." of {$total} assets — narrow the folder to see the rest.";
        }

        return $result;
    }

    private function folderAssets(mixed $container, string $folder, bool $recursive, mixed $search): Collection
    {
        $assets = $container->assets($folder !== '' ? $folder : '/', $recursive);

        $search = is_string($search) ? mb_strtolower(trim($search)) : '';
        if ($search !== '') {
            $assets = $assets->filter(fn ($asset) => str_contains(mb_strtolower($asset->basename()), $search))->values();
        }

        return $assets;
    }

    /**
     * @param  array<int, string>  $metaFields
     * @return array<int, array<string, mixed>>
     */
    private function assetRows(Collection $assets, mixed $container, array $metaFields, ToolContext $context): array
    {
        // Which assets already have a persisted .meta file (cached container
        // listing — one in-memory lookup, no per-asset I/O). We read meta ONLY
        // for those: calling ->get()/->meta() on an asset WITHOUT a meta file
        // makes Statamic generate it, which on a remote (S3) disk downloads the
        // file for its dimensions AND writes the meta back — and metaValue()
        // does that regeneration once PER MISSING FIELD. Browsing a folder then
        // turned into hundreds of S3 round-trips and hung the run. Assets that
        // never had meta simply show none here; the model can still pick them
        // by filename and analyze_image reads content when it actually needs it.
        $existingMeta = $container->metaFiles();

        return $assets->map(function ($asset) use ($metaFields, $existingMeta, $context) {
            // Keep the stream/queue job alive and cancellable across a long
            // listing — there is no other checkpoint inside this single call.
            $context->heartbeat();

            $row = [
                'ref' => $asset->containerHandle().'::'.$asset->path(),
                'filename' => $asset->basename(),
                'is_image' => (bool) $asset->isImage(),
            ];

            $meta = $existingMeta->contains($asset->metaPath()) ? $this->storedMeta($asset, $metaFields) : [];
            if ($meta !== []) {
                $row['meta'] = $meta;
            }

            return $row;
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function listContainers(): array
    {
        return [
            'ok' => true,
            'containers' => AssetContainer::all()->map(fn ($c) => [
                'handle' => (string) $c->handle(),
                'title' => (string) $c->title(),
                'folders' => $this->folderNames($c->folders()),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function folderNames(iterable $folders): array
    {
        return collect($folders)->take(40)->map(fn ($f) => (string) $f)->values()->all();
    }

    /**
     * Non-empty meta values of an asset that has a meta file. One meta read
     * (the existing yaml, no generation), then handles are pulled from the
     * in-memory data map — never a per-field ->get() that could regenerate.
     *
     * @param  array<int, string>  $metaFields
     * @return array<string, string>
     */
    private function storedMeta($asset, array $metaFields): array
    {
        $data = $metaFields !== [] ? Arr::get($asset->meta(), 'data', []) : [];
        $meta = [];

        foreach ($metaFields as $handle) {
            $value = is_array($data) ? ($data[$handle] ?? null) : null;
            if (is_string($value) && trim($value) !== '') {
                $meta[$handle] = $value;
            }
        }

        return $meta;
    }

    public function maxCalls(): ?int
    {
        return max(1, (int) config('statamic-ai-assistant.entry_generator_tool_max_cms_reads', 12));
    }
}
