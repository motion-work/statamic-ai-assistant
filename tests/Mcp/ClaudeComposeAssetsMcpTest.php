<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * The offer blueprint in a host app: a required slug field that is not the
 * entry address, a gallery whose images live outside the field's starting
 * folder, and a 3D model (GLB) next to images in that folder.
 */
class ClaudeComposeAssetsMcpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->wipeEntries();

        config(['statamic.system.multisite' => true, 'statamic.editions.pro' => true]);
        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => 'http://localhost/'],
        ]);

        config(['filesystems.disks.test_assets' => [
            'driver' => 'local',
            'root' => Storage::fake('test_assets')->path(''),
        ]]);
        AssetContainer::make('assets')->disk('test_assets')->title('Assets')->save();
        Storage::disk('test_assets')->put('khamso-nattha-monarch-esstisch-hobeln.jpg', 'x');
        Storage::disk('test_assets')->put('offerten/workshop.jpg', 'x');
        Storage::disk('test_assets')->put('offerten/esstisch.glb', 'x');

        Blueprint::make('offer')->setNamespace('collections.mcp_offer')->setContents([
            'tabs' => [
                'main' => ['sections' => [['fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Titel', 'required' => true]],
                    ['handle' => 'offer_components', 'field' => [
                        'type' => 'replicator',
                        'display' => 'Inhalt',
                        'sets' => ['main' => ['sets' => [
                            'gallery' => ['display' => 'Galerie', 'fields' => [
                                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Titel']],
                                ['handle' => 'images', 'field' => [
                                    'type' => 'assets',
                                    'display' => 'Bilder',
                                    'container' => 'assets',
                                    'folder' => 'offerten',
                                    'min_files' => 1,
                                ]],
                            ]],
                            'contact' => ['display' => 'Kontakt', 'fields' => [
                                ['handle' => 'image', 'field' => [
                                    'type' => 'assets',
                                    'display' => 'Bild',
                                    'container' => 'assets',
                                    'folder' => 'offerten',
                                    'max_files' => 1,
                                ]],
                            ]],
                            'model' => ['display' => 'Modell', 'fields' => [
                                ['handle' => 'model', 'field' => [
                                    'type' => 'assets',
                                    'display' => '3D-Modell',
                                    'container' => 'assets',
                                    'folder' => 'offerten',
                                    'max_files' => 1,
                                    'required' => true,
                                    'validate' => ['required'],
                                ]],
                            ]],
                            'locked' => ['display' => 'Nur Offerten', 'fields' => [
                                ['handle' => 'images', 'field' => [
                                    'type' => 'assets',
                                    'display' => 'Bilder',
                                    'container' => 'assets',
                                    'folder' => 'offerten',
                                    'restrict' => true,
                                    'min_files' => 1,
                                ]],
                            ]],
                        ]]],
                    ]],
                ]]]],
                'sidebar' => ['sections' => [['fields' => [
                    ['handle' => 'client_slug', 'field' => [
                        'type' => 'slug',
                        'display' => 'Kürzel (Name)',
                        'validate' => ['required', 'max:200'],
                    ]],
                    ['handle' => 'slug', 'field' => ['type' => 'slug', 'display' => 'Adresse', 'validate' => 'max:200']],
                ]]]],
            ],
        ])->save();
        Collection::make('mcp_offer')->title('Offerten')->sites(['de'])->save();

        Role::make('mcp_offer_editor')->permissions([
            'access cp',
            'access de site',
            'view mcp_offer entries',
            'edit mcp_offer entries',
            'create mcp_offer entries',
            'view assets assets',
            'upload assets assets',
        ])->save();
    }

    protected function tearDown(): void
    {
        $this->wipeEntries();
        User::find('mcp-offer')?->delete();
        Role::find('mcp_offer_editor')?->delete();
        Collection::findByHandle('mcp_offer')?->delete();
        AssetContainer::findByHandle('assets')?->delete();
        parent::tearDown();
    }

    public function test_a_new_offer_keeps_its_slug_field_and_existing_files(): void
    {
        $server = $this->server();

        $brief = $this->tool($server, 'get_content_model', ['collection' => 'mcp_offer', 'blueprint' => 'offer', 'site' => 'de'])['brief'];
        $this->assertStringContainsString('client_slug', $brief);
        $this->assertStringNotContainsString('Short vivid description', $this->tool($server, 'get_block_schemas', [
            'collection' => 'mcp_offer',
            'blueprint' => 'offer',
            'site' => 'de',
            'blocks' => ['gallery', 'model'],
        ])['blocks']['offer_components']['gallery']['images']['description'] ?? '');

        $preview = $this->tool($server, 'preview_compose_entry', [
            'collection' => 'mcp_offer',
            'blueprint' => 'offer',
            'site' => 'de',
            'data' => $this->offer([
                ['type' => 'gallery', 'title' => 'Aus der Werkstatt', 'images' => 'khamso-nattha-monarch-esstisch-hobeln.jpg'],
                ['type' => 'contact', 'image' => 'khamso-nattha-monarch-esstisch-hobeln.jpg'],
                ['type' => 'model', 'model' => 'offerten/esstisch.glb'],
            ]),
        ]);
        $this->assertArrayNotHasKey('error', $preview, json_encode($preview['problems'] ?? $preview));

        $saved = $this->tool($server, 'save_compose_entry', [
            'confirmation_token' => $preview['confirmation_token'],
            'confirmed' => true,
        ]);
        $this->assertTrue($saved['saved']);

        $data = Entry::find($saved['entry_id'])->data()->all();
        $this->assertSame('ellen-schmitt', $data['client_slug']);
        $this->assertArrayNotHasKey('slug', $data, 'The entry address stays on the entry, not in its data.');
        $this->assertSame(['khamso-nattha-monarch-esstisch-hobeln.jpg'], $data['offer_components'][0]['images']);
        $this->assertSame('khamso-nattha-monarch-esstisch-hobeln.jpg', $data['offer_components'][1]['image']);
        $this->assertSame('offerten/esstisch.glb', $data['offer_components'][2]['model']);
    }

    public function test_a_missing_model_and_a_restricted_folder_are_reported(): void
    {
        $server = $this->server();

        $missing = $this->tool($server, 'preview_compose_entry', [
            'collection' => 'mcp_offer',
            'blueprint' => 'offer',
            'site' => 'de',
            'data' => $this->offer([
                ['type' => 'gallery', 'images' => ['khamso-nattha-monarch-esstisch-hobeln.jpg']],
                ['type' => 'model', 'model' => 'offerten/sideboard-ellen/sideboard-variante-b.glb'],
            ]),
        ]);
        $this->assertSame('invalid_entry', $missing['error']);
        $this->assertArrayHasKey('offer_components.1.model', $missing['problems']);
        $this->assertStringContainsString('offerten/sideboard-ellen/sideboard-variante-b.glb', implode("\n", $missing['warnings']));

        $outside = $this->tool($server, 'preview_compose_entry', [
            'collection' => 'mcp_offer',
            'blueprint' => 'offer',
            'site' => 'de',
            'data' => $this->offer([
                ['type' => 'locked', 'images' => 'khamso-nattha-monarch-esstisch-hobeln.jpg'],
                ['type' => 'model', 'model' => 'offerten/esstisch.glb'],
            ]),
        ]);
        $this->assertSame('invalid_entry', $outside['error']);
        $this->assertArrayHasKey('offer_components.0.images', $outside['problems']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function offer(array $components): array
    {
        return [
            'title' => 'Sideboard',
            'client_slug' => 'ellen-schmitt',
            'offer_components' => $components,
        ];
    }

    private function server(): TranslationMcpServer
    {
        $user = User::make()->id('mcp-offer')->email('mcp-offer@example.com')->assignRole('mcp_offer_editor');
        $user->save();

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

        $decoded = json_decode($response['result']['content'][0]['text'] ?? '', true);
        $this->assertIsArray($decoded, (string) ($response['error']['message'] ?? ''));

        return $decoded;
    }
}
