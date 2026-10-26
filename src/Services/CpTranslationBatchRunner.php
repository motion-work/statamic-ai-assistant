<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use BoldWeb\StatamicAiAssistant\Jobs\TranslateEntryJob;
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

        $mode = config('statamic-ai-assistant.translation_mode', 'auto');
        $threshold = config('statamic-ai-assistant.translation_sync_threshold', 3);
        $queueDriver = config('queue.default', 'sync');
        $canAsync = $queueDriver !== 'sync' && $this->jobBatchesTableExists();
        $useAsync = $canAsync && ($mode === 'async' || ($mode === 'auto' && $jobCount > $threshold));

        if ($useAsync) {
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

        return array_merge($result, [
            'mode' => 'sync',
            'batch_id' => $batchId,
        ]);
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

        try {
            Bus::batch($jobs)
                ->name("Translation batch: {$batchId}")
                ->then(function () use ($batchId, $totalJobs) {
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
