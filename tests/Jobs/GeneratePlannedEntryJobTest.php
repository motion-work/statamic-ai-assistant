<?php

namespace MotionWork\StatamicAiAssistant\Tests\Jobs;

use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Services\EntryGenerationBatchService;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Services\EntryReferenceResolver;
use MotionWork\StatamicAiAssistant\Services\EntryTranslator;
use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

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
        // Taken paths ROTATE rather than drain: user-chosen imagery stays
        // available for every subsequent field and entry of the batch.
        $this->assertSame(
            [['container' => 'images', 'path' => 'a.jpg']],
            $session['url_augmentation']['preferred_paths'],
        );
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

    public function test_localize_saves_the_translation_on_the_target_site_with_the_source_as_origin(): void
    {
        $this->wipeEntries();

        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => 'http://localhost/'],
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => 'http://localhost/en/'],
        ]);

        Blueprint::make('article')->setNamespace('collections.stories')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'localizable' => true]],
                ['handle' => 'intro', 'field' => ['type' => 'textarea', 'localizable' => true]],
            ]]]]],
        ])->save();

        Collection::make('stories')->title('Stories')->sites(['de', 'en'])->save();

        $origin = Entry::make()
            ->collection('stories')
            ->blueprint('article')
            ->locale('de')
            ->slug('geschichte')
            ->data(['title' => 'Geschichte', 'intro' => 'Eine lange Geschichte']);
        $origin->save();
        $originId = (string) $origin->id();

        $sid = $this->batch->initPlanningSession(
            'de',
            null,
            'übersetze diese seite auf English',
            false,
            [
                'appendix' => '',
                'warnings' => [],
                'preferred' => new PreferredAssetPaths,
                'appended_to_prompts' => false,
            ],
            'stories',
            'article',
        );
        $this->batch->addPlannedEntry($sid, [
            'id' => 'e1',
            'collection' => 'stories',
            'blueprint' => 'article',
            'label' => 'Geschichte',
            'prompt' => 'localize',
            'collection_title' => 'Stories',
            'blueprint_title' => 'Article',
            'entry_id' => $originId,
            'operation' => 'localize',
            'target_site' => 'en',
            'target_locale' => 'en_US',
            'target_site_label' => 'English',
        ], 10);
        $this->batch->markPlanningComplete($sid);

        $deepl = new class extends DeeplService
        {
            public function translateBatch(array $texts, ?string $sourceLang, string $targetLang, array $options = []): array
            {
                return array_map(fn ($text) => 'EN '.$text, $texts);
            }
        };
        $translations = new TranslationService(new EntryTranslator($deepl, new EntryReferenceResolver));

        $generator = $this->createMock(EntryGeneratorService::class);
        $generator->expects($this->never())->method('generateContent');
        $generator->expects($this->never())->method('generateUpdateForEntry');

        (new GeneratePlannedEntryJob($sid, 'e1'))->handle($this->batch, $generator, $translations);

        $session = $this->batch->getSession($sid);
        $this->assertSame('saved', $session['entries']['e1']['status']);
        $this->assertSame('en', $session['entries']['e1']['target_site']);

        $german = Entry::query()->where('site', 'de')->get();
        $this->assertCount(1, $german);
        $this->assertSame($originId, (string) $german->first()->id());
        $this->assertSame('Geschichte', $german->first()->value('title'));

        $english = Entry::query()->where('site', 'en')->get();
        $this->assertCount(1, $english);
        $localized = $english->first();
        $this->assertSame('en', $localized->locale());
        $this->assertTrue($localized->hasOrigin());
        $this->assertSame($originId, (string) $localized->origin()->id());
        $this->assertSame('EN Geschichte', $localized->value('title'));
        $this->assertNotSame($originId, (string) $localized->id());
    }
}
