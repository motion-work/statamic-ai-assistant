<?php

namespace MotionWork\StatamicAiAssistant\Services\Concerns;

use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;

/**
 * With overwrite off, which selected entries already have a localization in a
 * target locale. The CP action, the bulk tool and the confirm dialog share this rule.
 */
trait ChecksOverwriteConflicts
{
    /** The entry in the source site, or null when it has no localization there. */
    abstract protected function resolveSourceEntry(Entry $entry, $sourceSite): ?Entry;

    /**
     * Per target locale: pages (source titles) that already have a localization, when overwrite is off.
     *
     * @param  array<int, Entry>  $entries
     * @param  array<int, string>  $destinationLocales
     * @return array<int, array{locale: string, locale_label: string, entry_titles: array<int, string>}>
     */
    public function conflictDetailsWithoutOverwrite(array $entries, string $sourceLocale, array $destinationLocales): array
    {
        $destinationLocales = array_values(array_unique(array_filter($destinationLocales)));
        $sourceSite = Site::all()->firstWhere('locale', $sourceLocale);
        if (! $sourceSite) {
            return [];
        }

        $blocks = [];
        foreach ($destinationLocales as $destLocale) {
            if ($destLocale === $sourceLocale) {
                continue;
            }
            $destSite = Site::all()->firstWhere('locale', $destLocale);
            if (! $destSite) {
                continue;
            }
            $titles = [];
            foreach ($entries as $entry) {
                $sourceEntry = $this->resolveSourceEntry($entry, $sourceSite);
                if (! $sourceEntry) {
                    continue;
                }
                if ($sourceEntry->existsIn($destSite->handle())) {
                    $titles[] = EntryLabel::for($sourceEntry);
                }
            }
            if ($titles === []) {
                continue;
            }
            $titles = array_values(array_unique($titles));
            sort($titles, SORT_NATURAL | SORT_FLAG_CASE);
            $site = Site::all()->firstWhere('locale', $destLocale);
            $blocks[] = [
                'locale' => $destLocale,
                'locale_label' => $site ? $this->siteLocaleDisplayLabel($site, $destLocale) : $destLocale,
                'entry_titles' => $titles,
            ];
        }

        return $blocks;
    }

    /**
     * Destination locales (among the requested set) where at least one entry already has a localization.
     *
     * @param  array<int, Entry>  $entries
     * @param  array<int, string>  $destinationLocales
     * @return array<int, string>
     */
    public function conflictingDestinationLocalesWithoutOverwrite(array $entries, string $sourceLocale, array $destinationLocales): array
    {
        return array_column($this->conflictDetailsWithoutOverwrite($entries, $sourceLocale, $destinationLocales), 'locale');
    }

    protected function siteLocaleDisplayLabel($site, string $locale): string
    {
        $name = (string) $site->name();
        $name = preg_replace('/^[\x{1F1E6}-\x{1F1FF}]{2}\s+/u', '', $name);
        $name = trim($name);

        return $name !== '' ? $name.' ('.$locale.')' : $locale;
    }

    /**
     * True when overwrite is off and at least one entry already has a localization in a selected destination locale.
     * Matches the Bulk translations tool conflict rule.
     *
     * @param  array<int, Entry>  $entries
     * @param  array<int, string>  $destinationLocales
     */
    public function hasConflictWithoutOverwrite(array $entries, string $sourceLocale, array $destinationLocales): bool
    {
        return count($this->conflictingDestinationLocalesWithoutOverwrite($entries, $sourceLocale, $destinationLocales)) > 0;
    }
}
