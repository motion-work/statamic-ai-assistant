<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\EntryGenerationBatchService;
use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class EntryGenerationBatchActivityTest extends TestCase
{
    private EntryGenerationBatchService $batch;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->batch = new EntryGenerationBatchService;
        $this->sessionId = $this->batch->initPlanningSession('default', null, 'Create some entries', true, [
            'appendix' => '',
            'warnings' => [],
            'preferred' => new PreferredAssetPaths,
            'appended_to_prompts' => false,
        ]);
    }

    public function test_activity_lines_are_returned_once_then_drained(): void
    {
        $this->batch->appendPlannerActivity($this->sessionId, 'Reading example.com');
        $this->batch->appendPlannerActivity($this->sessionId, 'Reading layout of Tea Time');
        $this->batch->appendPlannerActivity($this->sessionId, '   ');  // blank ignored

        $first = $this->batch->snapshotForProgress($this->sessionId);
        $this->assertSame(
            ['Reading example.com', 'Reading layout of Tea Time'],
            $first['planner_activity'],
        );

        // Drained — a second poll returns nothing new.
        $second = $this->batch->snapshotForProgress($this->sessionId);
        $this->assertSame([], $second['planner_activity']);
    }
}
