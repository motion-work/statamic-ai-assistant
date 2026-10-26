<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Jobs;

use BoldWeb\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use BoldWeb\StatamicAiAssistant\Services\EntryGenerationBatchService;
use BoldWeb\StatamicAiAssistant\Services\EntryGeneratorService;
use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

class GeneratePlannedEntryJobTest extends TestCase
{
    private EntryGenerationBatchService $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->batch = new EntryGenerationBatchService;
    }

    public function test_handle_mutates_preferred_paths_and_marks_ready(): void
    {
        $preferred = new PreferredAssetPaths([
            ['container' => 'images', 'path' => 'a.jpg'],
        ]);

        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'p',
            false,
            [
                'appendix' => '',
                'warnings' => [],
                'preferred' => $preferred,
                'appended_to_prompts' => false,
            ],
            'pages',
            'page',
        );
        $this->batch->addPlannedEntry($sid, [
            'id' => 'e1',
            'collection' => 'pages',
            'blueprint' => 'page',
            'label' => 'A',
            'prompt' => 'p',
            'collection_title' => 'P',
            'blueprint_title' => 'Page',
        ], 10);
        $this->batch->markPlanningComplete($sid);

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->expects($this->once())
            ->method('generateContent')
            ->willReturnCallback(function (
                string $collectionHandle,
                string $blueprintHandle,
                string $prompt,
                string $locale,
                ?string $attachmentContent,
                ?callable $onStreamToken,
                ?PreferredAssetPaths $preferredAssets,
                ?array $prefetchedUrlAug,
                ?callable $streamHeartbeat,
            ) {
                $this->assertIsArray($prefetchedUrlAug);
                $this->assertArrayHasKey('preferred', $prefetchedUrlAug);
                $prefetchedUrlAug['preferred']->takeForContainer('images', 99);

                return [
                    'data' => ['title' => 'T'],
                    'displayData' => ['title' => 'T'],
                    'warnings' => [],
                ];
            });

        $job = new GeneratePlannedEntryJob($sid, 'e1');
        $job->handle($this->batch, $generator);

        $session = $this->batch->getSession($sid);
        $this->assertSame('ready', $session['entries']['e1']['status']);
        $this->assertSame('completed', $session['status']);
        $this->assertSame([], $session['url_augmentation']['preferred_paths']);
    }

    public function test_handle_marks_failed_when_cancelled(): void
    {
        $sid = $this->batch->initPlanningSession(
            'default',
            null,
            'p',
            false,
            [
                'appendix' => '',
                'warnings' => [],
                'preferred' => new PreferredAssetPaths,
                'appended_to_prompts' => false,
            ],
            'pages',
            'page',
        );
        $this->batch->addPlannedEntry($sid, [
            'id' => 'e1',
            'collection' => 'pages',
            'blueprint' => 'page',
            'label' => 'A',
            'prompt' => 'p',
            'collection_title' => 'P',
            'blueprint_title' => 'Page',
        ], 10);

        $this->batch->cancelSession($sid);

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->expects($this->never())->method('generateContent');

        $job = new GeneratePlannedEntryJob($sid, 'e1');
        $job->handle($this->batch, $generator);

        $session = $this->batch->getSession($sid);
        $this->assertSame('failed', $session['entries']['e1']['status']);
    }
}
