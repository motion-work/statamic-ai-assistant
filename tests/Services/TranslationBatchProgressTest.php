<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Jobs\TranslateEntryJob;
use MotionWork\StatamicAiAssistant\Services\TranslationBatchCompletion;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * A queued batch is counted by Laravel's job batch, not by the jobs writing
 * their position into the cache (parallel workers made that jump around).
 */
class TranslationBatchProgressTest extends TestCase
{
    private function dispatch(string $batchId, int $jobs): void
    {
        $list = [];
        for ($i = 0; $i < $jobs; $i++) {
            $list[] = new TranslateEntryJob("entry-{$i}", 'en', 'de', true, $batchId, 1);
        }

        TranslationBatchCompletion::dispatchTracked('Test batch', $list, $batchId, static fn () => null);
    }

    private function busBatch(string $batchId): Batch
    {
        return Bus::findBatch(Cache::get("translation:batch:{$batchId}:bus"));
    }

    public function test_queued_batch_reports_laravel_batch_counts(): void
    {
        Bus::fake();
        $this->dispatch('b1', 3);
        $service = app(TranslationService::class);

        $progress = $service->getBatchProgress('b1');
        $this->assertSame('queued', $progress['status']);
        $this->assertSame(0, $progress['current']);
        $this->assertSame(3, $progress['total']);

        // One job done, one failed: both count as done.
        $batch = $this->busBatch('b1');
        $batch->pendingJobs = 2;
        $batch->failedJobs = 1;
        $service->updateEntryStatus('b1', 'entry-2', 'de', 'processing', null, ['origin_title' => 'About us']);

        $progress = $service->getBatchProgress('b1');
        $this->assertSame('processing', $progress['status']);
        $this->assertSame(2, $progress['current']);
        $this->assertSame('About us', $progress['current_entry']);
        $this->assertSame('processing', $progress['entries']["entry-2\x1ede"]['status']);
    }

    public function test_batch_stays_processing_between_rows_of_a_running_job(): void
    {
        Bus::fake();
        $this->dispatch('b4', 1);
        $service = app(TranslationService::class);

        // A navigation job finished one page and has not written its next row yet.
        $service->updateEntryStatus('b4', 'page-1', 'it', 'completed', null, ['origin_title' => 'Demo']);

        $progress = $service->getBatchProgress('b4');
        $this->assertSame('processing', $progress['status']);
        $this->assertNull($progress['current_entry']);
    }

    public function test_summary_written_on_completion_wins_over_the_counter(): void
    {
        Bus::fake();
        $this->dispatch('b2', 2);
        $service = app(TranslationService::class);
        $service->updateEntryStatus('b2', 'entry-0', 'de', 'completed', null, ['is_new' => true]);
        $service->updateEntryStatus('b2', 'entry-1', 'de', 'completed', null, ['is_new' => false]);

        // All jobs ran, the completion callback has not written the summary yet.
        $this->busBatch('b2')->pendingJobs = 0;
        $this->assertSame('processing', $service->getBatchProgress('b2')['status']);

        TranslationBatchCompletion::finishBatch('b2', 2, []);

        $progress = $service->getBatchProgress('b2');
        $this->assertSame('completed', $progress['status']);
        $this->assertSame(2, $progress['current']);
        $this->assertSame(1, $progress['translated']);
        $this->assertSame(1, $progress['updated']);
        $this->assertCount(2, $progress['entries']);
    }

    public function test_inline_run_keeps_its_own_counter(): void
    {
        $service = app(TranslationService::class);
        Cache::put('translation:batch:b3:progress', ['current' => 1, 'total' => 4, 'status' => 'processing'], 60);

        $progress = $service->getBatchProgress('b3');
        $this->assertSame(1, $progress['current']);
        $this->assertSame(4, $progress['total']);
    }
}
