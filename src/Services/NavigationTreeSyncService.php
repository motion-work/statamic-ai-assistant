<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Facades\Statamic\Structures\BranchIds;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use MotionWork\StatamicAiAssistant\Support\NavigationTree;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

class NavigationTreeSyncService
{
    protected NavigationBranchMapper $branches;

    public function __construct(
        protected TranslationService $translationService,
        DeeplService $deepl,
    ) {
        $this->branches = new NavigationBranchMapper($deepl);
    }

    /**
     * Preview copying the default site's navigation tree to other sites: missing localizations per destination.
     *
     * @return array{
     *   navigation: array{handle: string, title: string},
     *   source_site: array{handle: string, name: string, locale: string},
     *   sites: array<int, array{handle: string, name: string, locale: string}>,
     *   origin_site_handle: string,
     *   default_source_locale: string,
     *   default_source_site_name: string,
     *   tree_branch_count: int,
     *   url_only_navigation: bool,
     *   url_only_warning?: string|null,
     *   per_destination: array<int, array{
     *     site_handle: string,
     *     site_name: string,
     *     locale: string,
     *     missing_entries: array<int, array{source_entry_id: string, title: string}>,
     *   }>,
     *   error?: string,
     * }
     */
    public function preview(string $navHandle): array
    {
        $nav = Nav::findByHandle($navHandle);
        if (! $nav) {
            throw new \InvalidArgumentException(__('Navigation not found.'));
        }

        if (! Site::hasMultiple()) {
            throw new \InvalidArgumentException(__('This action requires at least two Statamic sites. Add another site in Sites configuration.'));
        }

        $resolved = NavigationTree::sourceTree($nav);
        $sourceSite = $resolved['site'];
        $tree = $resolved['tree'];
        $sourceSiteHandle = $sourceSite->handle();
        $urlOnlyNavigation = $resolved['url_only'] ?? false;

        $branchCount = NavigationTree::countBranches($tree);
        $entryIds = NavigationTree::entryIds($tree);

        $allSites = Site::all()->values();

        $perDestination = [];
        foreach ($allSites as $site) {
            if ($site->handle() === $sourceSiteHandle) {
                continue;
            }
            $perDestination[] = [
                'site_handle' => $site->handle(),
                'site_name' => $site->name(),
                'locale' => $site->locale(),
                'missing_entries' => NavigationTree::missingPages($entryIds, $site->handle(), $sourceSiteHandle),
            ];
        }

        return [
            'navigation' => [
                'handle' => $nav->handle(),
                'title' => $nav->title(),
            ],
            'source_site' => [
                'handle' => $sourceSiteHandle,
                'name' => $sourceSite->name(),
                'locale' => $sourceSite->locale(),
            ],
            'sites' => $allSites->map(fn ($s) => [
                'handle' => $s->handle(),
                'name' => $s->name(),
                'locale' => $s->locale(),
            ])->values()->all(),
            'origin_site_handle' => $sourceSiteHandle,
            'default_source_locale' => $sourceSite->locale(),
            'default_source_site_name' => $sourceSite->name(),
            'tree_branch_count' => $branchCount,
            'url_only_navigation' => $urlOnlyNavigation,
            'url_only_warning' => $urlOnlyNavigation ? __('Navigation URL-only warning') : null,
            'per_destination' => $perDestination,
        ];
    }

    /**
     * Copy navigation structure from the default site to each destination (by locale), translating missing pages first.
     *
     * @param  array<int, string>  $destinationLocales  Site locale strings (same as bulk translation)
     * @return array{results: array<int, array<string, mixed>>}
     */
    public function sync(
        string $navHandle,
        array $destinationLocales,
        bool $overwrite,
        int $maxDepth,
    ): array {
        $nav = Nav::findByHandle($navHandle);
        if (! $nav) {
            throw new \InvalidArgumentException(__('Navigation not found.'));
        }

        if (! Site::hasMultiple()) {
            throw new \InvalidArgumentException(__('This action requires at least two Statamic sites. Add another site in Sites configuration.'));
        }

        $resolved = NavigationTree::sourceTree($nav);
        $sourceSite = $resolved['site'];
        $tree = $resolved['tree'];
        $sourceSiteHandle = $sourceSite->handle();
        $sourceLocale = $sourceSite->locale();
        $urlOnlyNavigation = $resolved['url_only'] ?? false;

        $destinationLocales = array_values(array_unique(array_filter($destinationLocales)));
        if ($destinationLocales === []) {
            throw new \InvalidArgumentException(__('Select at least one destination language.'));
        }

        $results = [];

        foreach ($destinationLocales as $destLocale) {
            if ($destLocale === $sourceLocale) {
                throw new \InvalidArgumentException(__('Source and destination languages cannot be the same.'));
            }

            $results[] = $this->syncLocale($navHandle, $destLocale, $overwrite, $maxDepth);
        }

        return ['results' => $results];
    }

    /**
     * Sync ONE destination locale. This is the unit queued nav-sync jobs run;
     * $onPage streams per-page progress ('processing' before translating a
     * missing page, 'completed' with the full page payload after) so the CP
     * can render live rows exactly like entry translations.
     *
     * @param  (callable(string $phase, array<string, mixed> $page): void)|null  $onPage
     * @return array<string, mixed> Per-locale result row (same shape as sync()'s result items)
     */
    public function syncLocale(string $navHandle, string $destLocale, bool $overwrite, int $maxDepth, ?callable $onPage = null): array
    {
        $nav = Nav::findByHandle($navHandle);
        if (! $nav) {
            return ['locale' => $destLocale, 'success' => false, 'error' => __('Navigation not found.')];
        }

        $resolved = NavigationTree::sourceTree($nav);
        $sourceSite = $resolved['site'];
        $tree = $resolved['tree'];
        $sourceSiteHandle = $sourceSite->handle();
        $sourceLocale = $sourceSite->locale();
        $urlOnlyNavigation = $resolved['url_only'] ?? false;

        if ($destLocale === $sourceLocale) {
            return ['locale' => $destLocale, 'success' => false, 'error' => __('Source and destination languages cannot be the same.')];
        }

        $destSite = Site::all()->firstWhere('locale', $destLocale);
        if (! $destSite) {
            return [
                'locale' => $destLocale,
                'success' => false,
                'error' => __('Destination site not found for locale: :locale', ['locale' => $destLocale]),
            ];
        }

        $destSiteHandle = $destSite->handle();

        $translated = [];
        $pages = [];

        try {
            foreach (NavigationTree::entryIds($tree) as $entryId) {
                $done = $this->translateMissingPage($entryId, $sourceSite, $destSite, $overwrite, $maxDepth, $onPage);
                if ($done !== null) {
                    [$pages[], $translated[]] = $done;
                }
            }

            $mapped = $this->branches->mapTreeForSite($tree, $destSiteHandle, $sourceLocale, $destLocale);
            $mapped = BranchIds::ensure($mapped);

            $navTree = $nav->in($destSiteHandle) ?? $nav->makeTree($destSiteHandle);
            $navTree->tree($mapped);
            $navTree->save();

            $warnings = [];
            if ($urlOnlyNavigation) {
                $warnings[] = __('Navigation URL-only sync notice');
            }

            return [
                'locale' => $destLocale,
                'site_handle' => $destSiteHandle,
                'success' => true,
                'warnings' => $warnings,
                'warnings_translated_titles' => $translated,
                'pages' => $pages,
                'message' => __('Navigation tree saved for :site.', ['site' => $destSite->name()]),
            ];
        } catch (\Throwable $e) {
            return [
                'locale' => $destLocale,
                'site_handle' => $destSite->handle(),
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Translates one page of the navigation that has no localization on the
     * destination site yet: [page row, source title], or null when it has one
     * (or no source entry).
     *
     * @param  (callable(string $phase, array<string, mixed> $page): void)|null  $onPage
     * @return array{0: array<string, mixed>, 1: string}|null
     */
    private function translateMissingPage(string $entryId, $sourceSite, $destSite, bool $overwrite, int $maxDepth, ?callable $onPage): ?array
    {
        $entry = EntryFacade::find($entryId);
        if (! $entry) {
            return null;
        }
        $root = $entry->root();
        $sourceRoot = NavigationTree::localization($root, $sourceSite->handle());
        if (! $sourceRoot) {
            return null;
        }

        if (NavigationTree::localization($root, $destSite->handle()) !== null) {
            return null;
        }

        if ($onPage) {
            $onPage('processing', [
                'source_entry_id' => (string) $sourceRoot->id(),
                'origin_title' => EntryLabel::for($sourceRoot),
            ]);
        }

        $res = $this->translationService->translateEntry(
            $sourceRoot,
            $sourceSite->locale(),
            $destSite->locale(),
            $overwrite,
            $maxDepth,
        );

        if (! ($res['success'] ?? false)) {
            throw new \RuntimeException($res['error'] ?? __('Translation failed.'));
        }

        // Keep the full per-page outcome (incl. linked entries the
        // translator auto-created) so the CP can render one progress
        // row per translated page, exactly like entry translations.
        $page = [
            'source_entry_id' => (string) $sourceRoot->id(),
            'entry_id' => $res['entry_id'] ?? null,
            'origin_title' => $res['origin_title'] ?? EntryLabel::for($sourceRoot),
            'target_title' => $res['target_title'] ?? null,
            'edit_url' => $res['edit_url'] ?? null,
            'linked_entries' => $res['linked_entries'] ?? [],
        ];

        if ($onPage) {
            $onPage('completed', $page);
        }

        return [$page, EntryLabel::for($sourceRoot)];
    }
}
