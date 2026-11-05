<?php

namespace MotionWork\StatamicAiAssistant\Tests\Usage;

use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\User;

/**
 * Every finished AI call becomes one line in the month's usage log.
 */
class RecordAiUsageTest extends TestCase
{
    use UsageTestDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->useTempUsageDirectory();
    }

    protected function tearDown(): void
    {
        $this->removeTempUsageDirectory();
        User::find('usage-editor')?->delete();
        parent::tearDown();
    }

    private function finished(array $usage, array $context = [], ?string $model = 'anthropic/claude-haiku-5.5', AiTask $task = AiTask::Field): AiCompletionFinished
    {
        return new AiCompletionFinished(
            provider: 'openrouter',
            task: $task,
            requestedModels: ['anthropic/claude-haiku-5.5', 'openai/gpt-6-luna'],
            model: $model,
            generationId: 'gen-123',
            usage: $usage,
            streamed: false,
            durationMs: 1234,
            context: $context,
        );
    }

    public function test_a_call_with_a_reported_cost_is_logged_with_its_tokens_and_cost(): void
    {
        event($this->finished([
            'prompt_tokens' => 120,
            'completion_tokens' => 30,
            'cost' => 0.00042,
            'prompt_tokens_details' => ['cached_tokens' => 100],
            'completion_tokens_details' => ['reasoning_tokens' => 5],
        ], ['agent_session_id' => 'sess-1', 'user_id' => 'u-42', 'secret' => 'dropped']));

        $records = $this->loggedRecords();
        $this->assertCount(1, $records);
        $record = $records[0];

        $this->assertSame('openrouter', $record['provider']);
        $this->assertSame('field', $record['task']);
        $this->assertSame('anthropic/claude-haiku-5.5', $record['model']);
        $this->assertFalse($record['fallback']);
        $this->assertSame(120, $record['prompt_tokens']);
        $this->assertSame(30, $record['completion_tokens']);
        $this->assertSame(100, $record['cached_tokens']);
        $this->assertSame(5, $record['reasoning_tokens']);
        $this->assertEqualsWithDelta(0.00042, $record['cost'], 1e-9);
        $this->assertSame(1234, $record['duration_ms']);
        $this->assertSame('gen-123', $record['generation_id']);
        $this->assertSame('u-42', $record['user_id']);
        $this->assertSame('sess-1', $record['agent_session_id']);
        $this->assertArrayNotHasKey('secret', $record);
    }

    public function test_a_provider_without_cost_logs_null_cost_and_the_signed_in_user(): void
    {
        $user = User::make()->id('usage-editor')->email('editor@example.com');
        $user->save();
        $this->actingAs($user);

        event($this->finished(['prompt_tokens' => 10, 'completion_tokens' => 2], [], 'openai/gpt-6-luna'));

        $record = $this->loggedRecords()[0];
        $this->assertNull($record['cost']);
        $this->assertSame('usage-editor', $record['user_id']);
        $this->assertTrue($record['fallback'], 'The second requested model answered.');
    }

    public function test_nothing_is_logged_when_tracking_is_off(): void
    {
        config(['statamic-ai-assistant.usage_tracking' => false]);

        event($this->finished(['prompt_tokens' => 1]));

        $this->assertSame([], $this->loggedRecords());
    }

    public function test_a_log_that_cannot_be_written_never_breaks_the_ai_call(): void
    {
        // A file where the log directory should be: the append fails.
        file_put_contents($this->usageDir, 'not a directory');

        event($this->finished(['prompt_tokens' => 1]));

        $this->assertTrue(is_file($this->usageDir));
        unlink($this->usageDir);
    }
}
