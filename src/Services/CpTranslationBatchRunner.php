<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Jobs\SyncNavigationTreeJob;
use MotionWork\StatamicAiAssistant\Jobs\TranslateEntryJob;
use MotionWork\StatamicAiAssistant\Support\ReferenceOrder;
use Statamic\Contracts\Entries\Entry;

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
     * @param  array<int, Entry>|Collection  $entries
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
        $entries = ReferenceOrder::sort($entries);

        if ($this->shouldUseAsync($jobCount)) {
            return $this->dispatchAsync($entries, $sourceLocale, $destinationLocales, $overwrite, $maxDepth);
        }

        return $this->runSync($entries, $sourceLocale, $destinationLocales, $overwrite, $maxDepth);
    }

    /**
     * @param  Collection<int, Entry>  $entries
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
            TranslationBatchCompletion::linkedEntryIdsFromResults($result['results'] ?? []),
        );

        return array_merge($result, [
            'mode' => 'sync',
            'batch_id' => $batchId,
            'reconciled' => $reconciled,
        ]);
    }

    /**
     * @param  Collection<int, Entry>  $entries
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
     * @param  Collection<int, Entry>  $entries
     * @param  array<int, string>  $destinationLocales
     * @return array{mode: 'async', batch_id: string, total: int}
     */
    protected function dispatchAsync(Collection $entries, string $sourceLocale, array $destinationLocales, bool $overwrite, int $maxDepth): array
    {
        $batchId = Str::uuid()->toString();

        $jobs = self::translationJobs($entries, $sourceLocale, $destinationLocales, $overwrite, $batchId, $maxDepth);
        $totalJobs = count($jobs);

        if ($totalJobs === 0) {
            throw new \InvalidArgumentException(__('No translation jobs to run.'));
        }

        // Serialized into the completion callback so the reconcile pass knows
        // every (entry, locale) the batch touched without re-reading the jobs.
        $reconcilePairs = self::reconcilePairs($entries, $sourceLocale, $destinationLocales);

        TranslationBatchCompletion::dispatchTracked("Translation batch: {$batchId}", $jobs, $batchId, static fn () => TranslationBatchCompletion::finishBatch($batchId, $totalJobs, $reconcilePairs));

        return [
            'mode' => 'async',
            'batch_id' => $batchId,
            'total' => $totalJobs,
        ];
    }

    /**
     * One TranslateEntryJob per (entry, destination) pair, skipping the source locale.
     *
     * @param  Collection<int, Entry>  $entries
     * @param  array<int, string>  $destinationLocales
     * @return array<int, TranslateEntryJob>
     */
    private static function translationJobs(Collection $entries, string $sourceLocale, array $destinationLocales, bool $overwrite, string $batchId, int $maxDepth): array
    {
        $pairs = [];
        foreach ($entries as $entry) {
            foreach ($destinationLocales as $destinationLocale) {
                if ($destinationLocale !== $sourceLocale) {
                    $pairs[] = [$entry->id(), $destinationLocale];
                }
            }
        }

        return array_map(
            fn (array $pair) => new TranslateEntryJob($pair[0], $sourceLocale, $pair[1], $overwrite, $batchId, $maxDepth),
            $pairs,
        );
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

        $jobs = array_map(
            fn (string $locale) => new SyncNavigationTreeJob($navHandle, $locale, $overwrite, $maxDepth, $batchId),
            $destinationLocales,
        );

        TranslationBatchCompletion::dispatchTracked("Navigation sync batch: {$batchId}", $jobs, $batchId, static fn () => TranslationBatchCompletion::finishNavigationBatch($batchId, $totalJobs));

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
        } catch (\Throwable $e) {
            Log::warning('[translation] job_batches check failed, translating synchronously', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Batches queued before an upgrade carry callbacks that call these by
     * their old names, so they still finish.
     *
     * @param  array<int, array<string, mixed>>  $reconcilePairs
     */
    private static function finishBatch(string $batchId, int $totalJobs, array $reconcilePairs): void
    {
        TranslationBatchCompletion::finishBatch($batchId, $totalJobs, $reconcilePairs);
    }

    private static function finishNavigationBatch(string $batchId, int $totalJobs): void
    {
        TranslationBatchCompletion::finishNavigationBatch($batchId, $totalJobs);
    }
}
