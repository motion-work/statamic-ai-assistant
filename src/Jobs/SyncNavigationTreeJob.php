<?php

namespace MotionWork\StatamicAiAssistant\Jobs;

use MotionWork\StatamicAiAssistant\Services\NavigationTreeSyncService;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One queued job = one (navigation × destination locale) sync. Streams a
 * progress row into the translation batch cache for EVERY page it has to
 * translate ('processing' while DeepL runs, 'completed' with linked entries
 * after), so the CP shows the same live per-entry feedback as entry batches —
 * plus one summary row for the navigation tree save itself.
 */
class SyncNavigationTreeJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A nav sync may translate many pages sequentially; keep this generous. */
    public int $timeout = 1800;

    public function __construct(
        protected string $navHandle,
        protected string $destinationLocale,
        protected bool $overwrite,
        protected int $maxDepth,
        protected string $translationBatchId,
        protected int $jobIndex,
        protected int $totalJobs,
    ) {
        $this->onQueue(config('statamic-ai-assistant.translation_queue', 'translations'));
    }

    public function handle(NavigationTreeSyncService $syncService, TranslationService $translationService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $result = $syncService->syncLocale(
            $this->navHandle,
            $this->destinationLocale,
            $this->overwrite,
            $this->maxDepth,
            function (string $phase, array $page) use ($translationService): void {
                $translationService->updateEntryStatus(
                    $this->translationBatchId,
                    (string) ($page['source_entry_id'] ?? 'page'),
                    $this->destinationLocale,
                    $phase === 'completed' ? 'completed' : 'processing',
                    null,
                    [
                        'origin_title' => $page['origin_title'] ?? null,
                        'target_title' => $page['target_title'] ?? ($page['origin_title'] ?? null),
                        'edit_url' => $page['edit_url'] ?? null,
                        'destination_locale' => $this->destinationLocale,
                        'is_new' => true,
                        'linked_entries' => $page['linked_entries'] ?? [],
                    ],
                );
            },
        );

        $success = (bool) ($result['success'] ?? false);

        $translationService->updateEntryStatus(
            $this->translationBatchId,
            'navigation',
            $this->destinationLocale,
            $success ? 'completed' : 'failed',
            $result['error'] ?? null,
            [
                'origin_title' => (string) __('Navigation structure'),
                'target_title' => (string) ($result['message'] ?? ($result['site_handle'] ?? $this->destinationLocale)),
                'destination_locale' => $this->destinationLocale,
                'navigation_warnings' => $result['warnings'] ?? [],
            ],
        );

        // Monotonic-enough locale-level progress for the bar (rows carry the detail).
        Cache::put("translation:batch:{$this->translationBatchId}:progress", array_merge(
            Cache::get("translation:batch:{$this->translationBatchId}:progress", []),
            ['current' => min($this->jobIndex, $this->totalJobs), 'total' => $this->totalJobs, 'status' => 'processing'],
        ), now()->addHours(2));
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('SyncNavigationTreeJob failed', [
            'navigation' => $this->navHandle,
            'destination_locale' => $this->destinationLocale,
            'translation_batch_id' => $this->translationBatchId,
            'error' => $exception?->getMessage(),
        ]);

        app(TranslationService::class)->updateEntryStatus(
            $this->translationBatchId,
            'navigation',
            $this->destinationLocale,
            'failed',
            $exception?->getMessage() ?? 'Unknown error',
            [
                'origin_title' => (string) __('Navigation structure'),
                'destination_locale' => $this->destinationLocale,
            ],
        );
    }
}
