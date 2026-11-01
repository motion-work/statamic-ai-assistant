<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use MotionWork\StatamicAiAssistant\Support\NavigationTree;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;

/**
 * A source tree's branches for a target site: entry branches point at that
 * site's localization and take its title, URL branches get a translated label.
 */
final class NavigationBranchMapper
{
    public function __construct(private readonly DeeplService $deepl) {}

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @return array<int, array<string, mixed>>
     */
    public function mapTreeForSite(
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
    private function mapBranchForSite(
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
            $localized = NavigationTree::localization($root, $targetSiteHandle);
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
}
