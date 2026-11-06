<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\PlannerTranscript;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * A proposed plan becomes a 'plan' turn; the agent's go-ahead label travels
 * with it so the CP button says what will happen.
 */
class PlanTurnTest extends TestCase
{
    private EntryGenerationBatchService $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->batch = new EntryGenerationBatchService;
    }

    public function test_the_plan_turn_carries_the_agents_approve_label(): void
    {
        $sid = $this->batch->initPlanningSession('default', null, 'Ordne die FAQ zu');

        (new PlannerTranscript($this->batch))->completePlannerPlan(
            $sid,
            ['Ordne «Darf ich bar bezahlen?» der Gruppe «Gut zu wissen» zu.'],
            'Meinst du die zuvor erstellte FAQ?',
            0,
            'Ja, diese FAQ zuordnen',
        );

        $turn = collect($this->batch->snapshotForProgress($sid)['transcript'])->last();
        $this->assertSame('plan', $turn['kind']);
        $this->assertSame('Ja, diese FAQ zuordnen', $turn['approve_label']);
        $this->assertStringContainsString('Meinst du die zuvor erstellte FAQ?', $turn['text']);
    }

    public function test_the_applied_list_skips_changes_the_reply_already_names(): void
    {
        $transcript = new PlannerTranscript($this->batch);
        $move = '«Darf ich auch bar bezahlen?» unter «Gut zu wissen (Startseite)» verschoben.';

        $told = 'Der FAQ-Eintrag «Darf ich auch bar bezahlen?» wurde in die Gruppe «Gut zu wissen (Startseite)» verschoben.';
        $this->assertSame($told, $transcript->withAppliedList($told, [$move]));

        $terse = 'Erledigt.';
        $this->assertSame(
            "Erledigt.\nApplied: «Darf ich auch bar bezahlen?» unter «Gut zu wissen (Startseite)» verschoben.",
            $transcript->withAppliedList($terse, [$move]),
            'one period, no double ".."',
        );
    }

    public function test_a_plan_without_a_label_has_none(): void
    {
        $sid = $this->batch->initPlanningSession('default', null, 'Erstelle drei Seiten');

        (new PlannerTranscript($this->batch))->completePlannerPlan($sid, ['Erstelle drei Seiten.'], '', 0);

        $turn = collect($this->batch->snapshotForProgress($sid)['transcript'])->last();
        $this->assertSame('plan', $turn['kind']);
        $this->assertArrayNotHasKey('approve_label', $turn);
    }
}
