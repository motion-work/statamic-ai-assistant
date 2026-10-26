<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
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

    public function test_restore_session_from_transcript_seeds_a_resumable_session(): void
    {
        $sid = $this->batch->restoreSessionFromTranscript('default', [
            ['role' => 'user', 'text' => 'Create a page about our spa offering'],
            ['role' => 'assistant', 'text' => 'Created "Spa" in Pages.', 'kind' => 'summary'],
            // Invalid turns are dropped, not persisted.
            ['role' => 'system', 'text' => 'nope'],
            ['role' => 'user', 'text' => '   '],
            'not-an-array',
        ]);

        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertNotNull($snap);
        // Terminal state: reopenForFollowUp can pick the session up directly.
        $this->assertSame('completed', $snap['status']);
        $this->assertSame('planned', $snap['planning_status']);
        $this->assertTrue($snap['auto_resolve']);
        $this->assertSame([], $snap['entries']);

        $this->assertSame([
            ['role' => 'user', 'text' => 'Create a page about our spa offering', 'entry_ids' => [], 'kind' => null],
            ['role' => 'assistant', 'text' => 'Created "Spa" in Pages.', 'entry_ids' => [], 'kind' => 'summary'],
        ], $snap['transcript']);

        // Follow-up flow: the restored session accepts the next user turn.
        $this->assertTrue($this->batch->reopenForFollowUp($sid, 'Now add a FAQ section to it', null));
        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertSame('planning', $snap['planning_status']);
        $this->assertCount(3, $snap['transcript']);
        $this->assertSame('Now add a FAQ section to it', $snap['transcript'][2]['text']);
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

    private function initSession(string $prompt = 'Write the contact page.', ?int $cap = null): string
    {
        return $this->batch->initPlanningSession(
            'default', null, $prompt, true,
            ['appendix' => '', 'warnings' => [], 'preferred' => new PreferredAssetPaths, 'appended_to_prompts' => false],
            null, null, $cap,
        );
    }

    public function test_operations_persist_across_polls_and_reset_on_follow_up(): void
    {
        $sid = $this->initSession();

        $this->batch->appendOperation($sid, 'collection', 'new collection "candies"');
        $this->batch->appendOperation($sid, 'blueprint', 'new blueprint "candy"');
        $this->batch->appendOperation($sid, 'noop', '   '); // blank labels are ignored

        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertSame(
            ['new collection "candies"', 'new blueprint "candy"'],
            array_column($snap['operations'], 'label'),
        );
        $this->assertSame('collection', $snap['operations'][0]['kind']);

        // Unlike the drained activity feed, operations survive repeated polls…
        $again = $this->batch->snapshotForProgress($sid);
        $this->assertCount(2, $again['operations']);

        // …but a follow-up turn starts with a clean list.
        $this->batch->reopenForFollowUp($sid, 'And now add one more thing please.', null);
        $this->assertSame([], $this->batch->snapshotForProgress($sid)['operations']);
    }

    public function test_init_planning_session_seeds_transcript_with_first_user_turn(): void
    {
        $sid = $this->initSession('Which entry contains X?');

        $snap = $this->batch->snapshotForProgress($sid);
        $this->assertSame([
            ['role' => 'user', 'text' => 'Which entry contains X?', 'entry_ids' => [], 'kind' => null],
        ], $snap['transcript']);
    }

    public function test_append_assistant_turn_records_text_entry_ids_and_kind(): void
    {
        $sid = $this->initSession();

        $this->batch->appendAssistantTurn($sid, 'Done — updated “Home”.', ['e1', 'e2'], 'summary');

        $transcript = $this->batch->snapshotForProgress($sid)['transcript'];
        $this->assertCount(2, $transcript);
        $this->assertSame('assistant', $transcript[1]['role']);
        $this->assertSame('Done — updated “Home”.', $transcript[1]['text']);
        $this->assertSame(['e1', 'e2'], $transcript[1]['entry_ids']);
        $this->assertSame('summary', $transcript[1]['kind']);
    }

    public function test_reopen_for_follow_up_resets_planning_keeps_entries_and_transcript_and_appends_user_turn(): void
    {
        $sid = $this->initSession('First message.');
        $row = [
            'id' => 'e1', 'collection' => 'pages', 'blueprint' => 'page',
            'prompt' => 'p', 'label' => 'Home', 'collection_title' => 'Pages', 'blueprint_title' => 'Page',
        ];
        $this->batch->addPlannedEntry($sid, $row, 5);
        $this->batch->appendAssistantTurn($sid, 'Done.', ['e1'], 'summary');
        $this->batch->markPlanningComplete($sid);

        $this->assertTrue($this->batch->reopenForFollowUp($sid, 'Add a section to this entry.', 3));

        $session = $this->batch->getSession($sid);
        $this->assertSame('running', $session['status']);
        $this->assertSame('planning', $session['planning_status']);
        $this->assertNull($session['planner_error']);
        $this->assertNull($session['planner_answer']);
        $this->assertSame('Add a section to this entry.', $session['prompt']);
        $this->assertSame(3, $session['max_plan_entries']);
        // Prior entry survived.
        $this->assertCount(1, $session['entry_order']);
        // Transcript kept + new user turn appended.
        $this->assertCount(3, $session['transcript']);
        $this->assertSame('user', $session['transcript'][2]['role']);
        $this->assertSame('Add a section to this entry.', $session['transcript'][2]['text']);
    }

    public function test_reopen_for_follow_up_reactivates_completed_and_cancelled_sessions(): void
    {
        $sid = $this->initSession();
        $this->batch->cancelSession($sid);
        $this->assertSame('cancelled', $this->batch->getSession($sid)['status']);

        $this->assertTrue($this->batch->reopenForFollowUp($sid, 'Try again please.', null));
        $this->assertSame('running', $this->batch->getSession($sid)['status']);
        $this->assertNull($this->batch->getSession($sid)['max_plan_entries']);
    }

    public function test_reopen_for_follow_up_returns_false_for_missing_session(): void
    {
        $this->assertFalse($this->batch->reopenForFollowUp('does-not-exist', 'hi there friend', 1));
    }

    public function test_add_planned_entry_absolute_cap_allows_new_entries_after_prior_turns(): void
    {
        $sid = $this->initSession();
        $row = fn (string $id) => [
            'id' => $id, 'collection' => 'pages', 'blueprint' => 'page',
            'prompt' => 'p', 'label' => $id, 'collection_title' => 'Pages', 'blueprint_title' => 'Page',
        ];

        // Turn 1: one entry with per-turn cap 1 (absolute cap = 0 prior + 1).
        $this->assertTrue($this->batch->addPlannedEntry($sid, $row('e1'), 1));
        // A second add at the same absolute cap is rejected.
        $this->assertFalse($this->batch->addPlannedEntry($sid, $row('e2'), 1));

        // Turn 2: absolute cap = 1 prior + 1 per-turn = 2, so a new entry fits.
        $this->assertTrue($this->batch->addPlannedEntry($sid, $row('e2'), 2));
        $this->assertCount(2, $this->batch->getSession($sid)['entry_order']);
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
