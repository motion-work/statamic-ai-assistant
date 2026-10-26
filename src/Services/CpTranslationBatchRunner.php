<?php

namespace MotionWork\StatamicAiAssistant\Services;

use MotionWork\StatamicAiAssistant\Jobs\SyncNavigationTreeJob;
use MotionWork\StatamicAiAssistant\Jobs\TranslateEntryJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CpTranslationBatchRunner
{
    public function __construct(
        protected TranslationService $translationService
    ) {}

    /**
     * @param  array<int, string>  $destinationLocales
     * @return array<int, string>
     */
    public static function normalizeDestinationLocales(array $destinationLocales, ?string $legacySingle = null): array
    {
        $destinationLocales = array_values(array_unique(array_filter($destinationLocales)));
        if (empty($destinationLocales) && $legacySingle) {
            $destinationLocales = [$legacySingle];
        }

        return array_values(array_unique(array_filter($destinationLocales)));
    }

    /**
     * @param  array<int, string>  $destinationLocales
     */
    public function countTranslationJobs(int $entryCount, array $destinationLocales, string $sourceLocale): int
    {
        $n = 0;
        foreach ($destinationLocales as $dl) {
            if ($dl !== $sourceLocale) {
                $n++;
            }
        }

        return $entryCount * $n;
    }

    /**
     * Same sync/async rules as the Bulk translations CP tool.
     *
     * @param  array<int, \Statamic\Contracts\Entries\Entry>|\Illuminate\Support\Collection  $entries
     * @param  array<int, string>  $destinationLocales
     * @return array{mode: 'sync', batch_id: string, translated: int, updated: int, skipped: int, errors: array<int, string|null>, total: int, results: array}|array{mode: 'async', batch_id: string, total: int}
     *
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function run(iterable $entries, string $sourceLocale, array $destinationLocales, bool $overwrite, int $maxDepth = 1): array
    {
        $entries = $entries instanceof Collection ? $entries : collect($entries);
        $destinationLocales = self::normalizeDestinationLocales($destinationLocales, null);

        if (empty($destinationLocales)) {
            throw new \InvalidArgumentException(__('Select at least one destination language.'));
        }

        foreach ($destinationLocales as $dl) {
            if ($dl === $sourceLocale) {
                throw new \InvalidArgumentException(__('Source and destination languages cannot be the same.'));
            }
        }

        $jobCount = $this->countTranslationJobs($entries->count(), $destinationLocales, $sourceLocale);

        if ($jobCount === 0) {
            throw new \InvalidArgumentException(__('Select at least one valid destination language.'));
        }

        // Translate referenced entries BEFORE the entries referencing them, so
        // reference remapping finds existing localizations instead of having to
        // create them mid-run (or leaving source-site ids behind).
        $entries = $this->orderByReferences($entries);

        if ($this->shouldUseAsync($jobCount)) {
            return $this->dispatchAsync($entries, $sourceLocale, $destinationLocales, $overwrite, $maxDepth);
        }

        return $this->runSync($entries, $sourceLocale, $destinationLocales, $overwrite, $maxDepth);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Statamic\Contracts\Entries\Entry>  $entries
     * @param  array<int, string>  $destinationLocales
     * @return array{mode: 'sync', batch_id: string, translated: int, updated: int, skipped: int, errors: array<int, string|null>, total: int, results: array}
     */
    protected function runSync(Collection $entries, string $sourceLocale, array $destinationLocales, bool $overwrite, int $maxDepth): array
    {
        $batchId = Str::uuid()->toString();

        $result = $this->translationService->translateBatch(
            $entries->all(),
            $sourceLocale,
            $destinationLocales,
            $overwrite,
            $batchId,
            $maxDepth,
        );

        // Heal references that pointed at not-yet-translated entries: now that
        // the whole batch is done, their localizations exist and ids can swap.
        $reconciled = $this->translationService->reconcileBatchReferences(
            self::reconcilePairs($entries, $sourceLocale, $destinationLocales),
            self::linkedEntryIdsFromResults($result['results'] ?? []),
        );

        return array_merge($result, [
            'mode' => 'sync',
            'batch_id' => $batchId,
            'reconciled' => $reconciled,
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Statamic\Contracts\Entries\Entry>  $entries
     * @param  array<int, string>  $destinationLocales
     * @return array<int, array{0: string, 1: string}>
     */
    protected static function reconcilePairs(Collection $entries, string $sourceLocale, array $destinationLocales): array
    {
        $pairs = [];
        foreach ($entries as $entry) {
            foreach ($destinationLocales as $locale) {
                if ($locale !== $sourceLocale) {
                    $pairs[] = [(string) $entry->id(), $locale];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    protected static function linkedEntryIdsFromResults(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['linked_entries'] ?? []) as $linked) {
                if (is_array($linked) && is_string($linked['entry_id'] ?? null)) {
                    $ids[] = $linked['entry_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Statamic\Contracts\Entries\Entry>  $entries
     * @param  array<int, string>  $destinationLocales
     * @return array{mode: 'async', batch_id: string, total: int}
     */
    protected function dispatchAsync(Collection $entries, string $sourceLocale, array $destinationLocales, bool $overwrite, int $maxDepth): array
    {
        $batchId = Str::uuid()->toString();

        $totalJobs = 0;
        foreach ($entries as $entry) {
            foreach ($destinationLocales as $destinationLocale) {
                if ($destinationLocale === $sourceLocale) {
                    continue;
                }
                $totalJobs++;
            }
        }

        $jobs = [];
        $jobIndex = 0;
        foreach ($entries as $entry) {
            foreach ($destinationLocales as $destinationLocale) {
                if ($destinationLocale === $sourceLocale) {
                    continue;
                }
                $jobIndex++;
                $jobs[] = new TranslateEntryJob(
                    $entry->id(),
                    $sourceLocale,
                    $destinationLocale,
                    $overwrite,
                    $batchId,
                    $maxDepth,
                    $jobIndex,
                    $totalJobs,
                );
            }
        }

        if ($totalJobs === 0) {
            throw new \InvalidArgumentException(__('No translation jobs to run.'));
        }

        Cache::put("translation:batch:{$batchId}:progress", [
            'current' => 0,
            'total' => $totalJobs,
            'current_entry' => null,
            'status' => 'queued',
        ], now()->addHours(2));

        // Serialized into the completion callback so the reconcile pass knows
        // every (entry, locale) the batch touched without re-reading the jobs.
        $reconcilePairs = self::reconcilePairs($entries, $sourceLocale, $destinationLocales);

        try {
            Bus::batch($jobs)
                ->name("Translation batch: {$batchId}")
                ->then(function () use ($batchId, $totalJobs, $reconcilePairs) {
                    $entriesData = Cache::get("translation:batch:{$batchId}:entries", []);
                    $rows = collect($entriesData);
                    $legacySkippedCount = $rows->where('status', 'skipped')->count();
                    $success = $rows->filter(fn ($row) => ($row['status'] ?? '') === 'completed');
                    $skippedSuccess = $success->filter(fn ($row) => ($row['skipped'] ?? false) === true);
                    $notSkipped = $success->filter(fn ($row) => ! ($row['skipped'] ?? false));
                    $translated = $notSkipped->filter(fn ($row) => ($row['is_new'] ?? false) === true)->count()
                        + $skippedSuccess->count()
                        + $legacySkippedCount;
                    $updated = $notSkipped->filter(fn ($row) => ($row['is_new'] ?? false) === false)->count();
                    $failed = $rows->where('status', 'failed');
                    $errors = $failed->pluck('error')->filter()->values()->toArray();

                    $linkedCreatedTotal = 0;
                    foreach ($entriesData as $row) {
                        $linkedCreatedTotal += count($row['linked_entries'] ?? []);
                    }

                    // Post-batch reconcile: parallel jobs may have translated a
                    // referencing page before its linked entries existed in the
                    // target site — those references kept source-site ids. All
                    // translations exist now, so remap them (remap-only pass;
                    // creates nothing, calls no translation API).
                    $reconciled = 0;
                    try {
                        $reconciled = app(TranslationService::class)->reconcileBatchReferences(
                            $reconcilePairs,
                            self::linkedEntryIdsFromResults(array_values($entriesData)),
                        );
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Translation batch reference reconcile failed', [
                            'batch_id' => $batchId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    Cache::put("translation:batch:{$batchId}:progress", [
                        'current' => $totalJobs,
                        'total' => $totalJobs,
                        'current_entry' => null,
                        'status' => 'completed',
                        'translated' => $translated,
                        'updated' => $updated,
                        'skipped' => 0,
                        'errors' => $errors,
                        'linked_created_total' => $linkedCreatedTotal,
                        'reconciled' => $reconciled,
                    ], now()->addHours(2));
                })
                ->catch(function ($batch, \Throwable $e) use ($batchId) {
                    Cache::put("translation:batch:{$batchId}:progress", array_merge(
                        Cache::get("translation:batch:{$batchId}:progress", []),
                        ['status' => 'failed', 'fatal_error' => $e->getMessage()]
                    ), now()->addHours(2));
                })
                ->dispatch();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                __('Failed to dispatch translation batch. Make sure a queue driver is configured.'),
                0,
                $e
            );
        }

        return [
            'mode' => 'async',
            'batch_id' => $batchId,
            'total' => $totalJobs,
        ];
    }

    /**
     * Topologically order the selection so entries whose ids appear inside
     * OTHER selected entries' data come first (referenced before referencing).
     * Detection is a cheap substring scan of the raw data — ids are UUIDs, so
     * false positives are practically impossible. Cycles (A ↔ B) fall back to
     * the original selection order for the entries involved.
     *
     * @param  \Illuminate\Support\Collection<int, \Statamic\Contracts\Entries\Entry>  $entries
     * @return \Illuminate\Support\Collection<int, \Statamic\Contracts\Entries\Entry>
     */
    protected function orderByReferences(Collection $entries): Collection
    {
        if ($entries->count() < 2) {
            return $entries;
        }

        $ids = $entries->map(fn ($e) => (string) $e->id())->all();

        $payloads = [];
        foreach ($entries as $entry) {
            $payloads[(string) $entry->id()] = json_encode($entry->data()->toArray()) ?: '';
        }

        // id => selected ids it references (its dependencies).
        $deps = [];
        foreach ($payloads as $id => $json) {
            foreach ($ids as $other) {
                if ($other !== $id && str_contains($json, $other)) {
                    $deps[$id][] = $other;
                }
            }
        }

        $ordered = [];
        $emitted = [];
        $remaining = $ids;

        while ($remaining !== []) {
            $next = [];
            $progress = false;

            foreach ($remaining as $id) {
                $unmet = array_filter($deps[$id] ?? [], fn ($d) => ! isset($emitted[$d]));

                if ($unmet === []) {
                    $ordered[] = $id;
                    $emitted[$id] = true;
                    $progress = true;
                } else {
                    $next[] = $id;
                }
            }

            if (! $progress) {
                // Reference cycle — emit the rest in selection order.
                array_push($ordered, ...$next);
                break;
            }

            $remaining = $next;
        }

        $byId = $entries->keyBy(fn ($e) => (string) $e->id());

        return collect($ordered)->map(fn ($id) => $byId[$id])->values();
    }

    /**
     * Same sync/async rules as before, factored out so the navigation sync can
     * share them. $ignoreThreshold: a nav-sync "job" is one whole locale (which
     * may translate many pages), so the per-job threshold is a bad proxy —
     * navigation prefers async whenever a real queue is available.
     */
    protected function shouldUseAsync(int $jobCount, bool $ignoreThreshold = false): bool
    {
        $mode = config('statamic-ai-assistant.translation_mode', 'auto');
        $threshold = config('statamic-ai-assistant.translation_sync_threshold', 3);
        $queueDriver = config('queue.default', 'sync');
        $canAsync = $queueDriver !== 'sync' && $this->jobBatchesTableExists();

        if (! $canAsync || $mode === 'sync') {
            return false;
        }

        return $mode === 'async' || $ignoreThreshold || $jobCount > $threshold;
    }

    /**
     * Navigation tree sync through the same queue + progress-cache machinery as
     * entry batches: one job per destination locale, streaming a live row per
     * translated page. Falls back to the inline sync when no queue is available.
     *
     * @param  array<int, string>  $destinationLocales
     * @return array{mode: 'sync', results: array<int, array<string, mixed>>}|array{mode: 'async', batch_id: string, total: int}
     */
    public function runNavigationSync(
        NavigationTreeSyncService $syncService,
        string $navHandle,
        array $destinationLocales,
        bool $overwrite,
        int $maxDepth,
    ): array {
        $destinationLocales = self::normalizeDestinationLocales($destinationLocales, null);

        if (empty($destinationLocales)) {
            throw new \InvalidArgumentException(__('Select at least one destination language.'));
        }

        if (! $this->shouldUseAsync(count($destinationLocales), ignoreThreshold: true)) {
            $result = $syncService->sync($navHandle, $destinationLocales, $overwrite, $maxDepth);

            return array_merge($result, ['mode' => 'sync']);
        }

        $batchId = Str::uuid()->toString();
        $totalJobs = count($destinationLocales);

        $jobs = [];
        foreach ($destinationLocales as $i => $locale) {
            $jobs[] = new SyncNavigationTreeJob(
                $navHandle,
                $locale,
                $overwrite,
                $maxDepth,
                $batchId,
                $i + 1,
                $totalJobs,
            );
        }

        Cache::put("translation:batch:{$batchId}:progress", [
            'current' => 0,
            'total' => $totalJobs,
            'current_entry' => null,
            'status' => 'queued',
        ], now()->addHours(2));

        try {
            Bus::batch($jobs)
                ->name("Navigation sync batch: {$batchId}")
                ->then(function () use ($batchId, $totalJobs) {
                    $rows = collect(Cache::get("translation:batch:{$batchId}:entries", []));

                    $navPrefix = 'navigation'."\x1e";
                    $navRows = $rows->filter(fn ($row, $key) => str_starts_with((string) $key, $navPrefix));
                    $pageRows = $rows->reject(fn ($row, $key) => str_starts_with((string) $key, $navPrefix));

                    $navWarnings = $navRows
                        ->flatMap(fn ($row) => (array) ($row['navigation_warnings'] ?? []))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    $linkedCreatedTotal = $pageRows->sum(fn ($row) => count($row['linked_entries'] ?? []));

                    Cache::put("translation:batch:{$batchId}:progress", [
                        'current' => $totalJobs,
                        'total' => $totalJobs,
                        'current_entry' => null,
                        'status' => 'completed',
                        'translated' => $pageRows->where('status', 'completed')->count(),
                        'updated' => 0,
                        'skipped' => 0,
                        'errors' => $rows->where('status', 'failed')->pluck('error')->filter()->values()->all(),
                        'linked_created_total' => $linkedCreatedTotal,
                        'navigation_warnings' => $navWarnings,
                    ], now()->addHours(2));
                })
                ->catch(function ($batch, \Throwable $e) use ($batchId) {
                    Cache::put("translation:batch:{$batchId}:progress", array_merge(
                        Cache::get("translation:batch:{$batchId}:progress", []),
                        ['status' => 'failed', 'fatal_error' => $e->getMessage()]
                    ), now()->addHours(2));
                })
                ->dispatch();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                __('Failed to dispatch translation batch. Make sure a queue driver is configured.'),
                0,
                $e
            );
        }

        return [
            'mode' => 'async',
            'batch_id' => $batchId,
            'total' => $totalJobs,
        ];
    }

    private function jobBatchesTableExists(): bool
    {
        try {
            return Schema::hasTable('job_batches');
        } catch (\Throwable) {
            return false;
        }
    }
}
