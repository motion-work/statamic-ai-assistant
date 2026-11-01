<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

/**
 * Reads navigation trees for the sync: which site's tree is the source, the
 * entries it links, and a page's localization in another site.
 */
final class NavigationTree
{
    /**
     * Prefer the default site's tree; if it is empty or has no page entries, use the first other site that does.
     * This matches CP reality where e.g. English is default but only German has the nav built yet.
     *
     * @param  \Statamic\Contracts\Structures\Nav  $nav
     * @return array{site: \Statamic\Sites\Site, tree: array<int, array<string, mixed>>, url_only: bool}
     */
    public static function sourceTree($nav): array
    {
        $candidateUrlOnly = null;

        foreach (self::siteHandlesDefaultFirst() as $handle) {
            $navTree = $nav->in($handle);
            if (! $navTree) {
                continue;
            }

            $tree = $navTree->tree();
            if ($tree === [] || $tree === null) {
                continue;
            }

            $ids = self::entryIds($tree);
            if ($ids !== []) {
                $site = Site::get($handle);
                if ($site) {
                    return ['site' => $site, 'tree' => $tree, 'url_only' => false];
                }
            }
            if (self::countBranches($tree) > 0 && $candidateUrlOnly === null) {
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
    private static function siteHandlesDefaultFirst(): array
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

    public static function localization(Entry $root, string $targetSiteHandle): ?Entry
    {
        if ($root->locale() === $targetSiteHandle) {
            return $root;
        }

        return $root->in($targetSiteHandle);
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<int, string>
     */
    public static function entryIds(array $branches): array
    {
        $ids = [];
        foreach ($branches as $branch) {
            if (! empty($branch['entry'])) {
                $ids[] = $branch['entry'];
            }
            if (! empty($branch['children']) && is_array($branch['children'])) {
                $ids = array_merge($ids, self::entryIds($branch['children']));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     */
    public static function countBranches(array $branches): int
    {
        $n = count($branches);
        foreach ($branches as $branch) {
            if (! empty($branch['children']) && is_array($branch['children'])) {
                $n += self::countBranches($branch['children']);
            }
        }

        return $n;
    }

    /**
     * Linked pages without a localization in $siteHandle, by their source-site entry.
     *
     * @param  array<int, string>  $entryIds
     * @return array<int, array{source_entry_id: string, title: string}>
     */
    public static function missingPages(array $entryIds, string $siteHandle, string $sourceSiteHandle): array
    {
        $missing = [];
        foreach ($entryIds as $entryId) {
            $entry = EntryFacade::find($entryId);
            if (! $entry) {
                continue;
            }
            $root = $entry->root();
            if (self::localization($root, $siteHandle) !== null) {
                continue;
            }
            $sourceRoot = self::localization($root, $sourceSiteHandle);
            if (! $sourceRoot) {
                continue;
            }
            $missing[] = [
                'source_entry_id' => $sourceRoot->id(),
                'title' => EntryLabel::for($sourceRoot),
            ];
        }

        return collect($missing)->unique('source_entry_id')->values()->all();
    }
}
