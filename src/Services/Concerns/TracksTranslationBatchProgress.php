<?php

namespace MotionWork\StatamicAiAssistant\Services\Concerns;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;

/**
 * Progress of a bulk translation batch, kept in the cache for two hours: one
 * status row per entry and target locale, and a progress record. For a queued
 * batch the counter comes from Laravel's job batch (exact under parallel
 * workers); the record holds the summary once the batch has finished. An
 * inline run counts in the record itself.
 */
trait TracksTranslationBatchProgress
{
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
     * Counter for an inline run (one process, so no other writer).
     *
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
     * Parallel workers write rows into one array, so each read-modify-write
     * holds a lock; without one, concurrent writes drop each other's rows.
     *
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
        $row = array_merge([
            'status' => $status,
            'error' => $error,
            'entry_id' => $entryId,
            'destination_locale' => $destinationLocale,
            'completed_at' => now()->toDateTimeString(),
        ], $payload);

        $write = function () use ($key, $entryId, $destinationLocale, $row): void {
            $entries = Cache::get($key, []);
            $entries[$entryId."\x1e".$destinationLocale] = $row;
            Cache::put($key, $entries, now()->addHours(2));
        };

        try {
            Cache::lock($key.':lock', 10)->block(5, $write);
        } catch (LockTimeoutException) {
            // A row written without the lock may be lost; failing the job would lose the translation too.
            Log::warning('[translation] batch row lock timed out, writing without it', ['batch_id' => $batchId]);
            $write();
        }
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

        if (in_array($progress['status'] ?? null, ['queued', 'processing'], true)) {
            $progress = $this->withQueuedBatchCounter($batchId, $progress, $entries);
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

    /**
     * Jobs done (succeeded or failed) from Laravel's batch, and the page most
     * recently started. Stays "processing" at the end until the batch's
     * completion callback has written the summary.
     *
     * @param  array<string, mixed>  $progress
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    private function withQueuedBatchCounter(string $batchId, array $progress, array $entries): array
    {
        $busBatchId = Cache::get("translation:batch:{$batchId}:bus");
        $batch = is_string($busBatchId) ? Bus::findBatch($busBatchId) : null;
        if ($batch === null) {
            return $progress;
        }

        $done = min($batch->totalJobs, $batch->processedJobs() + $batch->failedJobs);
        $running = collect($entries)->where('status', 'processing')->sortBy('completed_at')->last();

        return array_merge($progress, [
            'current' => $done,
            'total' => $batch->totalJobs,
            // Any row means a job has started, also between a job's rows (navigation).
            'status' => $done > 0 || $entries !== [] ? 'processing' : 'queued',
            'current_entry' => $running ? $this->runningLabel($running) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function runningLabel(array $row): ?string
    {
        $title = $row['origin_title'] ?? null;
        if (! is_string($title) || $title === '') {
            return null;
        }

        $site = Site::all()->firstWhere('locale', $row['destination_locale'] ?? null);

        return $site ? $title.' → '.$site->name() : $title;
    }
}
