<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\EntryGenerationBatchService;
use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class EntryGenerationBatchServiceTest extends TestCase
{
    private EntryGenerationBatchService $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->batch = new EntryGenerationBatchService;
    }

    public function test_init_planning_session_creates_running_session_with_no_entries(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'Create one entry per article on https://example.com/news',
            true,
            [
                'appendix' => '',
                'warnings' => [],
                'preferred' => new PreferredAssetPaths,
                'appended_to_prompts' => false,
            ],
        );

        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertNotNull($snap);
        $this->assertSame($sid, $snap['session_id']);
        $this->assertSame('running', $snap['status']);
        $this->assertSame('planning', $snap['planning_status']);
        $this->assertNull($snap['planner_error']);
        $this->assertTrue($snap['auto_resolve']);
        $this->assertSame('Create one entry per article on https://example.com/news', $snap['prompt']);
        $this->assertSame([], $snap['entries']);
        $this->assertSame([], $snap['warnings']);
    }

    public function test_add_planned_entry_appends_and_respects_cap_and_duplicates(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default', null, 'p', true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        $row = fn (string $id, string $label = 'A') => [
            'id' => $id,
            'collection' => 'pages',
            'blueprint' => 'page',
            'prompt' => "Brief for {$id}",
            'label' => $label,
            'collection_title' => 'Pages',
            'blueprint_title' => 'Page',
        ];

        $this->assertTrue($this->batch->addPlannedEntry($sid, $row('e1'), 2));
        $this->assertTrue($this->batch->addPlannedEntry($sid, $row('e2'), 2));
        // Cap reached.
        $this->assertFalse($this->batch->addPlannedEntry($sid, $row('e3'), 2));
        // Duplicate id is rejected even when below the cap.
        $this->assertFalse($this->batch->addPlannedEntry($sid, $row('e1', 'Duplicate'), 10));

        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertCount(2, $snap['entries']);
        $this->assertSame(['e1', 'e2'], array_column($snap['entries'], 'id'));
        $this->assertSame(0, $snap['entries'][0]['index']);
        $this->assertSame(1, $snap['entries'][1]['index']);
        $this->assertSame('Brief for e1', $snap['entries'][0]['prompt']);
    }

    public function test_init_planning_session_persists_resolved_entry_cap(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default', null, 'p', true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
            null,
            null,
            1,
        );

        $this->assertSame(1, $this->batch->getSession($sid)['max_plan_entries']);
    }

    public function test_init_planning_session_defaults_entry_cap_to_null(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default', null, 'p', true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        $this->assertNull($this->batch->getSession($sid)['max_plan_entries']);
    }

    public function test_add_planned_entry_rejected_after_cancel(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default', null, 'p', true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        $this->batch->cancelSession($sid);

        $added = $this->batch->addPlannedEntry($sid, [
            'id' => 'e1',
            'collection' => 'pages',
            'blueprint' => 'page',
            'prompt' => 'p',
            'label' => 'A',
            'collection_title' => 'Pages',
            'blueprint_title' => 'Page',
        ], 100);

        $this->assertFalse($added);
        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertSame([], $snap['entries']);
    }

    public function test_planning_status_gates_completion(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default', null, 'p', true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        // Before any entry is added, the session must NOT auto-complete just because
        // there is nothing pending — the planner is still going.
        $this->batch->markCompletedIfDone($sid);
        $this->assertSame('running', $this->batch->getSession($sid)['status']);

        $this->batch->addPlannedEntry($sid, [
            'id' => 'e1',
            'collection' => 'pages',
            'blueprint' => 'page',
            'prompt' => 'p',
            'label' => 'A',
            'collection_title' => 'Pages',
            'blueprint_title' => 'Page',
        ], 10);

        $this->batch->markEntrySuccess($sid, 'e1', [
            'data' => ['title' => 'OK'],
            'displayData' => ['title' => 'OK'],
            'warnings' => [],
        ]);

        // Entry is ready, planner is still planning → still running.
        $this->assertSame('running', $this->batch->getSession($sid)['status']);

        $this->batch->markPlanningComplete($sid);

        $this->assertSame('completed', $this->batch->getSession($sid)['status']);
    }

    public function test_mark_planning_failed_records_error_and_exposes_in_snapshot(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default', null, 'p', true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        $this->batch->markPlanningFailed($sid, 'Cannot proceed: ambiguous request.');

        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertSame('planning_failed', $snap['planning_status']);
        $this->assertSame('Cannot proceed: ambiguous request.', $snap['planner_error']);
        // No entries → completion gate flips immediately once planning is no longer 'planning'.
        $this->assertSame('completed', $this->batch->getSession($sid)['status']);
    }

    public function test_append_planner_warning_collects_unique_lines(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default', null, 'p', true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        $this->batch->appendPlannerWarning($sid, 'fetch limit reached');
        $this->batch->appendPlannerWarning($sid, '');
        $this->batch->appendPlannerWarning($sid, 'truncated content');

        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertSame(['fetch limit reached', 'truncated content'], $snap['warnings']);
    }

    public function test_cancel_session(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default', null, 'p', true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
        );

        $this->batch->cancelSession($sid);
        $this->assertTrue($this->batch->isCancelled($sid));
    }

    public function test_full_entry_lifecycle_with_streaming_delta(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default', null, 'p', true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths(['container' => 'images', 'path' => 'hero.jpg']), 'appended_to_prompts' => false],
        );

        $this->batch->addPlannedEntry($sid, [
            'id' => 'entry-uuid-1',
            'collection' => 'pages',
            'blueprint' => 'page',
            'prompt' => 'Write the home page.',
            'label' => 'One',
            'collection_title' => 'Pages',
            'blueprint_title' => 'Page',
        ], 10);

        $this->batch->markEntryGenerating($sid, 'entry-uuid-1');
        $this->batch->recordStreamDelta($sid, 'entry-uuid-1', '{"title":');

        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertSame('generating', $snap['entries'][0]['status']);
        $this->assertSame('{"title":', $snap['entries'][0]['stream_delta']);

        // Buffer must be flushed after a snapshot read.
        $snap2 = $this->batch->snapshotForProgress($sid);
        $this->assertSame('', $snap2['entries'][0]['stream_delta']);

        $this->batch->markEntrySuccess($sid, 'entry-uuid-1', [
            'data' => ['title' => 'OK'],
            'displayData' => ['title' => 'OK'],
            'warnings' => [],
        ]);
        $this->batch->markPlanningComplete($sid);

        $session = $this->batch->getSession($sid);
        $this->assertSame('completed', $session['status']);
        $this->assertSame('ready', $session['entries']['entry-uuid-1']['status']);
    }
}
