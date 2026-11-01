<?php

namespace MotionWork\StatamicAiAssistant\Tests\Http;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use MotionWork\StatamicAiAssistant\Jobs\PlanEntriesJob;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\User;

/**
 * The AI agent's request endpoint streams NDJSON: it starts a planning
 * session and queues the planner, or explains why it cannot.
 */
class EntryGeneratorStreamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::make()->id('stream-super')->email('stream-super@example.com')->makeSuper());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lines(array $payload, int $status = 200): array
    {
        $response = $this->post('/cp/ai-generate/generate-stream', $payload)->assertStatus($status);

        return array_map(fn ($line) => json_decode($line, true), array_values(array_filter(explode("\n", $response->streamedContent()))));
    }

    public function test_a_collection_is_required_unless_the_target_is_resolved(): void
    {
        $this->assertSame([['type' => 'error', 'message' => __('Please select a collection.')]], $this->lines(['prompt' => 'Write a page'], 422));
    }

    public function test_the_sync_queue_is_refused(): void
    {
        config(['queue.default' => 'sync']);

        $lines = $this->lines(['prompt' => 'Write a page', 'auto_resolve_target' => true]);

        $this->assertSame('error', $lines[0]['type']);
        $this->assertStringContainsString('asynchronous queue', $lines[0]['message']);
    }

    public function test_a_planning_session_is_started_and_queued(): void
    {
        config(['queue.default' => 'database', 'queue.failed.driver' => null, 'queue.batching.database' => config('database.default')]);
        Schema::create('job_batches', fn ($table) => $table->string('id'));
        Bus::fake();

        $lines = $this->lines(['prompt' => 'Write a page', 'auto_resolve_target' => true]);

        $this->assertSame(['planning', 'batch', 'done'], array_column($lines, 'type'), json_encode($lines));
        $session = app(EntryGenerationBatchService::class)->getSession($lines[1]['session_id']);
        $this->assertTrue($session['auto_resolve']);
        $this->assertSame('Write a page', $session['prompt']);
        Bus::assertDispatched(PlanEntriesJob::class);
    }
}
