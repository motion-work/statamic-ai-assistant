<?php

namespace MotionWork\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Entries\Entry;

/**
 * Queued translation and navigation batches: dispatch with a progress row in
 * the cache, and the summary written when the batch fails or finishes. Static
 * throughout, because the callbacks are serialized with the batch.
 */
final class TranslationBatchCompletion
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    public static function linkedEntryIdsFromResults(array $rows): array
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
     * Dispatches a batch whose progress the CP polls: queued now, counted by
     * Laravel's batch (its id is kept next to the progress record), failed
     * with the error if a job fails, $then on success.
     * Static callbacks only: they are serialized with the batch.
     *
     * @param  array<int, object>  $jobs
     */
    public static function dispatchTracked(string $name, array $jobs, string $batchId, \Closure $then): void
    {
        $totalJobs = count($jobs);
        Cache::put("translation:batch:{$batchId}:progress", [
            'current' => 0,
            'total' => $totalJobs,
            'current_entry' => null,
            'status' => 'queued',
        ], now()->addHours(2));

        try {
            $batch = Bus::batch($jobs)
                ->name($name)
                ->then($then)
                ->catch(static fn ($batch, \Throwable $e) => self::markBatchFailed($batchId, $e))
                ->dispatch();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                __('Failed to dispatch translation batch. Make sure a queue driver is configured.'),
                0,
                $e
            );
        }

        Cache::put("translation:batch:{$batchId}:bus", $batch->id, now()->addHours(2));
    }

    private static function markBatchFailed(string $batchId, \Throwable $e): void
    {
        Cache::put("translation:batch:{$batchId}:progress", array_merge(
            Cache::get("translation:batch:{$batchId}:progress", []),
            ['status' => 'failed', 'fatal_error' => $e->getMessage()]
        ), now()->addHours(2));
    }

    public static function finishNavigationBatch(string $batchId, int $totalJobs): void
    {
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
    }

    /**
     * Completion of a queued batch. Static: it runs from the serialized
     * then() callback, which must not carry the runner with it.
     *
     * @param  array<int, array<string, mixed>>  $reconcilePairs
     */
    public static function finishBatch(string $batchId, int $totalJobs, array $reconcilePairs): void
    {
        $entriesData = Cache::get("translation:batch:{$batchId}:entries", []);
        [$translated, $updated, $errors] = self::batchCounts($entriesData);
        $linkedCreatedTotal = array_sum(array_map(fn (array $row) => count($row['linked_entries'] ?? []), $entriesData));

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
            Log::warning('Translation batch reference reconcile failed', [
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
    }

    /**
     * [translated (new, skipped and legacy-skipped rows), updated, errors] from
     * the per-entry status rows the jobs wrote.
     *
     * @param  array<string, array<string, mixed>>  $entriesData
     * @return array{0: int, 1: int, 2: array<int, string>}
     */
    private static function batchCounts(array $entriesData): array
    {
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

        return [$translated, $updated, $errors];
    }
}
