<?php

namespace MotionWork\StatamicAiAssistant\Tests\Jobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Services\AutoDraftSaver;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * A new entry the agent generates is saved in Statamic as an unpublished
 * draft inside the queued job (config auto_save_new_entries); updates,
 * localizations, users without permission and failed saves keep the preview.
 */
class AutoDraftSaveTest extends TestCase
{
    private EntryGenerationBatchService $batch;

    /** @var array<int, string> */
    private array $createdEntries = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['statamic.editions.pro' => true, 'statamic-ai-assistant.auto_save_new_entries' => true]);
        $this->batch = new EntryGenerationBatchService;

        User::make()->id('draft-super')->email('draft-super@example.com')->makeSuper()->save();
        User::make()->id('draft-editor')->email('draft-editor@example.com')->save();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdEntries as $id) {
            Entry::find($id)?->delete();
        }
        foreach (['draft-super', 'draft-editor'] as $id) {
            User::find($id)?->delete();
        }
        parent::tearDown();
    }

    public function test_a_new_entry_is_saved_as_an_unpublished_draft(): void
    {
        $session = $this->runJob('draft-super');
        $row = $session['entries']['e1'];

        $this->assertSame('saved', $row['status']);
        $this->assertTrue($row['auto_saved']);
        $this->assertSame(['title' => 'Holzpflege im Winter', 'content' => 'Text'], $row['data'], 'the content stays for the card preview');

        $entry = Entry::find($row['saved_entry']['entry_id']);
        $this->createdEntries[] = $entry->id();
        $this->assertFalse($entry->published());
        $this->assertSame('Holzpflege im Winter', $row['saved_entry']['title']);
        $this->assertSame($entry->editUrl(), $row['saved_entry']['edit_url']);
        $this->assertSame('completed', $session['status']);

        $snapshot = $this->batch->snapshotForProgress($this->sessionId);
        $this->assertTrue($snapshot['entries'][0]['auto_saved']);
    }

    public function test_it_is_off_when_configured(): void
    {
        config(['statamic-ai-assistant.auto_save_new_entries' => false]);

        $row = $this->runJob('draft-super')['entries']['e1'];

        $this->assertSame('ready', $row['status']);
        $this->assertArrayNotHasKey('auto_saved', $row);
    }

    public function test_a_user_who_may_not_create_entries_keeps_the_preview_with_a_hint(): void
    {
        $row = $this->runJob('draft-editor')['entries']['e1'];

        $this->assertSame('ready', $row['status']);
        $this->assertStringContainsString('Not saved automatically', implode(' ', $row['warnings']));
    }

    public function test_a_failed_save_keeps_the_preview_with_a_hint(): void
    {
        Event::listen(EntrySaving::class, fn () => throw new \RuntimeException('disk full'));

        $row = $this->runJob('draft-super')['entries']['e1'];

        $this->assertSame('ready', $row['status']);
        $this->assertStringContainsString('could not be saved automatically', implode(' ', $row['warnings']));
    }

    public function test_only_new_entries_qualify(): void
    {
        $this->assertTrue(AutoDraftSaver::appliesTo(['operation' => 'create', 'collection' => 'pages']));
        $this->assertTrue(AutoDraftSaver::appliesTo(['collection' => 'pages']), 'rows without an operation are creates');
        $this->assertFalse(AutoDraftSaver::appliesTo(['operation' => 'update', 'collection' => 'pages', 'entry_id' => 'abc']));
        $this->assertFalse(AutoDraftSaver::appliesTo(['operation' => 'create', 'collection' => 'pages', 'entry_id' => 'abc']));
        $this->assertFalse(AutoDraftSaver::appliesTo(['operation' => 'localize', 'collection' => 'pages']));
    }

    public function test_an_update_keeps_the_preview(): void
    {
        $existing = Entry::make()->collection('pages')->slug('bestehend')->data(['title' => 'Bestehend']);
        $existing->save();
        $this->createdEntries[] = $existing->id();

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->expects($this->never())->method('generateContent');
        $generator->method('generateUpdateForEntry')->willReturn([
            'data' => ['title' => 'Neu'],
            'displayData' => ['title' => 'Neu'],
            'warnings' => [],
        ]);

        $row = $this->runJob('draft-super', $generator, ['operation' => 'update', 'entry_id' => $existing->id()])['entries']['e1'];

        $this->assertSame('ready', $row['status']);
        $this->assertSame('Bestehend', Entry::find($existing->id())->value('title'), 'the entry itself is untouched');
    }

    private string $sessionId = '';

    /**
     * @param  array<string, mixed>  $rowOverrides
     * @return array<string, mixed>
     */
    private function runJob(string $userId, ?EntryGeneratorService $generator = null, array $rowOverrides = []): array
    {
        $this->sessionId = $this->batch->initPlanningSession('default', null, 'p', 'pages', 'page');
        $this->batch->addPlannedEntry($this->sessionId, array_merge([
            'id' => 'e1',
            'collection' => 'pages',
            'blueprint' => 'page',
            'label' => 'Holzpflege',
            'prompt' => 'p',
            'collection_title' => 'Pages',
            'blueprint_title' => 'Page',
        ], $rowOverrides), 10);
        $this->batch->markPlanningComplete($this->sessionId);

        if ($generator === null) {
            $generator = $this->createStub(EntryGeneratorService::class);
            $generator->method('generateContent')->willReturn([
                'data' => ['title' => 'Holzpflege im Winter', 'content' => 'Text'],
                'displayData' => ['title' => 'Holzpflege im Winter', 'content' => 'Text'],
                'warnings' => [],
            ]);
        }

        $job = new GeneratePlannedEntryJob($this->sessionId, 'e1');
        $job->userId = $userId;
        $job->handle($this->batch, $generator);

        return $this->batch->getSession($this->sessionId);
    }
}
