<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MotionWork\StatamicAiAssistant\Services\CpTranslationBatchRunner;
use MotionWork\StatamicAiAssistant\Services\NavigationTreeSyncService;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * The super-admin bulk translations page: picking entries and terms,
 * translating them in batches, and syncing navigation trees across sites.
 */
final class BulkTranslationController
{
    public function __construct(
        private TranslationService $translationService,
        private CpTranslationBatchRunner $batchRunner,
        private NavigationTreeSyncService $navigationTreeSyncService,
    ) {}

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
            // Same queue + progress-cache machinery as entry batches (falls back
            // to the inline sync when no real queue is available).
            $payload = $this->batchRunner->runNavigationSync(
                $this->navigationTreeSyncService,
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
            'sites' => Site::all()->filter(function ($site) use ($sites) {
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

    /**
     * Terms of a taxonomy with per-locale translation status — the taxonomy
     * counterpart of collectionEntries, in the SAME response shape so the CP
     * table, selection and progress components work unchanged. A term counts
     * as translated in a site when it has localized data there.
     */
    public function taxonomyTerms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'taxonomy' => 'required|string',
        ]);

        $taxonomy = Taxonomy::findByHandle($data['taxonomy']);
        if (! $taxonomy) {
            return response()->json(['error' => __('Taxonomy not found.')], 404);
        }

        $sites = $taxonomy->sites();
        if (! $sites || $sites->count() <= 1) {
            return response()->json(['error' => __('This taxonomy does not support multiple languages.')], 422);
        }

        $defaultSiteHandle = Site::default()->handle();
        $originSite = $sites->contains($defaultSiteHandle)
            ? $defaultSiteHandle
            : $sites->first();

        $terms = Term::query()
            ->where('taxonomy', $data['taxonomy'])
            ->where('site', $originSite)
            ->get();

        $result = $terms->map(function ($term) use ($sites, $originSite) {
            $base = method_exists($term, 'term') ? $term->term() : $term;

            $locales = [];
            foreach ($sites as $siteHandle) {
                $locales[$siteHandle] = $siteHandle === $originSite || collect($base->dataForLocale($siteHandle))->isNotEmpty()
                    ? 'translated'
                    : 'missing';
            }

            return [
                'id' => (string) $base->id(),
                'title' => (string) ($term->title() ?? $base->slug()),
                'edit_url' => $term->editUrl(),
                'locales' => $locales,
            ];
        });

        $defaultSite = Site::default();

        return response()->json([
            'entries' => $result->values(),
            'sites' => Site::all()->filter(function ($site) use ($sites) {
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

    /**
     * Translate a single taxonomy term to one destination locale (the term
     * counterpart of translateEntry; the CP loops term × locale pairs).
     */
    public function translateTerm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'term_id' => 'required|string',
            'destination_locale' => 'required|string',
            'overwrite' => 'sometimes|boolean',
        ]);

        $result = $this->translationService->translateTerm(
            $data['term_id'],
            Site::default()->locale(),
            $data['destination_locale'],
            (bool) ($data['overwrite'] ?? false),
        );

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }
}
