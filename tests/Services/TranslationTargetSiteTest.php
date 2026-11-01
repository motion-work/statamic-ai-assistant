<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Services\TranslationService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * A localization is only written into a site the collection is enabled for.
 * Otherwise Statamic's save fails on structured collections (no tree for
 * that site) after the file is written, leaving an entry the CP never shows.
 */
class TranslationTargetSiteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => 'http://localhost/'],
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => 'http://localhost/en/'],
            'it' => ['name' => 'Italiano', 'locale' => 'it_CH', 'url' => 'http://localhost/it/'],
        ]);

        Blueprint::make('page')->setNamespace('collections.de_en_pages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ]]]]],
        ])->save();

        Collection::make('de_en_pages')->title('Seiten')->sites(['de', 'en'])->structureContents(['root' => true])->save();
    }

    private function entry(): mixed
    {
        $entry = Entry::make()->collection('de_en_pages')->locale('de')->slug('kontakt')->data(['title' => 'Kontakt']);
        $entry->save();

        return $entry;
    }

    public function test_translation_into_a_site_the_collection_lacks_is_refused(): void
    {
        $entry = $this->entry();

        $result = app(TranslationService::class)->translateEntry($entry, 'de_CH', 'it_CH');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Seiten', (string) $result['error']);
        $this->assertFalse($entry->fresh()->existsIn('it'));
    }

    public function test_connector_refuses_the_localization_preview(): void
    {
        $entry = $this->entry();
        $server = app(TranslationMcpServer::class);

        $response = $server->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'preview_localization',
            'arguments' => ['entry_id' => (string) $entry->id(), 'target_site' => 'it'],
        ]]);

        $this->assertSame('site_not_in_collection', json_decode($response['result']['content'][0]['text'], true)['error'] ?? null);
    }
}
