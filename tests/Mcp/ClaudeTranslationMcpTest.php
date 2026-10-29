<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class ClaudeTranslationMcpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->wipeEntries();

        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => 'http://localhost/'],
            'en' => ['name' => 'English', 'locale' => 'en_US', 'url' => 'http://localhost/en/'],
        ]);

        Blueprint::make('article')->setNamespace('collections.mcp_stories')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'localizable' => true]],
                ['handle' => 'intro', 'field' => ['type' => 'textarea', 'localizable' => true]],
                ['handle' => 'body', 'field' => ['type' => 'bard', 'localizable' => true]],
                ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'localizable' => true, 'sets' => [
                    'main' => ['sets' => ['columns' => ['fields' => [
                        ['handle' => 'label', 'field' => ['type' => 'text']],
                        ['handle' => 'count', 'field' => ['type' => 'integer']],
                    ]]]],
                ]]],
            ]]]]],
        ])->save();

        Collection::make('mcp_stories')->title('Stories')->sites(['de', 'en'])->save();

        $this->app->instance(DeeplService::class, new class extends DeeplService
        {
            public function translateBatch(array $texts, ?string $sourceLang, string $targetLang, array $options = []): array
            {
                throw new \RuntimeException('The site translation API must not be called.');
            }
        });
    }

    protected function tearDown(): void
    {
        $this->wipeEntries();
        Collection::findByHandle('mcp_stories')?->delete();
        parent::tearDown();
    }

    public function test_claude_saves_its_own_translation_as_a_localization_of_the_source(): void
    {
        $origin = Entry::make()
            ->collection('mcp_stories')
            ->blueprint('article')
            ->locale('de')
            ->slug('geschichte')
            ->data(['title' => 'Geschichte', 'intro' => 'Eine lange Geschichte']);
        $origin->save();
        $originId = (string) $origin->id();

        $server = $this->app->make(TranslationMcpServer::class);

        $listed = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);
        $names = array_column($listed['result']['tools'], 'name');
        $this->assertContains('preview_localization', $names);
        $this->assertContains('save_localization', $names);

        $initialized = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05'],
        ]);
        $this->assertStringContainsString('does not call DeepL or Infomaniak', $initialized['result']['instructions']);

        $preview = $this->tool($server, 'preview_localization', [
            'entry_id' => $originId,
            'target_site' => 'English',
        ]);
        $this->assertFalse($preview['saved']);
        $this->assertSame('create', $preview['action']);
        $this->assertSame($originId, $preview['root_entry_id']);
        $this->assertSame('en', $preview['target']['handle']);
        $this->assertNotSame('', $preview['confirmation_token']);
        $this->assertCount(0, Entry::query()->where('site', 'en')->get());

        $translations = [];
        foreach ($preview['segments'] as $segment) {
            $translations[$segment['id']] = match ($segment['text']) {
                'Geschichte' => 'History',
                'Eine lange Geschichte' => 'A long story',
                default => 'unexpected '.$segment['text'],
            };
        }

        $refused = $this->tool($server, 'save_localization', [
            'confirmation_token' => $preview['confirmation_token'],
            'confirmed' => false,
            'translations' => $translations,
        ]);
        $this->assertSame('not_confirmed', $refused['error']);
        $this->assertCount(0, Entry::query()->where('site', 'en')->get());

        $saved = $this->tool($server, 'save_localization', [
            'confirmation_token' => $preview['confirmation_token'],
            'confirmed' => true,
            'translations' => $translations,
        ]);

        $this->assertTrue($saved['saved']);
        $this->assertFalse($saved['site_api_used']);
        $this->assertSame($originId, $saved['origin_entry_id']);
        $this->assertSame('en', $saved['site']);
        $this->assertSame('History', $saved['title']);

        $english = Entry::query()->where('site', 'en')->get();
        $this->assertCount(1, $english);
        $localized = $english->first();
        $this->assertTrue($localized->hasOrigin());
        $this->assertSame($originId, (string) $localized->origin()->id());
        $this->assertSame('A long story', $localized->value('intro'));
        $this->assertNotSame($originId, (string) $localized->id());
        $this->assertSame('Geschichte', Entry::find($originId)->value('title'));
    }

    public function test_numbers_in_the_entry_survive_a_translation(): void
    {
        $origin = Entry::make()
            ->collection('mcp_stories')
            ->blueprint('article')
            ->locale('de')
            ->slug('zahlen')
            ->data([
                'title' => 'Zahlen',
                'intro' => 'Einleitung',
                'body' => [
                    ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Titel']]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Absatz']]],
                ],
                'blocks' => [
                    ['id' => 'b1', 'type' => 'columns', 'enabled' => true, 'label' => 'Spalten', 'count' => 1],
                ],
            ]);
        $origin->save();

        $server = $this->app->make(TranslationMcpServer::class);
        $preview = $this->tool($server, 'preview_localization', [
            'entry_id' => (string) $origin->id(),
            'target_site' => 'en',
        ]);

        $translations = [];
        foreach ($preview['segments'] as $segment) {
            $translations[$segment['id']] = 'EN '.$segment['text'];
        }

        $saved = $this->tool($server, 'save_localization', [
            'confirmation_token' => $preview['confirmation_token'],
            'confirmed' => true,
            'translations' => $translations,
        ]);
        $this->assertTrue($saved['saved']);

        $data = Entry::find($saved['entry_id'])->data()->all();
        $this->assertSame(2, $data['body'][0]['attrs']['level']);
        $this->assertSame(1, $data['blocks'][0]['count']);
        $this->assertSame('EN Spalten', $data['blocks'][0]['label']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(TranslationMcpServer $server, string $name, array $arguments): array
    {
        $response = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);

        $decoded = json_decode($response['result']['content'][0]['text'], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
