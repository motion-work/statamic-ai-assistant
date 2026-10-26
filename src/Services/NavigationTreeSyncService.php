<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Support\EntryLabel;
use Facades\Statamic\Structures\BranchIds;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

class NavigationTreeSyncService
{
    public function __construct(
        protected TranslationService $translationService,
        protected DeeplService $deepl,
    ) {}

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

        $resolved = $this->resolveSourceNavigationTree($nav);
        $sourceSite = $resolved['site'];
        $tree = $resolved['tree'];
        $sourceSiteHandle = $sourceSite->handle();
        $urlOnlyNavigation = $resolved['url_only'] ?? false;

        $branchCount = $this->countBranches($tree);
        $entryIds = $this->collectEntryIdsFromBranches($tree);

        $allSites = Site::all()->values();

        $perDestination = [];
        foreach ($allSites as $site) {
            if ($site->handle() === $sourceSiteHandle) {
                continue;
            }
            $missing = [];
            foreach ($entryIds as $entryId) {
                $entry = EntryFacade::find($entryId);
                if (! $entry) {
                    continue;
                }
                $root = $entry->root();
                if ($this->localizationForSite($root, $site->handle()) !== null) {
                    continue;
                }
                $sourceRoot = $this->resolveRootInSite($root, $sourceSiteHandle);
                if (! $sourceRoot) {
                    continue;
                }
                $missing[] = [
                    'source_entry_id' => $sourceRoot->id(),
                    'title' => EntryLabel::for($sourceRoot),
                ];
            }
            $unique = collect($missing)->unique('source_entry_id')->values()->all();
            $perDestination[] = [
                'site_handle' => $site->handle(),
                'site_name' => $site->name(),
                'locale' => $site->locale(),
                'missing_entries' => $unique,
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

        $resolved = $this->resolveSourceNavigationTree($nav);
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

            $destSite = Site::all()->firstWhere('locale', $destLocale);
            if (! $destSite) {
                $results[] = [
                    'locale' => $destLocale,
                    'success' => false,
                    'error' => __('Destination site not found for locale: :locale', ['locale' => $destLocale]),
                ];
                continue;
            }

            $destSiteHandle = $destSite->handle();

            $translated = [];

            try {
                $entryIds = $this->collectEntryIdsFromBranches($tree);
                foreach ($entryIds as $entryId) {
                    $entry = EntryFacade::find($entryId);
                    if (! $entry) {
                        continue;
                    }
                    $root = $entry->root();
                    $sourceRoot = $this->resolveRootInSite($root, $sourceSiteHandle);
                    if (! $sourceRoot) {
                        continue;
                    }

                    if ($this->localizationForSite($root, $destSiteHandle) !== null) {
                        continue;
                    }

                    $res = $this->translationService->translateEntry(
                        $sourceRoot,
                        $sourceLocale,
                        $destLocale,
                        $overwrite,
                        $maxDepth,
                    );

                    if (! ($res['success'] ?? false)) {
                        throw new \RuntimeException($res['error'] ?? __('Translation failed.'));
                    }
                    $translated[] = EntryLabel::for($sourceRoot);
                }

                $mapped = $this->mapTreeForSite($tree, $destSiteHandle, $sourceLocale, $destLocale);
                $mapped = BranchIds::ensure($mapped);

                $navTree = $nav->in($destSiteHandle) ?? $nav->makeTree($destSiteHandle);
                $navTree->tree($mapped);
                $navTree->save();

                $warnings = [];
                if ($urlOnlyNavigation) {
                    $warnings[] = __('Navigation URL-only sync notice');
                }

                $results[] = [
                    'locale' => $destLocale,
                    'site_handle' => $destSiteHandle,
                    'success' => true,
                    'warnings' => $warnings,
                    'warnings_translated_titles' => $translated,
                    'message' => __('Navigation tree saved for :site.', ['site' => $destSite->name()]),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'locale' => $destLocale,
                    'site_handle' => $destSite->handle(),
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['results' => $results];
    }

    /**
     * Prefer the default site's tree; if it is empty or has no page entries, use the first other site that does.
     * This matches CP reality where e.g. English is default but only German has the nav built yet.
     *
     * @param  \Statamic\Contracts\Structures\Nav  $nav
     * @return array{site: \Statamic\Sites\Site, tree: array<int, array<string, mixed>>, url_only: bool}
     */
    protected function resolveSourceNavigationTree($nav): array
    {
        $candidateUrlOnly = null;

        foreach ($this->siteHandlesOrderedDefaultFirst() as $handle) {
            $navTree = $nav->in($handle);
            if (! $navTree) {
                continue;
            }

            $tree = $navTree->tree();
            if ($tree === [] || $tree === null) {
                continue;
            }

            $ids = $this->collectEntryIdsFromBranches($tree);
            if ($ids !== []) {
                $site = Site::get($handle);
                if ($site) {
                    return ['site' => $site, 'tree' => $tree, 'url_only' => false];
                }
            }
            if ($this->countBranches($tree) > 0 && $candidateUrlOnly === null) {
                $site = Site::get($handle);
                if ($site) {
                    $candidateUrlOnly = ['site' => $site, 'tree' => $tree, 'url_only' => true];
                }
            }
        }

        if ($candidateUrlOnly !== null) {
            return $candidateUrlOnly;
        }

        throw new \InvalidArgumentException(__('The navigation has no linked pages in any site. Add entries in the control panel.'));
    }

    /**
     * @return array<int, string>
     */
    protected function siteHandlesOrderedDefaultFirst(): array
    {
        $defaultHandle = Site::default()->handle();
        $handles = [$defaultHandle];

        foreach (Site::all() as $site) {
            if ($site->handle() !== $defaultHandle) {
                $handles[] = $site->handle();
            }
        }

        return $handles;
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<int, array<string, mixed>>
     */
    protected function mapTreeForSite(
        array $branches,
        string $targetSiteHandle,
        string $sourceLocale,
        string $destLocale,
    ): array {
        $out = [];
        foreach ($branches as $branch) {
            $mapped = $this->mapBranchForSite($branch, $targetSiteHandle, $sourceLocale, $destLocale);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $branch
     * @return array<string, mixed>|null
     */
    protected function mapBranchForSite(
        array $branch,
        string $targetSiteHandle,
        string $sourceLocale,
        string $destLocale,
    ): ?array {
        $copy = $branch;

        unset($copy['id']);

        if (! empty($branch['entry'])) {
            $entry = EntryFacade::find($branch['entry']);
            if (! $entry) {
                throw new \RuntimeException(__('Navigation references a missing entry (:id).', ['id' => $branch['entry']]));
            }
            $root = $entry->root();
            $localized = $this->localizationForSite($root, $targetSiteHandle);
            if ($localized === null) {
                throw new \RuntimeException(
                    __('No localized page for ":title" in the target site. Run translation for that page first.', [
                        'title' => EntryLabel::for($root),
                    ])
                );
            }
            $copy['entry'] = $localized->id();
            unset($copy['title']);
            $localizedTitle = $localized->value('title');
            if (is_string($localizedTitle) && $localizedTitle !== '') {
                $copy['title'] = $localizedTitle;
            }
        } elseif (! empty($branch['url'])) {
            // Custom / external URL: keep URL; translate label for the target locale.
            if (isset($copy['title']) && is_string($copy['title']) && trim($copy['title']) !== '') {
                $copy['title'] = $this->deepl->translateText($copy['title'], $sourceLocale, $destLocale);
            }
        }

        if (! empty($branch['children']) && is_array($branch['children'])) {
            $copy['children'] = $this->mapTreeForSite($branch['children'], $targetSiteHandle, $sourceLocale, $destLocale);
        }

        return $copy;
    }

    protected function localizationForSite(Entry $root, string $targetSiteHandle): ?Entry
    {
        if ($root->locale() === $targetSiteHandle) {
            return $root;
        }

        return $root->in($targetSiteHandle);
    }

    protected function resolveRootInSite(Entry $root, string $siteHandle): ?Entry
    {
        if ($root->locale() === $siteHandle) {
            return $root;
        }

        return $root->in($siteHandle);
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<int, string>
     */
    protected function collectEntryIdsFromBranches(array $branches): array
    {
        $ids = [];
        foreach ($branches as $branch) {
            if (! empty($branch['entry'])) {
                $ids[] = $branch['entry'];
            }
            if (! empty($branch['children']) && is_array($branch['children'])) {
                $ids = array_merge($ids, $this->collectEntryIdsFromBranches($branch['children']));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     */
    protected function countBranches(array $branches): int
    {
        $n = count($branches);
        foreach ($branches as $branch) {
            if (! empty($branch['children']) && is_array($branch['children'])) {
                $n += $this->countBranches($branch['children']);
            }
        }

        return $n;
    }
}
