<?php

namespace MotionWork\StatamicAiAssistant\Tests\Usage;

use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Usage\UsageLog;
use MotionWork\StatamicAiAssistant\Usage\UsageReport;

/**
 * A month of usage, added up: totals, breakdowns, recent calls, retention.
 */
class UsageReportTest extends TestCase
{
    use UsageTestDirectory;

    private UsageLog $log;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->useTempUsageDirectory();
        $this->log = new UsageLog;
    }

    protected function tearDown(): void
    {
        $this->removeTempUsageDirectory();
        parent::tearDown();
    }

    private function record(array $overrides): void
    {
        $this->log->append([
            'ts' => '2026-11-03T10:00:00+00:00',
            'provider' => 'openrouter',
            'task' => 'agent',
            'model' => 'openai/gpt-6.1-sol',
            'fallback' => false,
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'cost' => 0.01,
            'duration_ms' => 2000,
            'user_id' => 'u-1',
            ...$overrides,
        ]);
    }

    public function test_a_month_is_added_up_by_task_model_user_and_day(): void
    {
        $this->record([]);
        $this->record(['task' => 'field', 'model' => 'anthropic/claude-haiku-5.5', 'cost' => 0.001, 'user_id' => 'u-2', 'ts' => '2026-11-04T09:00:00+00:00']);
        $this->record(['fallback' => true, 'cost' => 0.02, 'ts' => '2026-11-04T11:00:00+00:00']);
        $this->record(['provider' => 'infomaniak', 'cost' => null, 'ts' => '2026-11-05T08:00:00+00:00']);
        $this->record(['ts' => '2026-10-31T23:00:00+00:00']);

        $report = (new UsageReport($this->log))->forMonth('2026-11');

        $this->assertSame(4, $report['totals']['calls']);
        $this->assertSame(3, $report['totals']['priced_calls']);
        $this->assertEqualsWithDelta(0.031, $report['totals']['cost'], 1e-9);
        $this->assertSame(400, $report['totals']['prompt_tokens']);
        $this->assertEqualsWithDelta(0.25, $report['totals']['fallback_rate'], 1e-9);

        $this->assertSame(['agent', 'field'], array_column($report['by_task'], 'key'), 'Most expensive first.');
        $this->assertSame(3, $report['by_task'][0]['calls']);
        $this->assertSame(['openai/gpt-6.1-sol', 'anthropic/claude-haiku-5.5'], array_column($report['by_model'], 'key'));
        $this->assertSame(['u-1', 'u-2'], array_column($report['by_user'], 'key'));
        $this->assertSame(['2026-11-03', '2026-11-04', '2026-11-05'], array_column($report['by_day'], 'key'), 'Calendar order.');

        $this->assertSame('2026-11-05T08:00:00+00:00', $report['recent'][0]['ts'], 'Newest call first.');
        $this->assertCount(4, $report['recent']);
    }

    public function test_the_cached_report_is_rebuilt_once_a_new_call_is_logged(): void
    {
        $this->record([]);
        $reports = new UsageReport($this->log);
        $this->assertSame(1, $reports->forMonth('2026-11')['totals']['calls']);

        $this->record(['ts' => '2026-11-06T10:00:00+00:00']);

        $this->assertSame(2, $reports->forMonth('2026-11')['totals']['calls']);
    }

    public function test_an_empty_month_reports_zero_calls(): void
    {
        $report = (new UsageReport($this->log))->forMonth('2026-01');

        $this->assertSame(0, $report['totals']['calls']);
        $this->assertSame(0.0, $report['totals']['fallback_rate']);
        $this->assertSame([], $report['recent']);
    }

    public function test_logs_older_than_the_retention_window_are_pruned(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 15));
        foreach (['2025-10', '2025-11', '2025-12', '2026-11'] as $month) {
            $this->record(['ts' => $month.'-02T10:00:00+00:00']);
        }

        $removed = $this->log->prune(12);

        $this->assertSame(['2025-11', '2025-10'], $removed);
        $this->assertSame(['2026-11', '2025-12'], $this->log->months());
    }
}
