<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class ClaudeImageMcpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['statamic.editions.pro' => true, 'app.url' => 'https://cms.example']);

        config(['filesystems.disks.test_assets' => [
            'driver' => 'local',
            'root' => Storage::fake('test_assets')->path(''),
        ]]);
        AssetContainer::make('assets')->disk('test_assets')->title('Assets')->save();
        Storage::disk('test_assets')->put('hero/alt.jpg', 'x');
        Storage::disk('test_assets')->put('team/anna.jpg', 'x');

        Blueprint::make('page')->setNamespace('collections.mcp_media')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'hero_image', 'field' => ['type' => 'assets', 'display' => 'Hero-Bild', 'container' => 'assets', 'folder' => 'hero', 'max_files' => 1]],
                ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'display' => 'Komponenten', 'sets' => [
                    'main' => ['sets' => [
                        'text_image' => ['fields' => [
                            ['handle' => 'title', 'field' => ['type' => 'text']],
                            ['handle' => 'image', 'field' => ['type' => 'assets', 'display' => 'Bild', 'container' => 'assets', 'max_files' => 1]],
                        ]],
                        'team' => ['fields' => [
                            ['handle' => 'photos', 'field' => ['type' => 'assets', 'display' => 'Fotos', 'container' => 'assets', 'folder' => 'team', 'restrict' => true]],
                        ]],
                    ]],
                ]]],
            ]]]]],
        ])->save();
        Collection::make('mcp_media')->title('Pages')->save();

        Role::make('mcp_image_editor')->title('Editor')->permissions([
            'access cp',
            'edit mcp_media entries',
            'upload assets assets',
            'view assets assets',
        ])->save();
        Role::make('mcp_text_editor')->title('Text editor')->permissions([
            'access cp',
            'edit mcp_media entries',
        ])->save();
    }

    protected function tearDown(): void
    {
        Entry::query()->where('collection', 'mcp_media')->get()->each->delete();
        foreach (['mcp-image', 'mcp-text'] as $id) {
            User::find($id)?->delete();
        }
        Role::find('mcp_image_editor')?->delete();
        Role::find('mcp_text_editor')?->delete();
        Collection::findByHandle('mcp_media')?->delete();
        AssetContainer::findByHandle('assets')?->delete();
        parent::tearDown();
    }

    public function test_an_editor_replaces_the_hero_image_with_an_uploaded_one(): void
    {
        $page = $this->page();
        $editor = $this->serverAs($this->user('mcp-image', 'mcp_image_editor'));

        $hero = $this->segment($editor, $page->id(), 'hero_image');
        $this->assertSame('asset', $hero['kind']);
        $this->assertSame('hero/alt.jpg', $hero['value']);
        $this->assertSame('assets', $hero['container']);
        $this->assertSame('hero', $hero['folder']);
        $this->assertFalse($hero['multiple']);

        $prepared = $this->tool($editor, 'prepare_image_upload', ['container' => 'assets', 'folder' => 'hero', 'filename' => 'Neues Hero', 'alt' => 'Team vor dem Haus']);
        $this->assertStringStartsWith('https://cms.example/mcp/upload/', $prepared['upload_url']);
        $this->assertFalse($this->tool($editor, 'check_image_upload', ['upload_id' => $prepared['upload_id']])['uploaded']);

        $this->get('/mcp/upload/'.$prepared['upload_id'])->assertOk()->assertSee('Upload an image for Claude');
        $this->post('/mcp/upload/'.$prepared['upload_id'], ['file' => UploadedFile::fake()->image('IMG_0042.JPG', 40, 30)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('path', 'hero/neues-hero.jpg');

        $checked = $this->tool($editor, 'check_image_upload', ['upload_id' => $prepared['upload_id']]);
        $this->assertTrue($checked['uploaded']);
        $this->assertSame('hero/neues-hero.jpg', $checked['path']);
        $this->assertSame('Team vor dem Haus', AssetContainer::find('assets')->asset('hero/neues-hero.jpg')->get('alt'));

        $preview = $this->tool($editor, 'preview_entry_edit', ['entry_id' => $page->id(), 'edits' => [$hero['id'] => 'assets::'.$checked['path']]]);
        $this->assertSame(['hero/alt.jpg', 'hero/neues-hero.jpg'], [$preview['changes'][0]['before'], $preview['changes'][0]['after']]);
        $this->assertTrue($this->tool($editor, 'save_entry_edit', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true])['saved']);

        $data = Entry::find($page->id())->data()->all();
        $this->assertSame('hero/neues-hero.jpg', $data['hero_image']);
        $this->assertSame('Kontakt', $data['title']);
        $this->assertSame([['id' => 'b1', 'type' => 'text_image', 'enabled' => true, 'title' => 'Bankkaution']], $data['blocks'], 'Blocks nobody edited are not touched.');
    }

    public function test_an_image_goes_into_an_empty_image_field_of_a_section(): void
    {
        $page = $this->page();
        $editor = $this->serverAs($this->user('mcp-image', 'mcp_image_editor'));

        $image = collect($this->tool($editor, 'preview_entry_edit', ['entry_id' => $page->id()])['segments'])
            ->first(fn ($s) => ($s['kind'] ?? null) === 'asset' && $s['location'] === 'Komponenten › text_image 1 "Bankkaution"');
        $this->assertNull($image['value'], 'An image field the section never set is listed too.');

        $missing = $this->tool($editor, 'preview_entry_edit', ['entry_id' => $page->id(), 'edits' => [$image['id'] => 'nirgends/bild.jpg']]);
        $this->assertSame('invalid_values', $missing['error']);

        $preview = $this->tool($editor, 'preview_entry_edit', ['entry_id' => $page->id(), 'edits' => [$image['id'] => 'team/anna.jpg']]);
        $this->tool($editor, 'save_entry_edit', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true]);

        $this->assertSame('team/anna.jpg', Entry::find($page->id())->data()->all()['blocks'][0]['image']);
    }

    public function test_multi_image_and_restricted_folders_are_respected(): void
    {
        $page = $this->page([['id' => 't1', 'type' => 'team', 'enabled' => true, 'photos' => ['team/anna.jpg']]]);
        $editor = $this->serverAs($this->user('mcp-image', 'mcp_image_editor'));

        $photos = $this->segment($editor, $page->id(), 'photos');
        $this->assertTrue($photos['multiple']);
        $this->assertTrue($photos['restrict_to_folder']);

        $outside = $this->tool($editor, 'preview_entry_edit', ['entry_id' => $page->id(), 'edits' => [$photos['id'] => ['team/anna.jpg', 'hero/alt.jpg']]]);
        $this->assertSame('invalid_values', $outside['error']);

        Storage::disk('test_assets')->put('team/ben.jpg', 'x');
        $preview = $this->tool($editor, 'preview_entry_edit', ['entry_id' => $page->id(), 'edits' => [$photos['id'] => 'team/ben.jpg']]);
        $this->tool($editor, 'save_entry_edit', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true]);
        $this->assertSame(['team/ben.jpg'], Entry::find($page->id())->data()->all()['blocks'][0]['photos']);
    }

    public function test_uploads_follow_the_role_and_a_link_takes_one_image(): void
    {
        $writer = $this->serverAs($this->user('mcp-text', 'mcp_text_editor'));
        $this->assertSame('forbidden', $this->tool($writer, 'prepare_image_upload', ['container' => 'assets'])['error']);

        $editor = $this->serverAs($this->user('mcp-image', 'mcp_image_editor'));
        $prepared = $this->tool($editor, 'prepare_image_upload', ['folder' => 'hero']);
        $this->assertSame('assets', $prepared['container'], 'The only container the role may upload to is the default.');

        $this->post('/mcp/upload/'.$prepared['upload_id'], ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->post('/mcp/upload/'.$prepared['upload_id'], ['file' => UploadedFile::fake()->image('alt.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('path', 'hero/alt-2.jpg');
        $this->assertSame('x', Storage::disk('test_assets')->get('hero/alt.jpg'), 'An existing file is never overwritten.');

        $this->post('/mcp/upload/'.$prepared['upload_id'], ['file' => UploadedFile::fake()->image('again.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(409);
        $this->assertSame('expired', $this->tool($writer, 'check_image_upload', ['upload_id' => $prepared['upload_id']])['error'], 'Another account cannot read the upload.');
        $this->get('/mcp/upload/'.str_repeat('a', 40))->assertNotFound();
    }

    public function test_an_editor_lists_the_files_of_a_folder(): void
    {
        Storage::disk('test_assets')->put('team/2026/carla.png', 'x');
        $editor = $this->serverAs($this->user('mcp-image', 'mcp_image_editor'));

        $team = $this->tool($editor, 'list_assets', ['folder' => '/team/']);
        $this->assertSame('assets', $team['container'], 'The only container the role may view is the default.');
        $this->assertSame(['team/2026'], $team['folders']);
        $this->assertSame(['team/anna.jpg'], array_column($team['files'], 'path'), 'Only the folder itself, not its subfolders.');
        $this->assertSame(1, $team['total_files']);

        $found = $this->tool($editor, 'list_assets', ['query' => 'CARLA']);
        $this->assertSame(['team/2026/carla.png'], array_column($found['files'], 'path'), 'A query searches below the folder.');

        $writer = $this->serverAs($this->user('mcp-text', 'mcp_text_editor'));
        $this->assertSame('forbidden', $this->tool($writer, 'list_assets', ['container' => 'assets'])['error']);
        $this->assertSame([], $this->tool($writer, 'list_assets', [])['containers']);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $blocks
     */
    private function page(?array $blocks = null): \Statamic\Contracts\Entries\Entry
    {
        $page = Entry::make()->collection('mcp_media')->blueprint('page')->slug('kontakt')->data([
            'title' => 'Kontakt',
            'hero_image' => 'hero/alt.jpg',
            'blocks' => $blocks ?? [['id' => 'b1', 'type' => 'text_image', 'enabled' => true, 'title' => 'Bankkaution']],
        ]);
        $page->save();

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    private function segment(TranslationMcpServer $server, string $entryId, string $field): array
    {
        $segments = $this->tool($server, 'preview_entry_edit', ['entry_id' => $entryId])['segments'];

        return collect($segments)->firstWhere('field', $field);
    }

    private function user(string $id, string $role): \Statamic\Contracts\Auth\User
    {
        $user = User::make()->id($id)->email($id.'@example.com')->assignRole($role);
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
