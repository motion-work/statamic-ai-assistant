<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

class TranslationService
{
    protected EntryTranslator $entryTranslator;

    public function __construct(EntryTranslator $entryTranslator, ?TermTranslator $termTranslator = null)
    {
        $this->entryTranslator = $entryTranslator;
        $this->termTranslator = $termTranslator ?? app(TermTranslator::class);
    }

    protected TermTranslator $termTranslator;

    /**
     * Translate a single taxonomy term into a destination locale. Terms
     * localize in place (same term id, per-site data), so there is no
     * origin-graph or linked-entry handling — but the result mirrors
     * translateEntry()'s shape so the CP progress UI renders it identically.
     *
     * @return array<string, mixed>
     */
    public function translateTerm(string $termId, string $sourceLocale, string $destinationLocale, bool $overwrite = true): array
    {
        $sourceSite = Site::all()->firstWhere('locale', $sourceLocale);
        $destinationSite = Site::all()->firstWhere('locale', $destinationLocale);

        if (! $sourceSite || ! $destinationSite) {
            return $this->termErrorResult($termId, __('Source or destination site not found.'), $destinationLocale);
        }

        $term = \Statamic\Facades\Term::find($termId);
        if ($term && method_exists($term, 'term')) {
            $term = $term->term();
        }

        if (! $term) {
            return $this->termErrorResult($termId, __('Term not found: :id', ['id' => $termId]), $destinationLocale);
        }

        $originTitle = (string) ($term->in($sourceSite->handle())->title() ?? $term->slug());

        $existingData = $term->dataForLocale($destinationSite->handle());
        $exists = collect($existingData)->isNotEmpty();

        if ($exists && ! $overwrite) {
            return [
                'success' => true,
                'entry_id' => $termId,
                'source_entry_id' => $termId,
                'title' => $originTitle,
                'origin_title' => $originTitle,
                'target_title' => (string) ($term->in($destinationSite->handle())->title() ?? $originTitle),
                'is_new' => false,
                'skipped' => true,
                'error' => null,
                'edit_url' => $term->in($destinationSite->handle())->editUrl(),
                'destination_locale' => $destinationLocale,
                'linked_entries' => [],
            ];
        }

        try {
            $localized = $this->termTranslator->translateTerm($term, $sourceSite->handle(), $destinationSite->handle());
            $untranslatedFields = $this->termTranslator->takeSkippedNonLocalizable();

            Log::info('TranslationService: Term translated via DeepL', [
                'term_id' => $termId,
                'source' => $sourceLocale,
                'destination' => $destinationLocale,
                'is_new' => ! $exists,
            ]);

            return [
                'success' => true,
                'entry_id' => $termId,
                'source_entry_id' => $termId,
                'edit_url' => $localized->editUrl(),
                'title' => $originTitle,
                'origin_title' => $originTitle,
                'target_title' => (string) ($localized->title() ?? $originTitle),
                'is_new' => ! $exists,
                'skipped' => false,
                'error' => null,
                'destination_locale' => $destinationLocale,
                'linked_entries' => [],
                'unresolved_references' => [],
                'untranslated_fields' => $untranslatedFields,
            ];
        } catch (\Exception $e) {
            Log::error('TranslationService: DeepL term translation failed', [
                'term_id' => $termId,
                'error' => $e->getMessage(),
            ]);

            return $this->termErrorResult($termId, __('Translation failed for term :title: :error', [
                'title' => $originTitle,
                'error' => $e->getMessage(),
            ]), $destinationLocale);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function termErrorResult(string $termId, string $error, string $destinationLocale): array
    {
        return [
            'success' => false,
            'entry_id' => $termId,
            'source_entry_id' => $termId,
            'title' => $termId,
            'origin_title' => $termId,
            'target_title' => null,
            'is_new' => false,
            'skipped' => false,
            'error' => $error,
            'edit_url' => null,
            'destination_locale' => $destinationLocale,
            'linked_entries' => [],
        ];
    }

    /**
     * Translate a single entry from source locale to destination locale using DeepL.
     *
     * @return array{success: bool, entry_id: string, title: string, origin_title: string, target_title: ?string, is_new: bool, skipped: bool, error: string|null, edit_url: ?string, destination_locale: string}
     */
    public function translateEntry(
        Entry $entry,
        string $sourceLocale,
        string $destinationLocale,
        bool $overwrite = true,
        int $maxDepth = 1,
    ): array {
        $sourceSite = Site::all()->firstWhere('locale', $sourceLocale);
        $destinationSite = Site::all()->firstWhere('locale', $destinationLocale);

        if (! $sourceSite || ! $destinationSite) {
            return $this->errorResult($entry, __('Source or destination site not found.'), $destinationLocale);
        }

        $sourceEntry = $this->resolveSourceEntry($entry, $sourceSite);
        if (! $sourceEntry) {
            return $this->errorResult($entry, __('Source language entry does not exist for entry :title', [
                'title' => EntryLabel::for($entry),
            ]), $destinationLocale);
        }

        $originTitle = EntryLabel::for($sourceEntry);

        $existingTarget = null;
        if ($sourceEntry->existsIn($destinationSite->handle())) {
            $existingTarget = $sourceEntry->in($destinationSite->handle());

            if (! $overwrite) {
                return [
                    'success' => true,
                    'entry_id' => $existingTarget->id(),
                    'source_entry_id' => $sourceEntry->id(),
                    'title' => $originTitle,
                    'origin_title' => $originTitle,
                    'target_title' => EntryLabel::for($existingTarget),
                    'is_new' => false,
                    'skipped' => true,
                    'error' => null,
                    'edit_url' => $existingTarget->editUrl(),
                    'destination_locale' => $destinationLocale,
                    'linked_entries' => [],
                ];
            }
        }

        try {
            $targetEntry = $this->entryTranslator->translateEntry(
                $sourceEntry,
                $destinationSite->handle(),
                $existingTarget,
                0,
                $maxDepth,
            );

            $linkedEntries = $this->entryTranslator->takeLinkedEntriesCreated();
            $primaryId = $targetEntry->id();
            $linkedEntries = array_values(array_filter(
                $linkedEntries,
                fn (array $e) => ($e['entry_id'] ?? '') !== $primaryId
            ));

            // References left pointing at source-site entries (linked entry not
            // translated yet / depth budget exhausted). The post-batch reconcile
            // pass heals the ones translated later in the same batch; the rest
            // stay visible as a warning so editors know to translate them.
            $unresolved = $this->entryTranslator->takeUnresolvedReferences();

            // Text-bearing fields skipped due to localizable: false — shown as a
            // warning so "still in the source language" is never a mystery.
            $untranslatedFields = $this->entryTranslator->takeSkippedNonLocalizable();

            Log::info('TranslationService: Entry translated via DeepL', [
                'entry_id' => $targetEntry->id(),
                'source' => $sourceLocale,
                'destination' => $destinationLocale,
                'is_new' => $existingTarget === null,
            ]);

            return [
                'success' => true,
                'entry_id' => $targetEntry->id(),
                'source_entry_id' => $sourceEntry->id(),
                'edit_url' => $targetEntry->editUrl(),
                'title' => $originTitle,
                'origin_title' => $originTitle,
                'target_title' => EntryLabel::for($targetEntry),
                'is_new' => $existingTarget === null,
                'skipped' => false,
                'error' => null,
                'destination_locale' => $destinationLocale,
                'linked_entries' => $linkedEntries,
                'unresolved_references' => $unresolved,
                'untranslated_fields' => $untranslatedFields,
            ];
        } catch (\Exception $e) {
            $this->entryTranslator->resetLinkedEntriesBuffer();

            Log::error('TranslationService: DeepL translation failed', [
                'entry_id' => $sourceEntry->id(),
                'error' => $e->getMessage(),
            ]);

            return $this->errorResult($sourceEntry, __('Translation failed for entry :title: :error', [
                'title' => $originTitle,
                'error' => $e->getMessage(),
            ]), $destinationLocale);
        }
    }

    /**
     * Translate multiple entries to one or more destination locales.
     *
     * @param  array<int, string>  $destinationLocales
     * @return array{translated: int, updated: int, skipped: int, errors: array, total: int, results: array}
     */
    public function translateBatch(
        array $entries,
        string $sourceLocale,
        array $destinationLocales,
        bool $overwrite = true,
        ?string $batchId = null,
        int $maxDepth = 1,
    ): array {
        $translated = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $results = [];

        $destinationLocales = array_values(array_unique(array_filter($destinationLocales)));
        $pairs = [];
        foreach ($entries as $entry) {
            foreach ($destinationLocales as $destinationLocale) {
                if ($destinationLocale === $sourceLocale) {
                    continue;
                }
                $pairs[] = [$entry, $destinationLocale];
            }
        }

        $total = count($pairs);
        $index = 0;

        foreach ($pairs as [$entry, $destinationLocale]) {
            if ($batchId) {
                // 1-based step so the bar moves off 0% while the first item is translating.
                $this->updateBatchProgress($batchId, $index + 1, $total, $entry, $destinationLocale);
            }

            $result = $this->translateEntry($entry, $sourceLocale, $destinationLocale, $overwrite, $maxDepth);
            $results[] = $result;

            if (! $result['success']) {
                $errors[] = $result['error'];
                if ($batchId) {
                    $this->updateEntryStatus(
                        $batchId,
                        $entry->id(),
                        $destinationLocale,
                        'failed',
                        $result['error'],
                        $this->entryStatusPayload($result)
                    );
                }
                $index++;

                continue;
            }

            if ($result['skipped'] ?? false) {
                $skipped++;
                if ($batchId) {
                    $this->updateEntryStatus(
                        $batchId,
                        $entry->id(),
                        $destinationLocale,
                        'completed',
                        null,
                        $this->entryStatusPayload($result)
                    );
                }
                $index++;

                continue;
            }

            if ($result['is_new']) {
                $translated++;
            } else {
                $updated++;
            }

            if ($batchId) {
                $this->updateEntryStatus(
                    $batchId,
                    $entry->id(),
                    $destinationLocale,
                    'completed',
                    null,
                    $this->entryStatusPayload($result)
                );
            }
            $index++;
        }

        if ($batchId) {
            $this->finalizeBatchProgress($batchId, $translated + $skipped, $updated, 0, $errors, $total);
        }

        $linkedCreatedTotal = 0;
        foreach ($results as $r) {
            $linkedCreatedTotal += count($r['linked_entries'] ?? []);
        }

        return [
            'translated' => $translated + $skipped,
            'updated' => $updated,
            'skipped' => 0,
            'errors' => $errors,
            'total' => $total,
            'results' => $results,
            'linked_created_total' => $linkedCreatedTotal,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Post-batch reference reconcile: re-run the reference pass in remap-only
     * mode over every localization the batch produced (plus linked entries it
     * auto-created). Heals references that were left pointing at source-site
     * entries because the linked entry had no translation yet when the
     * referencing page was translated — after the batch, those translations
     * exist, so the ids can be swapped. Never creates entries, never calls
     * DeepL. Returns the number of entries whose references were healed.
     *
     * @param  array<int, array{0: string, 1: string}>  $entryLocalePairs  [entryId, destinationLocale]
     * @param  array<int, string>  $extraTargetEntryIds  Localization ids to reconcile directly (e.g. auto-created linked entries)
     */
    public function reconcileBatchReferences(array $entryLocalePairs, array $extraTargetEntryIds = []): int
    {
        $targets = [];

        foreach ($entryLocalePairs as $pair) {
            if (! is_array($pair) || count($pair) !== 2) {
                continue;
            }
            [$entryId, $locale] = $pair;

            $site = Site::all()->firstWhere('locale', $locale);
            $entry = \Statamic\Facades\Entry::find($entryId);

            // in() walks the localization graph from whichever localization the
            // caller selected, so this finds the batch's target entry directly.
            $target = ($site && $entry) ? $entry->in($site->handle()) : null;

            if ($target) {
                $targets[(string) $target->id()] = $target;
            }
        }

        foreach ($extraTargetEntryIds as $id) {
            if (is_string($id) && $id !== '' && ! isset($targets[$id]) && ($entry = \Statamic\Facades\Entry::find($id))) {
                $targets[$id] = $entry;
            }
        }

        $healed = 0;

        foreach ($targets as $target) {
            try {
                if ($this->entryTranslator->remapEntryReferences($target)) {
                    $healed++;
                }
            } catch (\Throwable $e) {
                Log::warning('TranslationService: reference reconcile failed for entry', [
                    'entry_id' => (string) $target->id(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Drain the remap-only pass's bookkeeping — still-unresolved ids were
        // already reported per entry during translation.
        $this->entryTranslator->takeUnresolvedReferences();

        return $healed;
    }

    protected function entryStatusPayload(array $result): array
    {
        return [
            'origin_title' => $result['origin_title'] ?? $result['title'] ?? null,
            'target_title' => $result['target_title'] ?? null,
            'edit_url' => $result['edit_url'] ?? null,
            'target_entry_id' => $result['entry_id'] ?? null,
            'source_entry_id' => $result['source_entry_id'] ?? null,
            'destination_locale' => $result['destination_locale'] ?? null,
            'is_new' => $result['is_new'] ?? null,
            'skipped' => (bool) ($result['skipped'] ?? false),
            'linked_entries' => $result['linked_entries'] ?? [],
            'unresolved_references' => $result['unresolved_references'] ?? [],
            'untranslated_fields' => $result['untranslated_fields'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function entryStatusPayloadForBatch(array $result): array
    {
        return $this->entryStatusPayload($result);
    }

    /**
     * Get translation coverage statistics for all collections.
     */
    public function getTranslationStatus(): array
    {
        $sites = Site::all();
        $collections = \Statamic\Facades\Collection::all();
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

                $entries = \Statamic\Facades\Entry::query()
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

    protected function resolveSourceEntry(Entry $entry, $sourceSite): ?Entry
    {
        if ($entry->site()->handle() === $sourceSite->handle()) {
            return $entry;
        }

        if ($entry->existsIn($sourceSite->handle())) {
            return $entry->in($sourceSite->handle());
        }

        return null;
    }

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

    protected function errorResult(Entry $entry, string $error, ?string $destinationLocale = null): array
    {
        $title = EntryLabel::for($entry);

        return [
            'success' => false,
            'entry_id' => $entry->id(),
            'source_entry_id' => $entry->id(),
            'title' => $title,
            'origin_title' => $title,
            'target_title' => null,
            'is_new' => false,
            'skipped' => false,
            'error' => $error,
            'edit_url' => null,
            'destination_locale' => $destinationLocale ?? '',
            'linked_entries' => [],
        ];
    }

    // --- Batch progress helpers (cache-based) ---

    /**
     * @param  int  $current  1-based index of the operation being processed (1 … $total).
     */
    public function updateBatchProgress(string $batchId, int $current, int $total, Entry $entry, ?string $destinationLocale = null): void
    {
        $label = EntryLabel::for($entry);
        if ($destinationLocale) {
            $site = Site::all()->firstWhere('locale', $destinationLocale);
            if ($site) {
                $label .= ' → '.$site->name();
            }
        }

        $current = min(max(1, $current), max(1, $total));

        Cache::put("translation:batch:{$batchId}:progress", [
            'current' => $current,
            'total' => $total,
            'current_entry' => $label,
            'status' => 'processing',
        ], now()->addHours(2));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateEntryStatus(
        string $batchId,
        string $entryId,
        string $destinationLocale,
        string $status,
        ?string $error = null,
        array $payload = []
    ): void {
        $key = "translation:batch:{$batchId}:entries";
        $entries = Cache::get($key, []);
        $compositeKey = $entryId."\x1e".$destinationLocale;
        $entries[$compositeKey] = array_merge([
            'status' => $status,
            'error' => $error,
            'entry_id' => $entryId,
            'destination_locale' => $destinationLocale,
            'completed_at' => now()->toDateTimeString(),
        ], $payload);
        Cache::put($key, $entries, now()->addHours(2));
    }

    public function finalizeBatchProgress(
        string $batchId,
        int $translated,
        int $updated,
        int $skipped,
        array $errors,
        int $total
    ): void {
        Cache::put("translation:batch:{$batchId}:progress", [
            'current' => $total,
            'total' => $total,
            'current_entry' => null,
            'status' => 'completed',
            'translated' => $translated,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ], now()->addHours(2));
    }

    /**
     * Read batch progress from cache.
     */
    public function getBatchProgress(string $batchId): ?array
    {
        $progress = Cache::get("translation:batch:{$batchId}:progress");
        $entries = Cache::get("translation:batch:{$batchId}:entries", []);

        if (! $progress) {
            return null;
        }

        foreach ($entries as $key => $row) {
            if (($row['status'] ?? '') === 'skipped') {
                $entries[$key]['status'] = 'completed';
                $entries[$key]['skipped'] = true;
            }
        }

        if (isset($progress['skipped']) && (int) $progress['skipped'] > 0) {
            $progress['translated'] = ($progress['translated'] ?? 0) + (int) $progress['skipped'];
            $progress['skipped'] = 0;
        }

        return array_merge($progress, ['entries' => $entries]);
    }
}
