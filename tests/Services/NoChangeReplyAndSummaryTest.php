<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryJsonParser;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * An update with nothing to change answers {} or [] (no field changes, not a
 * parse failure), and a turn whose entry job failed does not keep a summary
 * that claims success.
 */
class NoChangeReplyAndSummaryTest extends TestCase
{
    public function test_an_empty_reply_means_no_field_changes(): void
    {
        $ai = $this->createMock(AbstractAiService::class);
        $ai->expects($this->never())->method('generateFromMessages');
        $parser = new EntryJsonParser($ai);

        foreach (['{}', '[]', "```json\n{}\n```", ' [] '] as $reply) {
            $this->assertSame([], $parser->parseResponseOrRetry($reply, [], 1000), "reply: {$reply}");
        }
    }

    public function test_a_non_empty_list_is_still_rejected(): void
    {
        $ai = $this->createStub(AbstractAiService::class);
        $ai->method('generateFromMessages')->willReturn('["a","b"]');

        $this->expectException(\RuntimeException::class);
        (new EntryJsonParser($ai))->parseResponseOrRetry('["a","b"]', [], 1000);
    }

    public function test_the_summary_is_corrected_once_a_job_of_the_turn_failed(): void
    {
        Cache::flush();
        $batch = new EntryGenerationBatchService;
        $sid = $batch->initPlanningSession('default', null, 'Ordne die FAQ zu');
        $rowA = $this->appendRow($batch, $sid, 'FAQ der Gruppe zuordnen');
        $rowB = $this->appendRow($batch, $sid, 'Antwort kürzen');

        $batch->appendAssistantTurn($sid, 'Done — updated "FAQ der Gruppe zuordnen".', [$rowA, $rowB], 'summary');
        $batch->markEntrySuccess($sid, $rowB, ['data' => ['title' => 'x'], 'displayData' => [], 'warnings' => []]);
        $this->assertStringStartsWith('Done', $this->lastTurn($batch, $sid)['text'], 'not corrected while a job still runs');

        $batch->markEntryFailed($sid, $rowA, 'Something broke.');
        $turn = $this->lastTurn($batch, $sid);
        $this->assertSame('1 of 2 done. Did not work: “FAQ der Gruppe zuordnen”.', $turn['text']);
        $this->assertTrue($turn['corrected']);
    }

    public function test_a_summary_written_after_its_job_failed_is_corrected_too(): void
    {
        Cache::flush();
        $batch = new EntryGenerationBatchService;
        $sid = $batch->initPlanningSession('default', null, 'Aktualisiere die FAQ');
        $row = $this->appendRow($batch, $sid, 'FAQ aktualisieren');

        $batch->markEntryFailed($sid, $row, 'Something broke.');
        $batch->appendAssistantTurn($sid, 'Done — updated "FAQ aktualisieren".', [$row], 'summary');

        $this->assertSame('That did not work: “FAQ aktualisieren”.', $this->lastTurn($batch, $sid)['text']);
    }

    public function test_a_successful_turn_keeps_its_summary(): void
    {
        Cache::flush();
        $batch = new EntryGenerationBatchService;
        $sid = $batch->initPlanningSession('default', null, 'Erstelle eine FAQ');
        $row = $this->appendRow($batch, $sid, 'Neue FAQ');

        $batch->appendAssistantTurn($sid, 'Done — created "Neue FAQ".', [$row], 'summary');
        $batch->markEntrySuccess($sid, $row, ['data' => ['title' => 'x'], 'displayData' => [], 'warnings' => []]);

        $this->assertSame('Done — created "Neue FAQ".', $this->lastTurn($batch, $sid)['text']);
    }

    private function appendRow(EntryGenerationBatchService $batch, string $sid, string $label): string
    {
        $id = 'row-'.md5($label);
        $batch->addPlannedEntry($sid, [
            'id' => $id, 'collection' => 'pages', 'blueprint' => 'page', 'prompt' => $label, 'label' => $label,
            'collection_title' => 'Pages', 'blueprint_title' => 'Page',
        ]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function lastTurn(EntryGenerationBatchService $batch, string $sid): array
    {
        return collect($batch->snapshotForProgress($sid)['transcript'])->last();
    }
}
