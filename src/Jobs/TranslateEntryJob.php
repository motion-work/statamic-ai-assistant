<?php

namespace BoldWeb\StatamicAiAssistant\Jobs;

use BoldWeb\StatamicAiAssistant\Support\EntryLabel;
use BoldWeb\StatamicAiAssistant\Services\TranslationService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;

class TranslateEntryJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    protected string $entryId;

    protected string $sourceLocale;

    protected string $destinationLocale;

    protected bool $overwrite;

    /** Cache key for TranslationService progress (not Laravel Bus batch id — see Batchable::$batchId). */
    protected string $translationBatchId;

    protected int $maxDepth;

    /** 1-based position in this batch (for progress UI). */
    protected int $jobIndex;

    protected int $totalJobs;

    public function __construct(
        string $entryId,
        string $sourceLocale,
        string $destinationLocale,
        bool $overwrite,
        string $translationBatchId,
        int $maxDepth,
        int $jobIndex,
        int $totalJobs,
    ) {
        $this->entryId = $entryId;
        $this->sourceLocale = $sourceLocale;
        $this->destinationLocale = $destinationLocale;
        $this->overwrite = $overwrite;
        $this->translationBatchId = $translationBatchId;
        $this->maxDepth = $maxDepth;
        $this->jobIndex = $jobIndex;
        $this->totalJobs = $totalJobs;
        $this->queue = config('statamic-ai-assistant.translation_queue', 'translations');
    }

    public function handle(TranslationService $translationService): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $entry = Entry::find($this->entryId);
        if (! $entry) {
            $this->markFailed($translationService, __('Entry not found.'));

            return;
        }

        $translationService->updateBatchProgress(
            $this->translationBatchId,
            $this->jobIndex,
            $this->totalJobs,
            $entry,
            $this->destinationLocale,
        );

        $result = $translationService->translateEntry(
            $entry,
            $this->sourceLocale,
            $this->destinationLocale,
            $this->overwrite,
            $this->maxDepth,
        );

        $payload = $translationService->entryStatusPayloadForBatch($result);

        if ($result['success']) {
            $translationService->updateEntryStatus(
                $this->translationBatchId,
                $this->entryId,
                $this->destinationLocale,
                'completed',
                null,
                $payload,
            );
        } else {
            $translationService->updateEntryStatus(
                $this->translationBatchId,
                $this->entryId,
                $this->destinationLocale,
                'failed',
                $result['error'],
                $payload,
            );
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('TranslateEntryJob failed', [
            'entry_id' => $this->entryId,
            'translation_batch_id' => $this->translationBatchId,
            'error' => $exception?->getMessage(),
        ]);

        $this->markFailed(app(TranslationService::class), $exception?->getMessage() ?? 'Unknown error');
    }

    private function markFailed(TranslationService $translationService, string $error): void
    {
        $entry = Entry::find($this->entryId);
        $translationService->updateEntryStatus(
            $this->translationBatchId,
            $this->entryId,
            $this->destinationLocale,
            'failed',
            $error,
            [
                'destination_locale' => $this->destinationLocale,
                'origin_title' => $entry ? EntryLabel::for($entry) : null,
            ],
        );
    }

}
