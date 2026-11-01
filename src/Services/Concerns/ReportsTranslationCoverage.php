<?php

namespace MotionWork\StatamicAiAssistant\Services\Concerns;

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

/**
 * Read-only overview for the bulk translations page: entry counts per
 * multi-site collection and site, and the navigations that can be synced.
 */
trait ReportsTranslationCoverage
{
    /**
     * Get translation coverage statistics for all collections.
     */
    public function getTranslationStatus(): array
    {
        $sites = Site::all();
        $collections = Collection::all();
        $status = [];

        foreach ($collections as $collection) {
            if (! $collection->sites() || $collection->sites()->count() <= 1) {
                continue;
            }

            $collectionStatus = [
                'handle' => $collection->handle(),
                'title' => $collection->title(),
                'sites' => [],
            ];

            foreach ($sites as $site) {
                if (! $collection->sites()->contains($site->handle())) {
                    continue;
                }

                $entries = Entry::query()
                    ->where('collection', $collection->handle())
                    ->where('site', $site->handle())
                    ->get();

                $collectionStatus['sites'][$site->handle()] = [
                    'locale' => $site->locale(),
                    'name' => $site->name(),
                    'count' => $entries->count(),
                ];
            }

            $status[] = $collectionStatus;
        }

        return $status;
    }

    /**
     * @return array<int, array{handle: string, title: string}>
     */
    public function getNavigationsList(): array
    {
        return Nav::all()->map(function ($nav) {
            return [
                'handle' => $nav->handle(),
                'title' => $nav->title(),
            ];
        })->values()->all();
    }
}
