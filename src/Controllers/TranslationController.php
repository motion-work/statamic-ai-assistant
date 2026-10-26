<?php

namespace BoldWeb\StatamicAiAssistant\Controllers;

use BoldWeb\StatamicAiAssistant\Services\CpTranslationBatchRunner;
use BoldWeb\StatamicAiAssistant\Services\DeeplService;
use BoldWeb\StatamicAiAssistant\Services\NavigationTreeSyncService;
use BoldWeb\StatamicAiAssistant\Services\TranslationService;
use BoldWeb\StatamicAiAssistant\Support\EntryLabel;
use BoldWeb\StatamicAiAssistant\Support\TrimAiOutput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class TranslationController
{
    protected TranslationService $translationService;

    protected CpTranslationBatchRunner $batchRunner;

    protected NavigationTreeSyncService $navigationTreeSyncService;

    public function __construct(
        TranslationService $translationService,
        CpTranslationBatchRunner $batchRunner,
        NavigationTreeSyncService $navigationTreeSyncService
    ) {
        $this->translationService = $translationService;
        $this->batchRunner = $batchRunner;
        $this->navigationTreeSyncService = $navigationTreeSyncService;
    }

    /**
     * Translate a single entry synchronously.
     */
    public function translateEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_id' => 'required|string',
            'destination_locale' => 'required|string',
            'overwrite' => 'boolean',
        ]);

        $entry = Entry::find($data['entry_id']);
        if (! $entry) {
            return response()->json(['error' => __('Entry not found.')], 404);
        }

        $sourceLocale = Site::default()->locale();

        $maxDepth = (int) config('statamic-ai-assistant.linked_entries_max_depth', 1);

        $result = $this->translationService->translateEntry(
            $entry,
            $sourceLocale,
            $data['destination_locale'],
            $data['overwrite'] ?? true,
            $maxDepth,
        );

        return response()->json($result);
    }

    /**
     * Translate multiple entries to one or more locales. Uses sync for small batches, async for larger ones.
     */
    public function translateBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_ids' => 'required|array|min:1',
            'entry_ids.*' => 'string',
            'destination_locales' => 'sometimes|array|min:1',
            'destination_locales.*' => 'string',
            'destination_locale' => 'sometimes|string',
            'overwrite' => 'boolean',
        ]);

        $sourceLocale = Site::default()->locale();

        $destinationLocales = $data['destination_locales'] ?? [];
        if (empty($destinationLocales) && isset($data['destination_locale'])) {
            $destinationLocales = [$data['destination_locale']];
        }
        $destinationLocales = array_values(array_unique(array_filter($destinationLocales)));

        if (empty($destinationLocales)) {
            return response()->json(['error' => __('Select at least one destination language.')], 422);
        }

        foreach ($destinationLocales as $dl) {
            if ($dl === $sourceLocale) {
                return response()->json([
                    'error' => __('Source and destination languages cannot be the same.'),
                ], 422);
            }
        }

        $entries = collect($data['entry_ids'])
            ->map(fn ($id) => Entry::find($id))
            ->filter();

        if ($entries->isEmpty()) {
            return response()->json(['error' => __('No valid entries found.')], 404);
        }

        $overwrite = $data['overwrite'] ?? true;
        $maxDepth = (int) config('statamic-ai-assistant.linked_entries_max_depth', 1);

        try {
            $result = $this->batchRunner->run(
                $entries,
                $sourceLocale,
                $destinationLocales,
                $overwrite,
                $maxDepth,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'details' => $e->getPrevious()?->getMessage(),
            ], 500);
        }

        return response()->json($result);
    }

    /**
     * Preflight check for the Translate (DeepL) entry action — same rule as Bulk translations (conflict without overwrite).
     */
    public function conflictCheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entry_ids' => 'required|array|min:1',
            'entry_ids.*' => 'string',
            'destination_locales' => 'required|array|min:1',
            'destination_locales.*' => 'string',
        ]);

        $entries = collect($data['entry_ids'])
            ->map(fn ($id) => Entry::find($id))
            ->filter();

        if ($entries->isEmpty()) {
            return response()->json([
                'has_conflict' => false,
                'conflicting_locales' => [],
                'conflicting_locale_labels' => [],
                'conflicts_by_locale' => [],
            ]);
        }

        foreach ($entries as $entry) {
            abort_unless(User::current()->can('edit', $entry), 403);
        }

        $destinationLocales = array_values(array_unique(array_filter($data['destination_locales'])));
        $sourceLocale = Site::default()->locale();

        $conflictsByLocale = $this->translationService->conflictDetailsWithoutOverwrite(
            $entries->all(),
            $sourceLocale,
            $destinationLocales,
        );

        $conflictingLocales = array_column($conflictsByLocale, 'locale');
        $conflictingLocaleLabels = array_column($conflictsByLocale, 'locale_label');

        return response()->json([
            'has_conflict' => count($conflictsByLocale) > 0,
            'conflicting_locales' => $conflictingLocales,
            'conflicting_locale_labels' => $conflictingLocaleLabels,
            'conflicts_by_locale' => $conflictsByLocale,
        ]);
    }

    /**
     * Translate a single field value via DeepL (for per-field translate buttons).
     */
    public function translateField(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => 'required|string',
            'source_locale' => 'nullable|string',
            'target_locale' => 'required|string',
            /** When true, text is HTML (e.g. Bard) and DeepL preserves structure via tag_handling=html. Plain text must omit this or send false — otherwise apostrophes become entities (e.g. &#x27;). */
            'html' => 'sometimes|boolean',
        ]);

        $sourceLocale = isset($data['source_locale']) && trim($data['source_locale']) !== ''
            ? $data['source_locale']
            : null;

        $deeplOptions = ($data['html'] ?? false) ? ['tag_handling' => 'html'] : [];

        try {
            /** @var DeeplService $deepl */
            $deepl = app(DeeplService::class);
            $translated = $deepl->translateText(
                $data['text'],
                $sourceLocale,
                $data['target_locale'],
                $deeplOptions,
            );

            return response()->json(['translated' => TrimAiOutput::normalize($translated)]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => __('Translation failed: :error', ['error' => $e->getMessage()]),
            ], 500);
        }
    }

    /**
     * DeepL API usage for the current billing period (characters / documents).
     */
    public function deeplUsage(): JsonResponse
    {
        if (! config('statamic-ai-assistant.translations', true)) {
            return response()->json(['enabled' => false]);
        }

        try {
            /** @var DeeplService $deepl */
            $deepl = app(DeeplService::class);

            return response()->json(array_merge(
                ['enabled' => true],
                $deepl->getUsageForApi()
            ));
        } catch (\Throwable $e) {
            return response()->json([
                'enabled' => true,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get progress for an async batch.
     */
    public function progress(string $batchId): JsonResponse
    {
        $progress = $this->translationService->getBatchProgress($batchId);

        if (! $progress) {
            return response()->json(['error' => __('Batch not found.')], 404);
        }

        return response()->json($progress);
    }

    /**
     * Return translation coverage statistics across all multisite collections.
     */
    public function status(): JsonResponse
    {
        $status = $this->translationService->getTranslationStatus();
        $navigations = $this->translationService->getNavigationsList();

        return response()->json([
            'collections' => $status,
            'navigations' => $navigations,
        ]);
    }

    /**
     * Preview syncing the default site's navigation tree to other sites (structure copy + missing pages per locale).
     */
    public function navigationEntries(Request $request): JsonResponse
    {
        $data = $request->validate([
            'navigation' => 'required|string',
        ]);

        try {
            $payload = $this->navigationTreeSyncService->preview($data['navigation']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    /**
     * Copy navigation structure from the default site to selected locales, translating missing pages then saving each site's tree.
     */
    public function navigationSync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'navigation' => 'required|string',
            'destination_locales' => 'sometimes|array|min:1',
            'destination_locales.*' => 'string',
            'destination_locale' => 'sometimes|string',
        ]);

        $destinationLocales = $data['destination_locales'] ?? [];
        if (empty($destinationLocales) && isset($data['destination_locale'])) {
            $destinationLocales = [$data['destination_locale']];
        }
        $destinationLocales = array_values(array_unique(array_filter($destinationLocales)));

        $overwrite = false;
        $maxDepth = (int) config('statamic-ai-assistant.linked_entries_max_depth', 1);

        try {
            $payload = $this->navigationTreeSyncService->sync(
                $data['navigation'],
                $destinationLocales,
                $overwrite,
                $maxDepth,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json($payload);
    }

    /**
     * Return entries for a given collection, with translation status per locale.
     */
    public function collectionEntries(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collection' => 'required|string',
        ]);

        $collection = Collection::findByHandle($data['collection']);
        if (! $collection) {
            return response()->json(['error' => __('Collection not found.')], 404);
        }

        $sites = $collection->sites();
        if (! $sites || $sites->count() <= 1) {
            return response()->json(['error' => __('This collection does not support multiple languages.')], 422);
        }

        $defaultSiteHandle = Site::default()->handle();
        $originSite = $sites->contains($defaultSiteHandle)
            ? $defaultSiteHandle
            : $sites->first();

        $entries = Entry::query()
            ->where('collection', $data['collection'])
            ->where('site', $originSite)
            ->get();

        $result = $entries->map(function ($entry) use ($sites) {
            $locales = [];
            foreach ($sites as $siteHandle) {
                $locales[$siteHandle] = $entry->existsIn($siteHandle) ? 'translated' : 'missing';
            }

            return [
                'id' => $entry->id(),
                'title' => EntryLabel::for($entry),
                'edit_url' => $entry->editUrl(),
                'locales' => $locales,
            ];
        });

        $defaultSite = Site::default();

        return response()->json([
            'entries' => $result->values(),
            'sites' => \Statamic\Facades\Site::all()->filter(function ($site) use ($sites) {
                return $sites->contains($site->handle());
            })->map(function ($site) {
                return [
                    'handle' => $site->handle(),
                    'name' => $site->name(),
                    'locale' => $site->locale(),
                ];
            })->values(),
            'origin_site_handle' => $originSite,
            'default_source_locale' => $defaultSite->locale(),
            'default_source_site_name' => $defaultSite->name(),
        ]);
    }
}
