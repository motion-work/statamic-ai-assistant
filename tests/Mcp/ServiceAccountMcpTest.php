<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use MotionWork\StatamicAiAssistant\Mcp\ServiceTokens;
use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Services\SetHintsService;
use MotionWork\StatamicAiAssistant\Support\GlossaryAccess;
use MotionWork\StatamicAiAssistant\Support\PackageInstallers;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

/**
 * A control plane (mateo) calls /mcp with a service token instead of the
 * Claude app's OAuth sign-in: the token acts as its Control Panel user and
 * adds the service-only setup tools.
 */
class ServiceAccountMcpTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->dir = sys_get_temp_dir().'/sai-service-'.uniqid();
        mkdir($this->dir);
        config([
            'app.url' => 'https://cms.example',
            'statamic.editions.pro' => true,
            'statamic.system.multisite' => true,
            'statamic-ai-assistant.mcp_service_tokens' => true,
            'statamic-ai-assistant.mcp_service_tokens_path' => $this->dir.'/service-tokens.yaml',
            'statamic-ai-assistant.access_path' => $this->dir.'/access.yaml',
            'statamic-ai-assistant.set_hints_path' => $this->dir.'/set-hints.yaml',
            'statamic-ai-assistant.translation_glossary_path' => $this->dir.'/glossary.yaml',
            'statamic-ai-assistant.translation_style_rules_path' => $this->dir.'/style.yaml',
            'deepl.api_key' => null,
        ]);
        Site::setSites([
            'de' => ['name' => 'Deutsch', 'locale' => 'de_CH', 'url' => 'http://localhost/'],
            'fr' => ['name' => 'Français', 'locale' => 'fr_CH', 'url' => 'http://localhost/fr/'],
        ]);

        app(SetHintsService::class)->save([
            'hero' => ['ai_description' => 'Grosser Einstieg mit Bild.', 'when_to_use' => ['Erste Sektion jeder Seite']],
        ]);
        Blueprint::make('page')->setNamespace('collections.svc_pages')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'required' => true]],
                ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                    'hero' => ['display' => 'Hero', 'fields' => [
                        ['handle' => 'headline', 'field' => ['type' => 'text']],
                        ['handle' => 'image', 'field' => ['type' => 'assets']],
                    ]],
                    'text' => ['display' => 'Text', 'fields' => [['handle' => 'body', 'field' => ['type' => 'bard']]]],
                ]]]]],
            ]]]]],
        ])->save();
        Collection::make('svc_pages')->title('Seiten')->sites(['de', 'fr'])->save();

        Role::make('mateo')->title('mateo')->permissions([
            'access cp', 'access de site', 'access fr site',
            'view svc_pages entries', 'edit svc_pages entries', 'create svc_pages entries',
            GlossaryAccess::PERMISSION, 'configure collections', 'configure fields',
        ])->save();
        User::make()->id('svc-bot')->email('mateo@instance.local')->assignRole('mateo')->save();
        $this->grant(['agent_settings' => ['roles' => ['mateo']]]);
    }

    protected function tearDown(): void
    {
        foreach (['svc-bot', 'svc-super'] as $id) {
            User::find($id)?->delete();
        }
        Role::find('mateo')?->delete();
        Collection::findByHandle('svc_pages')?->delete();
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_service_token_lists_the_block_catalog_over_http(): void
    {
        $token = $this->mint();

        $response = $this->rpc($token, 'tools/call', ['name' => 'list_blocks', 'arguments' => new \stdClass]);

        $response->assertOk();
        $response->assertJsonPath('jsonrpc', '2.0');
        $response->assertJsonPath('result.isError', false);
        $catalog = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame(['hero', 'text'], array_column($catalog['blocks'], 'handle'));
        $this->assertSame(2, $catalog['count']);
        $this->assertSame(['text'], $catalog['without_hints']);
        $hero = $catalog['blocks'][0];
        $this->assertSame('Hero', $hero['title']);
        $this->assertSame('Grosser Einstieg mit Bild.', $hero['ai_description']);
        $this->assertSame(['Erste Sektion jeder Seite'], $hero['when_to_use']);
        $this->assertTrue($hero['has_hint']);
        $this->assertSame('short text, images / visual', $hero['content_mix']);
        $this->assertSame([['collection' => 'svc_pages', 'blueprint' => 'page', 'field' => 'blocks']], $hero['used_in']);
    }

    public function test_list_blocks_filters_by_collection_and_refuses_an_unknown_one(): void
    {
        $server = $this->serverAs(User::find('svc-bot'), service: false);

        $this->assertSame(2, $this->tool($server, 'list_blocks', ['collection' => 'svc_pages'])['count']);
        $this->assertSame(0, $this->tool($server, 'list_blocks', ['collection' => 'svc_pages', 'blueprint' => 'other'])['count']);
        $this->assertSame('missing_collection', $this->tool($server, 'list_blocks', ['collection' => 'nope'])['error']);
    }

    public function test_with_the_flag_off_a_service_token_is_refused(): void
    {
        $token = $this->mint();
        config(['statamic-ai-assistant.mcp_service_tokens' => false]);

        $response = $this->rpc($token, 'tools/list');

        $response->assertUnauthorized();
        $response->assertJsonPath('error', 'unauthorized');
    }

    public function test_tokens_are_hashed_at_rest_and_a_rotated_or_revoked_token_stops_working(): void
    {
        $old = $this->mint();
        $stored = (string) file_get_contents($this->dir.'/service-tokens.yaml');
        $this->assertStringStartsWith(ServiceTokens::PREFIX, $old);
        $this->assertStringNotContainsString($old, $stored);
        $this->assertStringContainsString(hash('sha256', $old), $stored);

        $new = $this->mint();
        $this->rpc($old, 'tools/list')->assertUnauthorized();
        $this->rpc($new, 'tools/list')->assertOk();

        $this->artisan('statamic-ai-assistant:service-token', ['email' => 'mateo@instance.local', '--name' => 'mateo', '--revoke' => true])->assertSuccessful();
        $this->rpc($new, 'tools/list')->assertUnauthorized();
        $this->rpc('sai_svc_'.str_repeat('x', 48), 'tools/list')->assertUnauthorized();
    }

    public function test_a_token_of_a_deleted_user_is_refused(): void
    {
        $token = $this->mint();
        User::find('svc-bot')->delete();

        $this->rpc($token, 'tools/list')->assertUnauthorized();
    }

    public function test_the_command_needs_a_control_panel_user(): void
    {
        $this->artisan('statamic-ai-assistant:service-token', ['email' => 'nobody@example.com'])->assertFailed();
        $this->assertFileDoesNotExist($this->dir.'/service-tokens.yaml');
    }

    public function test_the_setup_tools_are_offered_only_to_a_service_account(): void
    {
        $setup = ['write_site_instructions', 'set_style_rules', 'install_package'];
        $signedIn = $this->serverAs(User::find('svc-bot'), service: false);
        $names = $this->toolNames($signedIn);
        $this->assertContains('list_blocks', $names);
        $this->assertSame([], array_values(array_intersect($setup, $names)));
        $this->assertSame('service_account_only', $this->tool($signedIn, 'write_site_instructions', ['markdown' => 'x'])['error']);
        $this->assertSame('', app(SetHintsService::class)->siteInstructions());

        $tools = $this->rpc($this->mint(), 'tools/list')->json('result.tools');
        $this->assertSame($setup, array_values(array_intersect($setup, array_column($tools, 'name'))));
    }

    public function test_the_token_follows_the_users_claude_level(): void
    {
        $this->grant(['agent_settings' => ['roles' => ['mateo']], 'claude_restricted' => true, 'claude_read' => ['roles' => ['mateo']]]);
        $token = $this->mint();

        $names = array_column($this->rpc($token, 'tools/list')->json('result.tools'), 'name');
        $this->assertContains('list_blocks', $names);
        $this->assertNotContains('write_site_instructions', $names);

        $refused = $this->call_($token, 'write_site_instructions', ['markdown' => 'Du-Form.']);
        $this->assertSame('forbidden', $refused['error']);
        $this->assertSame('', app(SetHintsService::class)->siteInstructions());
    }

    public function test_site_instructions_from_the_service_reach_the_brief_and_the_ai_instructions_page(): void
    {
        $token = $this->mint();

        $saved = $this->call_($token, 'write_site_instructions', ['markdown' => "# Showroom Muster\n\nImmer per Du. Termine statt Shop."]);

        $this->assertTrue($saved['saved']);
        $this->assertSame(52, $saved['characters']);
        $this->assertFalse($saved['truncated_in_prompts']);
        $brief = $this->call_($token, 'get_content_model', ['collection' => 'svc_pages'])['brief'];
        $this->assertStringContainsString("SITE INSTRUCTIONS (set by this site's editors — always follow them):\n# Showroom Muster", $brief);

        config(['statamic-ai-assistant.infomaniak_api_token' => null, 'statamic-ai-assistant.openrouter_api_key' => null]);
        $super = User::make()->id('svc-super')->email('super@example.com')->makeSuper();
        $super->save();
        $this->actingAs($super);
        $this->get('/cp/ai-block-hints')->assertOk();
        $this->getJson('/cp/ai-block-hints/list')->assertJsonPath('site_instructions', "# Showroom Muster\n\nImmer per Du. Termine statt Shop.");

        config(['statamic-ai-assistant.mcp_service_tokens' => false]);
        $this->get('/cp/ai-block-hints')->assertNotFound();
    }

    public function test_site_instructions_need_the_agent_settings_grant_and_a_sane_length(): void
    {
        $token = $this->mint();
        $tooLong = $this->call_($token, 'write_site_instructions', ['markdown' => str_repeat('a', SetHintsService::SITE_INSTRUCTIONS_MAX_CHARS + 1)]);
        $this->assertSame('too_long', $tooLong['error']);

        $this->grant([]);
        $this->assertSame('forbidden', $this->call_($token, 'write_site_instructions', ['markdown' => 'Du-Form.'])['error']);
        $this->assertSame('', app(SetHintsService::class)->siteInstructions());
    }

    public function test_style_rules_as_a_list_apply_to_every_language_and_reach_the_brief(): void
    {
        $token = $this->mint();

        $saved = $this->call_($token, 'set_style_rules', ['rules' => ['Nie "günstig" schreiben.', ' ']]);

        $this->assertTrue($saved['saved']);
        $this->assertSame(['de' => ['Nie "günstig" schreiben.'], 'fr' => ['Nie "günstig" schreiben.']], $saved['rules']);
        $brief = $this->call_($token, 'get_content_model', ['collection' => 'svc_pages', 'site' => 'de'])['brief'];
        $this->assertStringContainsString("STYLE & TONE RULES (site-approved; always follow them when writing this language):\n- Nie \"günstig\" schreiben.", $brief);

        $perLanguage = $this->call_($token, 'set_style_rules', ['rules' => ['fr' => ['Vouvoyer.']]]);
        $this->assertSame(['fr' => ['Vouvoyer.']], $perLanguage['rules']);
    }

    public function test_style_rules_need_the_glossary_permission(): void
    {
        Role::find('mateo')->removePermission(GlossaryAccess::PERMISSION)->save();

        $this->assertSame('forbidden', $this->call_($this->mint(), 'set_style_rules', ['rules' => ['x']])['error']);
        $this->assertFileDoesNotExist($this->dir.'/style.yaml');
    }

    public function test_install_package_runs_a_registered_installer_or_the_package_command(): void
    {
        $token = $this->mint();

        $missing = $this->call_($token, 'install_package', ['key' => 'showroom-retail']);
        $this->assertSame('no_installer', $missing['error']);
        $this->assertSame('invalid_key', $this->call_($token, 'install_package', ['key' => '../etc'])['error']);

        app(PackageInstallers::class)->register('showroom-retail', fn (string $key) => 'Published 7 blueprints for '.$key);
        $installed = $this->call_($token, 'install_package', ['key' => 'showroom-retail']);
        $this->assertSame(['installed' => true, 'key' => 'showroom-retail', 'via' => 'registered', 'output' => 'Published 7 blueprints for showroom-retail'], $installed);

        $this->packageCommand(0);
        $viaArtisan = $this->call_($token, 'install_package', ['key' => 'other-package']);
        $this->assertSame('artisan:mateo-package:install', $viaArtisan['via']);
        $this->assertSame('installed other-package', $viaArtisan['output']);

        $this->packageCommand(1);
        $this->assertSame('install_failed', $this->call_($token, 'install_package', ['key' => 'other-package'])['error']);
    }

    public function test_install_package_needs_the_blueprint_permissions(): void
    {
        Role::find('mateo')->removePermission('configure fields')->save();
        app(PackageInstallers::class)->register('showroom-retail', fn () => 'done');

        $refused = $this->call_($this->mint(), 'install_package', ['key' => 'showroom-retail']);

        $this->assertSame('forbidden', $refused['error']);
        $this->assertStringContainsString('configure fields', $refused['message']);
    }

    /** Stands in for the command a mateo package registers. */
    private function packageCommand(int $exit): void
    {
        $command = new class extends Command
        {
            protected $signature = 'mateo-package:install {key}';

            public int $exit = 0;

            public function handle(): int
            {
                $this->line('installed '.$this->argument('key'));

                return $this->exit;
            }
        };
        $command->exit = $exit;
        $command->setLaravel($this->app);
        $this->app->make(Kernel::class)->registerCommand($command);
    }

    private function mint(): string
    {
        $this->artisan('statamic-ai-assistant:service-token', ['email' => 'mateo@instance.local', '--name' => 'mateo', '--plain' => true])->assertSuccessful();
        $records = YAML::parse((string) file_get_contents($this->dir.'/service-tokens.yaml'))['tokens'];
        $this->assertCount(1, $records, 'Minting the same name again replaces the token.');

        // The command printed the plaintext; mint directly to get one the test can use.
        return app(ServiceTokens::class)->mint(User::find('svc-bot'), 'mateo');
    }

    /**
     * @param  array<string, mixed>  $grants
     */
    private function grant(array $grants): void
    {
        file_put_contents($this->dir.'/access.yaml', YAML::dump($grants));
        $this->app->forgetInstance(AgentAccessStore::class);
    }

    private function rpc(string $token, string $method, array $params = []): TestResponse
    {
        return $this->postJson('/mcp', array_filter([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params ?: null,
        ]), ['Authorization' => 'Bearer '.$token]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function call_(string $token, string $name, array $arguments): array
    {
        $response = $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments]);
        $response->assertOk();

        return json_decode($response->json('result.content.0.text'), true);
    }

    private function serverAs(\Statamic\Contracts\Auth\User $user, bool $service): TranslationMcpServer
    {
        $server = $this->app->make(TranslationMcpServer::class);
        $server->bridge()->setActor($user, $service);

        return $server;
    }

    /**
     * @return array<int, string>
     */
    private function toolNames(TranslationMcpServer $server): array
    {
        return array_column($server->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['result']['tools'], 'name');
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

        return json_decode($response['result']['content'][0]['text'], true);
    }
}
