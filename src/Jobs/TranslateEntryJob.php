<?php

namespace MotionWork\StatamicAiAssistant\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use MotionWork\StatamicAiAssistant\Support\EntryLabel;
use MotionWork\StatamicAiAssistant\Usage\UsageAttribution;
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

    /**
     * CP user who started the translation, captured at dispatch, so calls of
     * the LLM fallback are attributed in the AI usage log. Null on jobs queued
     * before the addon recorded it.
     */
    public ?string $userId = null;

    public function __construct(
        string $entryId,
        string $sourceLocale,
        string $destinationLocale,
        bool $overwrite,
        string $translationBatchId,
        int $maxDepth,
    ) {
        $this->entryId = $entryId;
        $this->sourceLocale = $sourceLocale;
        $this->destinationLocale = $destinationLocale;
        $this->overwrite = $overwrite;
        $this->translationBatchId = $translationBatchId;
        $this->maxDepth = $maxDepth;
        $this->userId = UsageAttribution::currentUserId();
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

        // Laravel's batch counts finished jobs; this row names the page in progress.
        $translationService->updateEntryStatus(
            $this->translationBatchId,
            $this->entryId,
            $this->destinationLocale,
            'processing',
            null,
            ['origin_title' => EntryLabel::for($entry)],
        );

        $result = UsageAttribution::run(
            $this->userId,
            ['translation_batch_id' => $this->translationBatchId],
            fn () => $translationService->translateEntry(
                $entry,
                $this->sourceLocale,
                $this->destinationLocale,
                $this->overwrite,
                $this->maxDepth,
            ),
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
