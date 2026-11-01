<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\EntrySearch;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Assets\AssetFolder;
use Statamic\CP\Column;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

/**
 * The AI agent's pickers in the Control Panel: collections and blueprints,
 * entry and asset mentions, the asset browser and previews.
 */
final class AgentPickerController
{
    public function __construct(
        private EntryGeneratorService $generator,
        private EntrySearch $search,
    ) {}

    /**
     * Return available collections and their blueprints.
     */
    public function collections(): JsonResponse
    {
        return response()->json(['collections' => $this->generator->getCollectionsCatalog()]);
    }

    /**
     * Typeahead for the chat composer's "@" entry mentions. Reuses the same
     * native Statamic entry query as the planner's find_entries tool so results
     * are consistent with what the agent can actually resolve.
     */
    public function entrySearch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:120',
            'collection' => 'nullable|string',
            'limit' => 'nullable|integer',
        ]);

        $limit = max(1, min(20, (int) ($data['limit'] ?? 8)));
        $query = trim((string) ($data['q'] ?? ''));
        $collection = ($data['collection'] ?? '') !== '' ? $data['collection'] : null;

        $rows = $this->search->findEntriesShortlist($collection, $query, $limit);

        $collectionTitles = Collection::all()->keyBy->handle();

        $results = array_map(function (array $row) use ($collectionTitles) {
            $handle = (string) ($row['collection'] ?? '');
            $collection = $collectionTitles->get($handle);

            return [
                'kind' => 'entry',
                'id' => (string) ($row['id'] ?? ''),
                'title' => ($row['title'] ?? '') !== '' ? (string) $row['title'] : (string) ($row['slug'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'collection' => $handle,
                'collection_title' => $collection ? (string) $collection->title() : $handle,
            ];
        }, $rows);

        // Assets + folders join the same picker (distinct kinds) so prompts can
        // reference imagery: "@asset:container::path" / "@folder:container::path".
        if ($query !== '') {
            $results = array_merge($results, $this->searchAssetMentions($query));
        }

        return response()->json(['results' => array_values($results)]);
    }

    /**
     * The entry currently open in the Control Panel, so the composer can
     * mention it without the editor searching for it.
     */
    public function openEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'required|string|max:80',
        ]);

        return response()->json([
            'entry' => $this->search->entryMention($data['id']),
        ]);
    }

    /**
     * Filename/folder-name matches across all asset containers, as mention rows.
     *
     * @return array<int, array<string, string>>
     */
    private function searchAssetMentions(string $query): array
    {
        $needle = mb_strtolower($query);
        $rows = [];

        foreach (AssetContainer::all() as $container) {
            $containerHandle = (string) $container->handle();

            foreach ($container->folders() as $folder) {
                if (count($rows) >= 8) {
                    return $rows;
                }
                $folder = (string) $folder;

                if (str_contains(mb_strtolower($folder), $needle)) {
                    $rows[] = [
                        'kind' => 'folder',
                        'title' => $folder,
                        'ref' => $containerHandle.'::'.$folder,
                        'collection_title' => $container->title().' · '.__('Folder'),
                    ];
                }
            }

            foreach ($container->files() as $path) {
                if (count($rows) >= 8) {
                    return $rows;
                }
                $path = (string) $path;

                if (str_contains(mb_strtolower(basename($path)), $needle)) {
                    $rows[] = [
                        'kind' => 'asset',
                        'title' => basename($path),
                        'ref' => $containerHandle.'::'.$path,
                        'collection_title' => $container->title().' · '.__('Asset'),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Resolve a single "@asset:" / "@folder:" reference to a compact preview
     * payload for the chat hover card: a CP thumbnail plus a little metadata.
     * The "small" preset (400px) keeps the download light, and the CP thumbnail
     * route already falls back to a placeholder for oversized images.
     */
    public function assetPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ref' => 'required|string',
            'kind' => 'nullable|in:asset,folder',
        ]);

        $kind = $data['kind'] ?? 'asset';
        [$containerHandle, $path] = array_pad(explode('::', $data['ref'], 2), 2, '');
        $container = AssetContainer::findByHandle(trim($containerHandle));

        if (! $container || trim($path) === '') {
            return response()->json(['ok' => false], 404);
        }

        if ($kind === 'folder') {
            $folder = trim($path, '/');
            $assets = $container->assets($folder !== '' ? $folder : '/', false);
            $images = $assets->filter(fn ($a) => $a->isImage())->values();

            return response()->json([
                'ok' => true,
                'kind' => 'folder',
                'name' => $folder === '' ? (string) $container->title() : basename($folder),
                'meta' => (string) $container->title(),
                'count' => $assets->count(),
                'thumbnails' => $images->take(9)
                    ->map(fn ($a) => $a->thumbnailUrl('small'))
                    ->filter()
                    ->values()
                    ->all(),
            ]);
        }

        $asset = $container->asset(trim($path, '/'));

        if (! $asset) {
            return response()->json(['ok' => false], 404);
        }

        $folder = trim((string) $asset->folder(), '/');

        return response()->json([
            'ok' => true,
            'kind' => 'asset',
            'name' => $asset->basename(),
            'meta' => (string) $container->title().($folder !== '' && $folder !== '.' ? ' · '.$folder : ''),
            // Preview capability drives the modal's presentation: images show a
            // thumbnail, everything else a document card ('file'). New media
            // kinds (video, …) slot in here without touching the dispatch.
            'preview' => $asset->isImage() ? 'image' : 'file',
            'size' => (int) $asset->size(),
            'is_image' => (bool) $asset->isImage(),
            'thumbnail' => $asset->isImage() ? $asset->thumbnailUrl('small') : null,
            'extension' => strtoupper((string) $asset->extension()),
            'width' => $asset->isImage() ? $asset->width() : null,
            'height' => $asset->isImage() ? $asset->height() : null,
            'alt' => (string) ($asset->get('alt') ?? ''),
        ]);
    }

    /**
     * Bootstrap payload for Statamic's native asset browser (the same shape the
     * assets fieldtype preloads), so the chat can open the CP asset selector to
     * browse folders and insert "@asset:"/"@folder:" references.
     */
    public function assetBrowser(Request $request): JsonResponse
    {
        $containers = AssetContainer::all()->sortBy->title()->values();

        if ($containers->isEmpty()) {
            return response()->json(['ok' => false, 'error' => __('No asset containers configured.')], 404);
        }

        $requested = trim((string) $request->input('container', ''));
        $container = $requested !== ''
            ? $containers->first(fn ($c) => (string) $c->handle() === $requested)
            : $containers->first();

        if (! $container) {
            return response()->json(['ok' => false, 'error' => __('Asset container not found.')], 404);
        }

        $user = User::current();

        $columns = $container->blueprint()->columns()->map(fn ($column) => clone $column);
        $columns->put('basename', Column::make('basename')->label(__('File'))->visible(true)->defaultVisibility(true)->sortable(true)->required(true));
        $columns->put('size', Column::make('size')->label(__('Size'))->value('size_formatted')->visible(true)->defaultVisibility(true)->sortable(true));
        $columns->put('last_modified', Column::make('last_modified')->label(__('Last Modified'))->value('last_modified_relative')->visible(true)->defaultVisibility(true)->sortable(true));
        $columns->setPreferred('assets.'.$container->handle().'.columns');

        return response()->json([
            'ok' => true,
            'containers' => $containers->map(fn ($c) => ['handle' => (string) $c->handle(), 'title' => (string) $c->title()])->all(),
            'container' => [
                'id' => $container->id(),
                'title' => $container->title(),
                'edit_url' => $container->editUrl(),
                'delete_url' => $container->deleteUrl(),
                'blueprint_url' => cp_route('blueprints.asset-containers.edit', $container->handle()),
                'can_view' => $user->can('view', $container),
                'can_upload' => $user->can('store', [Asset::class, $container]),
                'can_edit' => $user->can('edit', $container),
                'can_delete' => $user->can('delete', $container),
                'can_create_folders' => $user->can('create', [AssetFolder::class, $container]),
                'sort_field' => $container->sortField(),
                'sort_direction' => $container->sortDirection(),
            ],
            'columns' => $columns->rejectUnlisted()->values()->toArray(),
        ]);
    }

    /**
     * Return the field schema for a given collection + blueprint.
     */
    public function blueprintFields(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'required|string',
            'blueprint' => 'nullable|string',
        ]);

        $collection = Collection::findByHandle($data['collection']);

        if (! $collection) {
            return response()->json(['error' => __('Collection not found.')], 404);
        }

        $visible = $collection->entryBlueprints()->reject->hidden();

        $blueprint = $data['blueprint']
            ? $visible->keyBy->handle()->get($data['blueprint'])
            : $visible->first();

        if (! $blueprint) {
            return response()->json(['error' => __('Blueprint not found.')], 404);
        }

        $schema = $this->generator->getFieldSchemaForPreview($blueprint);

        return response()->json([
            'blueprint' => $blueprint->handle(),
            'fields' => $schema,
        ]);
    }
}
