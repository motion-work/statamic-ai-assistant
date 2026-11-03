<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;

class ClaudeAssetAltMcpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['statamic.editions.pro' => true]);

        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => '/'],
            'fr' => ['name' => 'Français', 'locale' => 'fr_FR', 'url' => '/fr/'],
        ]);

        config(['filesystems.disks.alt_assets' => [
            'driver' => 'local',
            'root' => Storage::fake('alt_assets')->path(''),
        ]]);

        Blueprint::make('assets')->setNamespace('assets')->setContents([
            'fields' => [
                ['handle' => 'alt', 'field' => ['type' => 'text']],
                ['handle' => 'alt_fr', 'field' => ['type' => 'text']],
            ],
        ])->save();

        AssetContainer::make('assets')->disk('alt_assets')->title('Assets')->save();

        $disk = Storage::disk('alt_assets');
        $disk->put('gallery/a.jpg', UploadedFile::fake()->image('a.jpg', 40, 30)->getContent());
        $disk->put('gallery/b.jpg', UploadedFile::fake()->image('b.jpg', 40, 30)->getContent());

        Role::make('alt_editor')->title('Alt editor')->permissions([
            'access cp',
            'view assets assets',
            'upload assets assets',
        ])->save();
    }

    protected function tearDown(): void
    {
        User::find('alt-user')?->delete();
        Role::find('alt_editor')?->delete();
        AssetContainer::findByHandle('assets')?->delete();
        parent::tearDown();
    }

    public function test_review_preview_and_save_alt_text_in_two_languages(): void
    {
        $server = $this->serverAs($this->user());

        $review = $this->reviewTool($server, [
            'container' => 'assets',
            'folder' => 'gallery',
            'locales' => ['de', 'fr'],
            'scope' => 'missing',
        ]);

        $this->assertTrue($review['ok']);
        $this->assertSame(['de' => 'alt', 'fr' => 'alt_fr'], $review['locale_handles']);
        $this->assertCount(2, $review['images']);

        $preview = $this->tool($server, 'preview_asset_alts', [
            'updates' => [
                [
                    'ref' => 'assets::gallery/a.jpg',
                    'values' => ['alt' => 'Terrasse mit Seeblick', 'alt_fr' => 'Terrasse avec vue sur le lac'],
                ],
                [
                    'ref' => 'assets::gallery/b.jpg',
                    'values' => ['alt' => 'Frühstücksbuffet im Restaurant', 'alt_fr' => 'Buffet du petit-déjeuner au restaurant'],
                ],
            ],
        ]);

        $this->assertFalse($preview['saved']);
        $this->assertNotEmpty($preview['confirmation_token']);

        $refused = $this->tool($server, 'save_asset_alts', [
            'confirmation_token' => $preview['confirmation_token'],
            'confirmed' => false,
        ]);
        $this->assertFalse($refused['saved']);

        $saved = $this->tool($server, 'save_asset_alts', [
            'confirmation_token' => $preview['confirmation_token'],
            'confirmed' => true,
        ]);
        $this->assertTrue($saved['saved']);

        $a = Asset::find('assets::gallery/a.jpg');
        $this->assertSame('Terrasse mit Seeblick', $a->get('alt'));
        $this->assertSame('Terrasse avec vue sur le lac', $a->get('alt_fr'));
    }

    public function test_scope_missing_skips_fully_filled_images(): void
    {
        $asset = Asset::find('assets::gallery/a.jpg');
        $asset->set('alt', 'Schon da')->set('alt_fr', 'Déjà là')->save();

        $server = $this->serverAs($this->user());
        $review = $this->reviewTool($server, [
            'container' => 'assets',
            'locales' => ['de', 'fr'],
            'scope' => 'missing',
        ]);

        $this->assertSame(1, $review['total_matching']);
        $this->assertSame('assets::gallery/b.jpg', $review['images'][0]['ref']);
    }

    private function user(): \Statamic\Contracts\Auth\User
    {
        $user = User::make()->id('alt-user')->email('alt@example.com')->assignRole('alt_editor');
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

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function reviewTool(TranslationMcpServer $server, array $arguments): array
    {
        $response = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'review_images', 'arguments' => $arguments],
        ]);

        $this->assertArrayHasKey('content', $response['result']);
        $blocks = $response['result']['content'];
        $this->assertNotEmpty($blocks);
        $decoded = json_decode($blocks[0]['text'], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
