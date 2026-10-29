<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Services\SetHintsService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class ClaudeComposeEntryMcpTest extends TestCase
{
    private string $hintsPath;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->wipeEntries();

        config(['statamic.system.multisite' => true]);
        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => 'http://localhost/'],
        ]);

        $this->hintsPath = sys_get_temp_dir().'/compose-hints-'.uniqid().'.yaml';
        config(['statamic-ai-assistant.set_hints_path' => $this->hintsPath]);
        app(SetHintsService::class)->save([
            'quote' => ['ai_description' => 'Grosses Zitat einer Kundin.', 'when_to_use' => ['Social Proof nach den Argumenten']],
        ]);

        Blueprint::make('page')->setNamespace('collections.mcp_compose')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
                ['handle' => 'body', 'field' => ['type' => 'bard']],
                ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => [
                    'main' => ['sets' => [
                        'quote' => ['display' => 'Zitat', 'fields' => [
                            ['handle' => 'text', 'field' => ['type' => 'text']],
                            ['handle' => 'author', 'field' => ['type' => 'text']],
                        ]],
                        'teaser' => ['display' => 'Teaser', 'fields' => [
                            ['handle' => 'title', 'field' => ['type' => 'text']],
                            ['handle' => 'link', 'field' => ['type' => 'link']],
                        ]],
                    ]],
                ]]],
            ]]]]],
        ])->save();
        Collection::make('mcp_compose')->title('Seiten')->sites(['de'])->save();

        Role::make('mcp_composer')->permissions(['access cp', 'access de site', 'view mcp_compose entries', 'edit mcp_compose entries'])->save();
    }

    protected function tearDown(): void
    {
        $this->wipeEntries();
        foreach (['mcp-composer', 'mcp-other'] as $id) {
            User::find($id)?->delete();
        }
        Role::find('mcp_composer')?->delete();
        Collection::findByHandle('mcp_compose')?->delete();
        @unlink($this->hintsPath);
        parent::tearDown();
    }

    public function test_an_editor_composes_a_new_entry_from_the_brief(): void
    {
        $server = $this->serverAs($this->user('mcp-composer'));
        $data = [
            'title' => 'Mietkaution für Studierende',
            'body' => '<p>Deine erste <strong>Wohnung</strong>.</p>',
            'blocks' => [
                ['type' => 'quote', 'text' => 'Ging ganz schnell.', 'author' => 'Lea'],
                ['type' => 'teaser', 'title' => 'Mehr erfahren', 'link' => '#vorteile'],
            ],
        ];

        $this->assertSame([], $this->tool($server, 'get_content_model', [])['collections'], 'Without create permission nothing can be composed.');
        $this->assertSame('forbidden', $this->tool($server, 'preview_compose_entry', ['collection' => 'mcp_compose', 'data' => $data])['error']);

        Role::find('mcp_composer')->addPermission('create mcp_compose entries')->save();
        $server = $this->serverAs(User::find('mcp-composer'));

        $this->assertSame('mcp_compose', $this->tool($server, 'get_content_model', [])['collections'][0]['handle']);
        $brief = $this->tool($server, 'get_content_model', ['collection' => 'mcp_compose'])['brief'];
        $this->assertStringContainsString('Grosses Zitat einer Kundin.', $brief, 'The editors\' block hints are in the brief.');
        $this->assertStringContainsString('Social Proof nach den Argumenten', $brief);

        $invalid = $this->tool($server, 'preview_compose_entry', ['collection' => 'mcp_compose', 'data' => ['body' => '<p>Ohne Titel</p>']]);
        $this->assertSame('invalid_entry', $invalid['error']);
        $this->assertArrayHasKey('title', $invalid['problems']);

        $preview = $this->tool($server, 'preview_compose_entry', ['collection' => 'mcp_compose', 'data' => $data]);
        $this->assertSame('create', $preview['mode']);
        $this->assertSame('mietkaution-fuer-studierende', $preview['slug']);
        $blocks = collect($preview['outline'])->firstWhere('field', 'Blocks')['blocks'];
        $this->assertSame(['quote', 'teaser: Mehr erfahren'], $blocks);
        $this->assertCount(0, Entry::all(), 'A preview writes nothing.');

        $saved = $this->tool($server, 'save_compose_entry', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true]);
        $this->assertTrue($saved['saved']);
        $this->assertFalse($saved['published']);

        $entry = Entry::find($saved['entry_id']);
        $this->assertSame('mcp-composer', $entry->get('updated_by'));
        $this->assertSame('bold', $entry->get('body')[0]['content'][1]['marks'][0]['type']);
        $this->assertSame('#vorteile', $entry->get('blocks')[1]['link']);
        $this->assertSame('Lea', $entry->get('blocks')[0]['author']);
    }

    public function test_a_refactor_keeps_blocks_sent_back_by_id_exactly(): void
    {
        $page = Entry::make()->collection('mcp_compose')->blueprint('page')->locale('de')->slug('ueber-uns')->data([
            'title' => 'Über uns',
            'blocks' => [
                ['id' => 'q1', 'type' => 'quote', 'enabled' => false, 'text' => 'Top', 'author' => 'Lea', 'legacy' => ['kept' => true]],
                ['id' => 't1', 'type' => 'teaser', 'enabled' => true, 'title' => 'Alt', 'link' => 'entry::nope'],
            ],
        ]);
        $page->save();
        $before = $page->get('blocks');
        Role::find('mcp_composer')->addPermission('create mcp_compose entries')->save();
        $server = $this->serverAs($this->user('mcp-composer'));

        $model = $this->tool($server, 'get_content_model', ['entry_id' => $page->id()]);
        $this->assertStringContainsString('"id":"q1"', $model['current_content']);

        $blocks = [
            ['id' => 't1', 'type' => 'teaser', 'title' => 'Neu'],
            ['type' => 'quote', 'text' => 'Ganz neu', 'author' => 'Max'],
            ['id' => 'q1', 'type' => 'quote'],
        ];
        $this->assertSame('mode_required', $this->tool($server, 'preview_compose_entry', ['entry_id' => $page->id(), 'data' => ['blocks' => $blocks]])['error']);

        $copy = $this->tool($server, 'preview_compose_entry', ['entry_id' => $page->id(), 'mode' => 'copy', 'data' => ['title' => 'Über uns (neu)', 'blocks' => $blocks]]);
        $saved = $this->tool($server, 'save_compose_entry', ['confirmation_token' => $copy['confirmation_token'], 'confirmed' => true]);
        $this->assertFalse($saved['published']);
        $this->assertSame('ueber-uns-neu', $saved['slug']);
        $result = Entry::find($saved['entry_id'])->get('blocks');

        $this->assertSame(['teaser', 'quote', 'quote'], array_column($result, 'type'));
        $this->assertSame(array_merge($before[1], ['title' => 'Neu']), $result[0], 'Only the changed field differs; the stored link stays.');
        $this->assertSame('Max', $result[1]['author']);
        $this->assertSame($before[0], $result[2], 'A block sent back by id alone is kept exactly, disabled and all.');
        $this->assertSame($before, Entry::find($page->id())->get('blocks'), 'A copy leaves the original alone.');

        $inPlace = $this->tool($server, 'preview_compose_entry', ['entry_id' => $page->id(), 'mode' => 'in_place', 'data' => ['blocks' => [['id' => 'q1', 'type' => 'quote']]]]);
        $this->assertStringContainsString('in place', $inPlace['message']);
        Entry::find($page->id())->set('title', 'Geändert im Control Panel')->save();
        $stale = $this->tool($server, 'save_compose_entry', ['confirmation_token' => $inPlace['confirmation_token'], 'confirmed' => true]);
        $this->assertSame('entry_changed', $stale['error']);

        $inPlace = $this->tool($server, 'preview_compose_entry', ['entry_id' => $page->id(), 'mode' => 'in_place', 'data' => ['blocks' => [['id' => 'q1', 'type' => 'quote']]]]);
        $other = $this->serverAs($this->user('mcp-other'));
        $this->assertSame('preview_required', $this->tool($other, 'save_compose_entry', ['confirmation_token' => $inPlace['confirmation_token'], 'confirmed' => true])['error']);

        $this->tool($this->serverAs(User::find('mcp-composer')), 'save_compose_entry', ['confirmation_token' => $inPlace['confirmation_token'], 'confirmed' => true]);
        $this->assertSame([$before[0]], Entry::find($page->id())->get('blocks'));
        $this->assertSame('Geändert im Control Panel', Entry::find($page->id())->get('title'), 'Fields the refactor leaves out stay as they are.');
    }

    private function user(string $id): \Statamic\Contracts\Auth\User
    {
        $user = User::make()->id($id)->email($id.'@example.com')->assignRole('mcp_composer');
        $user->save();

        return $user;
    }

    private function serverAs(\Statamic\Contracts\Auth\User $user): TranslationMcpServer
    {
        $server = $this->app->make(TranslationMcpServer::class);
        $server->bridge()->setActor($user);

        return $server;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function tool(TranslationMcpServer $server, string $name, array $arguments): array
    {
        $response = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]);

        $decoded = json_decode($response['result']['content'][0]['text'], true);
        $this->assertIsArray($decoded, (string) ($response['error']['message'] ?? ''));

        return $decoded;
    }
}
