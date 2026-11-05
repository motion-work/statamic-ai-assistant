<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Semantic\EmbeddingIndexer;
use MotionWork\StatamicAiAssistant\Tests\Semantic\FakesEmbeddings;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class ConnectorSemanticFindEntriesTest extends TestCase
{
    use FakesEmbeddings;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->wipeEntries();
        $this->useSemanticSearch();

        foreach (['sem_pages', 'sem_secret'] as $handle) {
            Blueprint::make('page')->setNamespace('collections.'.$handle)->setContents([
                'tabs' => ['main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                    ['handle' => 'body', 'field' => ['type' => 'textarea']],
                ]]]]],
            ])->save();
            Collection::make($handle)->title($handle)->sites([Site::default()->handle()])->save();
        }

        Role::make('sem_editor')->title('Editor')->permissions(['access cp', 'view sem_pages entries'])->save();

        $this->ids['warranty'] = $this->entry('sem_pages', 'Garantie und Reparaturen', 'Defekte Möbel reparieren wir.');
        $this->ids['returns'] = $this->entry('sem_pages', 'Rückgabe', 'Rückgabe innert 30 Tagen.');
        $this->ids['secret'] = $this->entry('sem_secret', 'Interne Reparatur-Garantie', 'Nur intern.');
    }

    protected function tearDown(): void
    {
        $this->wipeEntries();
        User::find('sem-editor')?->delete();
        Role::find('sem_editor')?->delete();
        Collection::findByHandle('sem_pages')?->delete();
        Collection::findByHandle('sem_secret')?->delete();
        $this->tearDownSemanticSearch();
        parent::tearDown();
    }

    public function test_keyword_matches_come_first_then_viewable_entries_similar_in_meaning(): void
    {
        app(EmbeddingIndexer::class)->buildSite(Site::default()->handle());

        $found = array_column($this->findEntries('Rückgabe')['entries'], 'entry_id');

        $this->assertSame($this->ids['returns'], $found[0], 'The title match stays first.');
        $this->assertContains($this->ids['warranty'], $found, 'Found by meaning.');
        $this->assertNotContains($this->ids['secret'], $found, 'Semantic matches pass the same view check.');
    }

    public function test_without_an_index_the_result_is_the_keyword_search_alone(): void
    {
        $found = array_column($this->findEntries('Rückgabe')['entries'], 'entry_id');

        $this->assertSame([$this->ids['returns']], $found);
        $this->assertSame([], $this->embeddingRequests, 'No embedding call without an index.');
    }

    /** @return array<string, mixed> */
    private function findEntries(string $query): array
    {
        $user = User::make()->id('sem-editor')->email('sem-editor@example.com')->assignRole('sem_editor');
        $user->save();

        $server = $this->app->make(TranslationMcpServer::class);
        $server->bridge()->setActor($user);
        $response = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'find_entries', 'arguments' => ['query' => $query]],
        ]);

        return json_decode($response['result']['content'][0]['text'], true);
    }

    private function entry(string $collection, string $title, string $body): string
    {
        $entry = Entry::make()->collection($collection)->locale(Site::default()->handle())->slug(str($title)->slug())->data(['title' => $title, 'body' => $body]);
        $entry->save();

        return (string) $entry->id();
    }
}
