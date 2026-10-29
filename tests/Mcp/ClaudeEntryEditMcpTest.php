<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class ClaudeEntryEditMcpTest extends TestCase
{
    private const BOLD_PARAGRAPH = [
        'type' => 'paragraph',
        'content' => [
            ['type' => 'text', 'text' => 'Fett', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' und normal'],
        ],
    ];

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

        Blueprint::make('page')->setNamespace('collections.mcp_pages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'localizable' => true]],
                ['handle' => 'kicker', 'field' => ['type' => 'text', 'localizable' => false]],
                ['handle' => 'body', 'field' => ['type' => 'bard', 'localizable' => true]],
                ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'localizable' => true, 'sets' => [
                    'main' => ['sets' => ['columns' => ['fields' => [
                        ['handle' => 'label', 'field' => ['type' => 'text']],
                        ['handle' => 'count', 'field' => ['type' => 'integer']],
                    ]]]],
                ]]],
            ]]]]],
        ])->save();

        Collection::make('mcp_pages')->title('Pages')->sites(['de', 'en'])->save();
        Collection::make('mcp_secret')->title('Secret')->sites(['de'])->save();

        Role::make('mcp_editor')->title('Editor')->permissions([
            'access cp',
            'access de site',
            'edit mcp_pages entries',
        ])->save();
    }

    protected function tearDown(): void
    {
        $this->wipeEntries();
        foreach (['mcp-editor', 'mcp-other'] as $id) {
            User::find($id)?->delete();
        }
        Role::find('mcp_editor')?->delete();
        Collection::findByHandle('mcp_pages')?->delete();
        Collection::findByHandle('mcp_secret')?->delete();
        parent::tearDown();
    }

    public function test_an_editor_changes_one_text_and_everything_else_stays_as_it_was(): void
    {
        $page = $this->page();
        $before = $page->data()->all();
        $server = $this->serverAs($this->user('mcp-editor'));

        $listed = $this->tool($server, 'preview_entry_edit', ['entry_id' => $page->id()]);
        $this->assertFalse($listed['saved']);
        $texts = array_column($listed['segments'], 'text', 'id');
        $this->assertContains('Hinweis', $texts, 'Fields marked localizable: false can be edited on the entry itself.');

        $edits = [
            array_search('Über uns', $texts, true) => 'Über Homie',
            array_search('Absatz', $texts, true) => 'Neuer Absatz',
        ];
        $preview = $this->tool($server, 'preview_entry_edit', ['entry_id' => $page->id(), 'edits' => $edits]);
        $this->assertSame(['Über uns', 'Absatz'], array_column($preview['changes'], 'before'));
        $this->assertSame('Über uns', Entry::find($page->id())->value('title'), 'A preview writes nothing.');

        $refused = $this->tool($server, 'save_entry_edit', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => false]);
        $this->assertSame('not_confirmed', $refused['error']);

        $saved = $this->tool($server, 'save_entry_edit', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true]);
        $this->assertTrue($saved['saved']);
        $this->assertSame(2, $saved['changed_segments']);

        $after = Entry::find($page->id());
        $this->assertSame('Über Homie', $after->value('title'));
        $this->assertSame('ueber-uns', $after->slug());
        $this->assertSame('mcp-editor', $after->get('updated_by'));

        $data = $after->data()->all();
        $this->assertSame('Neuer Absatz', $data['body'][1]['content'][0]['text']);
        $this->assertSame($before['body'][0], $data['body'][0], 'The heading keeps its level and text.');
        $this->assertSame(self::BOLD_PARAGRAPH, $data['body'][2], 'An untouched formatted paragraph keeps its exact nodes.');
        $this->assertSame($before['blocks'], $data['blocks']);
        $this->assertSame('Hinweis', $data['kicker']);

        $reused = $this->tool($server, 'save_entry_edit', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true]);
        $this->assertSame('preview_required', $reused['error']);
    }

    public function test_the_role_decides_which_entries_can_be_edited(): void
    {
        $server = $this->serverAs($this->user('mcp-editor'));

        $secret = Entry::make()->collection('mcp_secret')->locale('de')->slug('geheim')->data(['title' => 'Geheim']);
        $secret->save();
        $this->assertSame('forbidden', $this->tool($server, 'preview_entry_edit', ['entry_id' => $secret->id()])['error']);

        $page = $this->page();
        $english = Entry::make()->collection('mcp_pages')->blueprint('page')->locale('en')->origin($page->id())
            ->slug('about-us')->data(['title' => 'About us']);
        $english->save();
        $denied = $this->tool($server, 'preview_entry_edit', ['entry_id' => $english->id(), 'edits' => ['0' => 'About Homie']]);
        $this->assertSame('forbidden', $denied['error'], 'The role has no access to the en site.');
        $this->assertSame('About us', Entry::find($english->id())->value('title'));

        $anonymous = $this->app->make(TranslationMcpServer::class);
        $anonymous->bridge()->setActor(null);
        $this->assertSame('not_signed_in', $this->tool($anonymous, 'preview_entry_edit', ['entry_id' => $page->id()])['error']);
    }

    public function test_a_stale_preview_or_another_users_token_saves_nothing(): void
    {
        $page = $this->page();
        $editor = $this->serverAs($this->user('mcp-editor'));
        $preview = $this->tool($editor, 'preview_entry_edit', ['entry_id' => $page->id(), 'edits' => ['0' => 'Über Homie']]);

        $other = $this->serverAs($this->user('mcp-other'));
        $stolen = $this->tool($other, 'save_entry_edit', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true]);
        $this->assertSame('preview_required', $stolen['error']);

        Entry::find($page->id())->set('kicker', 'Geändert im Control Panel')->save();
        $editor = $this->serverAs(User::find('mcp-editor'));
        $stale = $this->tool($editor, 'save_entry_edit', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true]);
        $this->assertSame('entry_changed', $stale['error']);
        $this->assertSame('Über uns', Entry::find($page->id())->value('title'));
    }

    public function test_translating_follows_the_role_too(): void
    {
        $page = $this->page();
        $secret = Entry::make()->collection('mcp_secret')->locale('de')->slug('geheim')->data(['title' => 'Geheim']);
        $secret->save();
        $editor = $this->serverAs($this->user('mcp-editor'));

        $found = array_column($this->tool($editor, 'find_entries', ['query' => ''])['entries'], 'entry_id');
        $this->assertContains($page->id(), $found);
        $this->assertNotContains($secret->id(), $found, 'Entries the role cannot view are not listed.');
        $this->assertSame('forbidden', $this->tool($editor, 'read_entry', ['entry_id' => $secret->id()])['error']);

        $denied = $this->tool($editor, 'preview_localization', ['entry_id' => $page->id(), 'target_site' => 'en']);
        $this->assertSame('forbidden', $denied['error'], 'The role has no access to the en site.');

        Role::find('mcp_editor')->addPermission('access en site')->save();
        $editor = $this->serverAs(User::find('mcp-editor'));
        $preview = $this->tool($editor, 'preview_localization', ['entry_id' => $page->id(), 'target_site' => 'en']);
        $this->assertNotSame('', $preview['confirmation_token']);

        $translations = array_map(fn ($segment) => 'EN '.$segment['text'], array_column($preview['segments'], null, 'id'));
        $other = $this->serverAs($this->user('mcp-other'));
        $stolen = $this->tool($other, 'save_localization', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true, 'translations' => $translations]);
        $this->assertSame('preview_required', $stolen['error']);

        $saved = $this->tool($editor, 'save_localization', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true, 'translations' => $translations]);
        $this->assertTrue($saved['saved']);
        $this->assertSame('EN Über uns', $saved['title']);
    }

    public function test_an_editor_duplicates_an_entry_like_the_control_panel(): void
    {
        $page = $this->page();
        $english = Entry::make()->collection('mcp_pages')->blueprint('page')->locale('en')->origin($page->id())
            ->slug('about-us')->data(['title' => 'About us']);
        $english->save();
        $editor = $this->serverAs($this->user('mcp-editor'));

        $this->assertSame('forbidden', $this->tool($editor, 'duplicate_entry', ['entry_id' => $page->id(), 'confirmed' => true])['error']);

        Role::find('mcp_editor')->addPermission('create mcp_pages entries')->addPermission('access en site')->save();
        $editor = $this->serverAs(User::find('mcp-editor'));

        $preview = $this->tool($editor, 'duplicate_entry', ['entry_id' => $english->id()]);
        $this->assertFalse($preview['saved']);
        $this->assertSame((string) $page->id(), $preview['entry']['entry_id'], 'A localization is duplicated from its source entry.');
        $this->assertCount(2, Entry::all());

        $copy = $this->tool($editor, 'duplicate_entry', ['entry_id' => $page->id(), 'confirmed' => true]);
        $this->assertTrue($copy['saved']);
        $this->assertNotSame((string) $page->id(), $copy['entry_id']);
        $this->assertSame((string) $page->id(), $copy['duplicated_from']);
        $this->assertSame('Über uns (Duplicated)', $copy['title']);
        $this->assertSame('ueber-uns-1', $copy['slug']);
        $this->assertFalse($copy['published']);
        $this->assertCount(1, $copy['localizations']);
        $this->assertSame('en', $copy['localizations'][0]['site']);

        $saved = Entry::find($copy['entry_id']);
        $this->assertSame($page->value('body'), $saved->value('body'));
        $this->assertSame($page->value('blocks'), $saved->value('blocks'));
        $this->assertSame('Über uns', Entry::find($page->id())->value('title'), 'The original is untouched.');
    }

    public function test_publishing_follows_the_role(): void
    {
        $page = $this->page();
        $page->published(false)->save();
        $editor = $this->serverAs($this->user('mcp-editor'));

        $this->assertSame('forbidden', $this->tool($editor, 'set_published', ['entry_id' => $page->id(), 'published' => true, 'confirmed' => true])['error']);

        Role::find('mcp_editor')->addPermission('publish mcp_pages entries')->save();
        $editor = $this->serverAs(User::find('mcp-editor'));

        $preview = $this->tool($editor, 'set_published', ['entry_id' => $page->id(), 'published' => true]);
        $this->assertFalse($preview['saved']);
        $this->assertFalse(Entry::find($page->id())->published(), 'Without confirmed true nothing changes.');

        $published = $this->tool($editor, 'set_published', ['entry_id' => $page->id(), 'published' => true, 'confirmed' => true]);
        $this->assertTrue($published['saved']);
        $this->assertTrue(Entry::find($page->id())->published());
        $this->assertSame('mcp-editor', Entry::find($page->id())->get('updated_by'));

        $already = $this->tool($editor, 'set_published', ['entry_id' => $page->id(), 'published' => true, 'confirmed' => true]);
        $this->assertFalse($already['saved']);

        $this->tool($editor, 'set_published', ['entry_id' => $page->id(), 'published' => false, 'confirmed' => true]);
        $this->assertFalse(Entry::find($page->id())->published());
    }

    private function page(): \Statamic\Contracts\Entries\Entry
    {
        $page = Entry::make()
            ->collection('mcp_pages')
            ->blueprint('page')
            ->locale('de')
            ->slug('ueber-uns')
            ->data([
                'title' => 'Über uns',
                'kicker' => 'Hinweis',
                'body' => [
                    ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Titel']]],
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Absatz']]],
                    self::BOLD_PARAGRAPH,
                ],
                'blocks' => [
                    ['id' => 'b1', 'type' => 'columns', 'enabled' => true, 'label' => 'Spalten', 'count' => 1],
                ],
            ]);
        $page->save();

        return $page;
    }

    private function user(string $id): \Statamic\Contracts\Auth\User
    {
        $user = User::make()->id($id)->email($id.'@example.com')->assignRole('mcp_editor');
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
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
