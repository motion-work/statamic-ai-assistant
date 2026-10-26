<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use MotionWork\StatamicAiAssistant\Jobs\PlanEntriesJob;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

class EntryGeneratorController
{
    private EntryGeneratorService $generator;

    private AbstractAiService $aiService;

    private EntryGenerationBatchService $entryBatch;

    private \MotionWork\StatamicAiAssistant\Services\DocumentTextExtractor $documents;

    public function __construct(
        EntryGeneratorService $generator,
        AbstractAiService $aiService,
        EntryGenerationBatchService $entryBatch,
        \MotionWork\StatamicAiAssistant\Services\DocumentTextExtractor $documents,
    ) {
        $this->generator = $generator;
        $this->aiService = $aiService;
        $this->entryBatch = $entryBatch;
        $this->documents = $documents;
    }

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

        $rows = $this->generator->findEntriesShortlist($collection, $query, $limit);

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
            'entry' => $this->generator->entryMention($data['id']),
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

        foreach (\Statamic\Facades\AssetContainer::all() as $container) {
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
        $container = \Statamic\Facades\AssetContainer::findByHandle(trim($containerHandle));

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
        $containers = \Statamic\Facades\AssetContainer::all()->sortBy->title()->values();

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

        $user = \Statamic\Facades\User::current();

        $columns = $container->blueprint()->columns()->map(fn ($column) => clone $column);
        $columns->put('basename', \Statamic\CP\Column::make('basename')->label(__('File'))->visible(true)->defaultVisibility(true)->sortable(true)->required(true));
        $columns->put('size', \Statamic\CP\Column::make('size')->label(__('Size'))->value('size_formatted')->visible(true)->defaultVisibility(true)->sortable(true));
        $columns->put('last_modified', \Statamic\CP\Column::make('last_modified')->label(__('Last Modified'))->value('last_modified_relative')->visible(true)->defaultVisibility(true)->sortable(true));
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
                'can_upload' => $user->can('store', [\Statamic\Contracts\Assets\Asset::class, $container]),
                'can_edit' => $user->can('edit', $container),
                'can_delete' => $user->can('delete', $container),
                'can_create_folders' => $user->can('create', [\Statamic\Contracts\Assets\AssetFolder::class, $container]),
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

    /**
     * Generate content from a prompt (does NOT create the entry yet).
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'nullable|string',
            'blueprint' => 'nullable|string',
            'prompt' => 'required|string|min:3',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'file|mimes:pdf,txt|max:10240',
            'auto_resolve_target' => 'nullable|boolean',
        ]);

        $attachmentContent = $this->attachmentContentFromRequest($request);

        $autoResolve = $request->boolean('auto_resolve_target');

        if (! $autoResolve && ! $request->filled('collection')) {
            return response()->json(['error' => __('Please select a collection.')], 422);
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();

        try {
            if ($autoResolve) {
                $resolved = $this->generator->resolveTargetFromPrompt(
                    $data['prompt'],
                    $attachmentContent,
                );
                $collectionHandle = $resolved['collection'];
                $blueprintHandle = $resolved['blueprint'];
            } else {
                $collectionHandle = (string) $request->input('collection');
                $blueprintHandle = (string) ($request->input('blueprint') ?? '');
            }

            $result = $this->generator->generateContent(
                $collectionHandle,
                $blueprintHandle,
                $data['prompt'],
                $locale,
                $attachmentContent,
            );

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'displayData' => $result['displayData'],
                'warnings' => $result['warnings'],
                'resolved_collection' => $collectionHandle,
                'resolved_blueprint' => $blueprintHandle,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Entry generation failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return response()->json(['error' => __('Entry generation failed. Please try again.')], 500);
        }
    }

    /**
     * Streaming endpoint kicks off async planning + entry generation.
     *
     * The HTTP request itself does NO LLM work — it just creates the Redis-backed
     * batch session and dispatches PlanEntriesJob. The browser then polls
     * GET generate-progress/{sessionId} to surface incremental cards as the
     * agentic planner discovers articles and dispatches one GeneratePlannedEntryJob each.
     *
     * NDJSON event stream emitted by this endpoint:
     *   {type:"planning"}
     *   {type:"batch", session_id, async:true}    // start polling
     *   {type:"done", success:true}
     *   {type:"error", message}                    // fatal pre-dispatch error
     */
    public function generateStream(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'collection' => 'nullable|string',
            'blueprint' => 'nullable|string',
            'prompt' => 'required|string|min:3',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'file|mimes:pdf,txt|max:10240',
            'auto_resolve_target' => 'nullable|boolean',
        ]);

        $attachmentContent = $this->attachmentContentFromRequest($request);

        $autoResolve = $request->boolean('auto_resolve_target');

        if (! $autoResolve && ! $request->filled('collection')) {
            return response()->stream(function () {
                echo json_encode(['type' => 'error', 'message' => __('Please select a collection.')], JSON_UNESCAPED_UNICODE)."\n";
                @ob_flush();
                flush();
            }, 422, $this->ndjsonStreamHeaders());
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();
        $collectionHandle = $autoResolve ? null : (string) $request->input('collection');
        $blueprintHandle = $autoResolve ? null : (string) ($request->input('blueprint') ?? '');

        // Resolve the per-request entry cap and the advanced-tools state HERE,
        // while the CP user is known — the planner runs in a queued job with no
        // authenticated user, so it reads these off the session instead of
        // re-checking permissions. Advanced tools require the access grant AND
        // the user's explicit opt-in toggle.
        $maxPlanEntries = EntryCreationPolicy::maxPlanEntries();
        $advancedTools = AgentAccess::advancedToolsActive();

        return response()->stream(function () use ($data, $attachmentContent, $autoResolve, $locale, $collectionHandle, $blueprintHandle, $maxPlanEntries, $advancedTools) {
            $emit = static function (array $payload): void {
                echo json_encode($payload, JSON_UNESCAPED_UNICODE)."\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                if ($err = $this->entryBatchQueueSetupError()) {
                    $emit(['type' => 'error', 'message' => $err]);

                    return;
                }

                $emit(['type' => 'planning']);

                $sessionId = $this->entryBatch->initPlanningSession(
                    $locale,
                    $attachmentContent,
                    (string) $data['prompt'],
                    $autoResolve,
                    [
                        'appendix' => '',
                        'warnings' => [],
                        'preferred' => new PreferredAssetPaths,
                        'appended_to_prompts' => false,
                    ],
                    $collectionHandle,
                    $blueprintHandle,
                    $maxPlanEntries,
                    $advancedTools,
                );

                PlanEntriesJob::dispatch($sessionId);

                $emit([
                    'type' => 'batch',
                    'session_id' => $sessionId,
                    'async' => true,
                ]);

                $emit(['type' => 'done', 'success' => true]);
            } catch (\RuntimeException $e) {
                $emit(['type' => 'error', 'message' => $e->getMessage()]);
            } catch (\Throwable $e) {
                Log::error('Entry generation stream failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $emit(['type' => 'error', 'message' => __('Entry generation failed. Please try again.')]);
            }
        }, 200, $this->ndjsonStreamHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function ndjsonStreamHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * Persist generated data: creates a new entry, or — when `entry_id` is
     * supplied — patches that existing entry via the dedicated update path.
     */
    public function createEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'required_without:entry_id|string',
            'blueprint' => 'nullable|string',
            'data' => 'present|array',
            'entry_id' => 'nullable|string',
        ]);

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();
        $entryId = isset($data['entry_id']) && is_string($data['entry_id']) && $data['entry_id'] !== ''
            ? $data['entry_id']
            : null;

        if ($data['data'] === []) {
            return response()->json([
                'error' => $entryId !== null
                    ? __('The AI did not produce any field changes. Try a more specific prompt.')
                    : __('No content to save.'),
            ], 422);
        }

        try {
            if ($entryId !== null) {
                $entry = $this->generator->updateEntryFromData($entryId, $data['data']);
                $operation = 'updated';
            } else {
                $entry = $this->generator->saveEntry(
                    $data['collection'],
                    $data['blueprint'] ?? '',
                    $locale,
                    $data['data'],
                );
                $operation = 'created';
            }

            return response()->json([
                'success' => true,
                'operation' => $operation,
                'entry_id' => $entry->id(),
                'edit_url' => $entry->editUrl(),
                'title' => $entry->value('title') ?? '',
            ]);
        } catch (\Exception $e) {
            Log::error('Entry persist failed', ['error' => $e->getMessage(), 'entry_id' => $entryId]);

            return response()->json(['error' => __('Saving the entry failed. Please try again.')], 500);
        }
    }

    /**
     * Regenerate a single field value.
     */
    public function regenerateField(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field_type' => 'required|string',
            'current_value' => 'nullable|string',
            'prompt' => 'required|string',
        ]);

        try {
            if ($data['field_type'] === 'bard') {
                $content = $this->aiService->generateHtmlRefactorFromPrompt(
                    $data['current_value'] ?? '',
                    $data['prompt'],
                );
            } else {
                $content = $this->aiService->generateContentFromPrompt($data['prompt']);
            }

            return response()->json(['value' => $content]);
        } catch (\Exception $e) {
            Log::error('Field regeneration failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => __('Field regeneration failed. Please try again.')], 500);
        }
    }

    /**
     * Poll queued batch generation (same entry card shape as stream, plus stream_delta per tick).
     */
    public function generateBatchProgress(string $sessionId): JsonResponse
    {
        $snap = $this->entryBatch->snapshotForProgress($sessionId);
        if ($snap === null) {
            return response()->json(['error' => __('Session not found.')], 404);
        }

        return response()->json($snap);
    }

    /**
     * Continue an existing chat session with a follow-up message. Re-opens the
     * session, appends the user turn, and re-runs the planner with the full
     * transcript as context. Returns immediately; the browser keeps polling
     * generate-progress. Plain JSON (no NDJSON) — the job does the async work.
     */
    public function generateContinue(Request $request, string $sessionId): JsonResponse
    {
        $data = $request->validate([
            'prompt' => 'required|string|min:1',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'file|mimes:pdf,txt|max:10240',
        ]);

        $session = $this->entryBatch->getSession($sessionId);
        if (! is_array($session)) {
            return response()->json(['error' => __('This chat has expired. Start a new request.')], 404);
        }

        if (($session['planning_status'] ?? '') === 'planning') {
            return response()->json(['error' => __('The agent is still working on the previous message.')], 409);
        }

        if ($err = $this->entryBatchQueueSetupError()) {
            return response()->json(['error' => $err], 422);
        }

        // Re-resolve the per-request cap and advanced-tools state here, where
        // the CP user is known (the queued planner has no authenticated user).
        $maxPlanEntries = EntryCreationPolicy::maxPlanEntries();
        $advancedTools = AgentAccess::advancedToolsActive();

        $attachmentContent = $this->attachmentContentFromRequest($request);

        if (! $this->entryBatch->reopenForFollowUp($sessionId, (string) $data['prompt'], $maxPlanEntries, $advancedTools, $attachmentContent)) {
            return response()->json(['error' => __('This chat has expired. Start a new request.')], 404);
        }

        PlanEntriesJob::dispatch($sessionId);

        return response()->json(['ok' => true, 'session_id' => $sessionId]);
    }

    /**
     * Resume a chat whose server session expired. Chat history lives only in
     * the user's browser; this reseeds a fresh transient session from the
     * client-provided transcript, appends the new user turn, and re-runs the
     * planner — the browser then polls generate-progress as usual.
     */
    public function generateResume(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => 'required|string|min:1',
            'transcript' => 'required|array|min:1|max:80',
            'transcript.*.role' => 'required|string|in:user,assistant',
            'transcript.*.text' => 'required|string|max:20000',
            'transcript.*.kind' => 'nullable|string|max:32',
            'attachment' => 'nullable|file|mimes:pdf,txt|max:10240',
            'attachments' => 'nullable|array|max:6',
            'attachments.*' => 'file|mimes:pdf,txt|max:10240',
        ]);

        if ($err = $this->entryBatchQueueSetupError()) {
            return response()->json(['error' => $err], 422);
        }

        $locale = optional(Site::selected())->handle() ?: Site::default()->handle();
        $maxPlanEntries = EntryCreationPolicy::maxPlanEntries();
        $advancedTools = AgentAccess::advancedToolsActive();

        $attachmentContent = $this->attachmentContentFromRequest($request);

        $sessionId = $this->entryBatch->restoreSessionFromTranscript($locale, $data['transcript'], $advancedTools);
        $this->entryBatch->reopenForFollowUp($sessionId, (string) $data['prompt'], $maxPlanEntries, $advancedTools, $attachmentContent);

        PlanEntriesJob::dispatch($sessionId);

        return response()->json(['ok' => true, 'session_id' => $sessionId]);
    }

    /**
     * Current advanced-tools state for the agent UI toggle: whether the user
     * holds the access grant at all (toggle hidden otherwise) and whether they
     * have opted in.
     */
    public function advancedToolsPreference(): JsonResponse
    {
        return response()->json([
            'granted' => AgentAccess::allows('advanced_tools'),
            'enabled' => AgentAccess::advancedToolsActive(),
        ]);
    }

    /**
     * Persist the per-user advanced-tools opt-in (survives sessions — it is a
     * Statamic user preference). The grant itself is still required; the
     * preference alone activates nothing.
     */
    public function saveAdvancedToolsPreference(\Illuminate\Http\Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);

        $user = \Statamic\Facades\User::current();
        if (! $user || ! AgentAccess::allows('advanced_tools')) {
            return response()->json(['error' => __('Not allowed.')], 403);
        }

        $user->setPreference(AgentAccess::ADVANCED_TOOLS_PREFERENCE, (bool) $data['enabled'])->save();

        return response()->json([
            'granted' => true,
            'enabled' => AgentAccess::advancedToolsActive($user),
        ]);
    }

    /**
     * Cancel a running batch (jobs still in chain will mark entries cancelled when they start).
     */
    public function generateBatchCancel(string $sessionId): JsonResponse
    {
        $this->entryBatch->cancelSession($sessionId);

        return response()->json(['ok' => true]);
    }

    private function entryBatchQueueSetupError(): ?string
    {
        if (config('queue.default') === 'sync') {
            return (string) __(
                'AI agent batch generation requires an asynchronous queue. Set QUEUE_CONNECTION=redis in your .env, then start php artisan horizon or php artisan queue:work. The sync driver cannot be used.',
            );
        }

        if ($missing = $this->missingQueueDatabaseTables()) {
            return (string) __(
                'The addon queue relies on database tables that are not set up yet (:missing). Run `php artisan migrate --force` to create them, then restart php artisan horizon or php artisan queue:work.',
                ['missing' => implode(', ', $missing)],
            );
        }

        return null;
    }

    /**
     * Queue-related database tables that are missing or unreachable — typically
     * because `php artisan migrate --force` has not been run locally. Empty when
     * the database is ready. Mirrors the Horizon/sync setup guard above.
     */
    private function missingQueueDatabaseTables(): array
    {
        $checks = [];

        if (config('queue.failed.driver') === 'database-uuids') {
            $checks[] = [
                config('queue.failed.database', config('database.default')),
                config('queue.failed.table', 'failed_jobs'),
            ];
        }

        // Laravel Bus batching backs the translation/navigation batch runs.
        $checks[] = [
            config('queue.batching.database', config('database.default')),
            config('queue.batching.table', 'job_batches'),
        ];

        $missing = [];

        foreach ($checks as [$connection, $table]) {
            if (! $this->queueTableExists($connection, $table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    private function queueTableExists(string $connection, string $table): bool
    {
        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Extract text content from an optional attachment uploaded with a chat
     * turn (initial or follow-up). Centralized so continue/resume mirror the
     * streaming start endpoint's handling.
     */
    private function attachmentContentFromRequest(Request $request): ?string
    {
        $files = [];
        if ($single = $request->file('attachment')) {
            $files[] = $single;
        }
        $many = $request->file('attachments');
        foreach (is_array($many) ? $many : [] as $file) {
            if ($file) {
                $files[] = $file;
            }
        }

        // Each document is labeled with its client filename — that name equals
        // the "@file:<token>" the user references in the prompt, so the planner
        // can match mentions to contents. Unreadable files (e.g. scanned PDFs
        // with no embedded text) are still listed with the reason, never
        // dropped silently — otherwise the model invents content from the name.
        $parts = [];
        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            $result = $this->documents->extractUploadedFile($file);

            $parts[] = $result['content'] !== null
                ? "── Attachment: {$name} ──\n".trim($result['content'])
                : "── Attachment: {$name} (UNREADABLE — {$result['reason']}) ──\n"
                    ."This file was attached but its text could not be extracted. Tell the user this and do not guess its contents.";
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

}
