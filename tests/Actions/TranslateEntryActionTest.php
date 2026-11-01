<?php

namespace MotionWork\StatamicAiAssistant\Tests\Actions;

use MotionWork\StatamicAiAssistant\Actions\TranslateEntry;
use MotionWork\StatamicAiAssistant\Services\CpTranslationBatchRunner;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * The "Translate" entry action refuses unusable requests before anything is
 * queued, and reports where the editor ends up afterwards.
 */
class TranslateEntryActionTest extends TestCase
{
    private function multisite(): void
    {
        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => 'http://localhost/'],
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => 'http://localhost/en/'],
        ]);
    }

    private function error(array $result): string
    {
        $this->assertSame('errorCallback', $result['callback'][0]);

        return $result['callback'][1];
    }

    public function test_requests_that_cannot_run_are_refused(): void
    {
        $action = new TranslateEntry;
        $this->assertStringContainsString('single site', $this->error($action->run([], ['destination_locales' => ['en_US']])));

        $this->multisite();
        $entry = Entry::make()->collection('pages')->locale('de')->slug('x');

        $this->assertSame(__('Select at least one destination language.'), $this->error($action->run([$entry], ['destination_locales' => []])));
        $this->assertSame(__('Source and destination languages cannot be the same.'), $this->error($action->run([$entry], ['destination_locales' => ['en_US', 'de_CH']])));
        $this->assertSame(__('No valid entries selected.'), $this->error($action->run(['not an entry'], ['destination_language' => 'en_US'])));
    }

    public function test_the_legacy_single_destination_is_still_read(): void
    {
        $this->multisite();
        $runner = $this->createMock(CpTranslationBatchRunner::class);
        $runner->expects($this->once())->method('run')
            ->with($this->anything(), 'de_CH', ['en_US'], true, $this->anything())
            ->willReturn(['mode' => 'async', 'total' => 1]);
        $this->app->instance(CpTranslationBatchRunner::class, $runner);

        $entry = Entry::make()->collection('pages')->locale('de')->slug('x');
        $result = (new TranslateEntry)->run([$entry], ['destination_language' => 'en_US', 'overwrite' => true]);

        $this->assertSame('successCallback', $result['callback'][0]);
        $this->assertStringContainsString('translations', $result['callback'][1]);
    }
}
