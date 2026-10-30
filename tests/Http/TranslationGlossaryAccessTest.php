<?php

namespace MotionWork\StatamicAiAssistant\Tests\Http;

use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Support\GlossaryAccess;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class TranslationGlossaryAccessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/glossary-access-'.uniqid();
        mkdir($this->dir);
        config([
            'statamic-ai-assistant.translation_glossary_path' => $this->dir.'/glossary.yaml',
            'statamic-ai-assistant.translation_style_rules_path' => $this->dir.'/style.yaml',
            'deepl.api_key' => null,
            'statamic.editions.pro' => true,
        ]);

        Role::make('glossary_editor')->permissions(['access cp'])->save();
    }

    protected function tearDown(): void
    {
        foreach (['glossary-editor', 'glossary-super'] as $id) {
            User::find($id)?->delete();
        }
        Role::find('glossary_editor')?->delete();
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_the_role_permission_decides_who_changes_the_glossary_in_the_control_panel(): void
    {
        $editor = User::make()->id('glossary-editor')->email('editor@example.com')->assignRole('glossary_editor');
        $editor->save();
        $super = User::make()->id('glossary-super')->email('super@example.com')->makeSuper();
        $super->save();
        $payload = ['styles' => ['de' => ['Formelle Anrede (Sie).']]];

        $this->actingAs($editor);
        $this->getJson('/cp/ai-translation-glossary/data')->assertOk()->assertJsonPath('can_edit', false);
        $this->postJson('/cp/ai-translation-glossary/save', $payload)->assertForbidden();
        $this->assertFileDoesNotExist($this->dir.'/style.yaml');

        $this->actingAs($super);
        $this->getJson('/cp/ai-translation-glossary/data')->assertJsonPath('can_edit', true);

        Role::find('glossary_editor')->addPermission(GlossaryAccess::PERMISSION)->save();
        $this->actingAs(User::find('glossary-editor'));
        $this->getJson('/cp/ai-translation-glossary/data')->assertJsonPath('can_edit', true);
        $this->postJson('/cp/ai-translation-glossary/save', $payload)->assertOk();
        $this->assertFileExists($this->dir.'/style.yaml');
    }

    public function test_the_page_works_without_deepl_or_an_ai_model_because_claude_uses_it(): void
    {
        config(['statamic-ai-assistant.infomaniak_api_token' => null]);
        $super = User::make()->id('glossary-super')->email('super@example.com')->makeSuper();
        $super->save();
        $this->actingAs($super);

        $this->get('/cp/ai-translation-glossary')->assertOk();
        $this->getJson('/cp/ai-translation-glossary/data')
            ->assertJsonPath('deepl_configured', false)
            ->assertJsonPath('ai_translation', false);
        $this->postJson('/cp/ai-translation-glossary/save', ['styles' => ['de' => ['Formelle Anrede (Sie).']]])->assertOk();
        $this->assertFileExists($this->dir.'/style.yaml');

        config(['statamic-ai-assistant.translations' => false]);
        $this->get('/cp/ai-translation-glossary')->assertNotFound();
    }

    public function test_the_permission_is_listed_for_roles(): void
    {
        $this->assertNotNull(Permission::boot()->get(GlossaryAccess::PERMISSION));
    }

    public function test_the_role_permission_decides_who_changes_the_glossary_from_claude(): void
    {
        $editor = User::make()->id('glossary-editor')->email('editor@example.com')->assignRole('glossary_editor');
        $editor->save();
        $server = $this->app->make(TranslationMcpServer::class);
        $server->bridge()->setActor($editor);

        foreach (['save_glossary' => ['entries' => [], 'confirmed' => true], 'save_style_rules' => ['rules' => [], 'confirmed' => true]] as $tool => $arguments) {
            $response = $server->dispatch([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => $arguments],
            ]);
            $this->assertSame('forbidden', json_decode($response['result']['content'][0]['text'], true)['error'], $tool);
        }
        $this->assertFileDoesNotExist($this->dir.'/glossary.yaml');
        $this->assertFileDoesNotExist($this->dir.'/style.yaml');

        $read = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'read_glossary', 'arguments' => []],
        ]);
        $this->assertFalse($read['result']['isError'], 'Everyone can still read the glossary.');

        Role::find('glossary_editor')->addPermission(GlossaryAccess::PERMISSION)->save();
        $server->bridge()->setActor(User::find('glossary-editor'));
        $saved = $server->dispatch([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'save_style_rules', 'arguments' => ['rules' => ['de' => ['Formelle Anrede (Sie).']], 'confirmed' => true]],
        ]);
        $this->assertTrue(json_decode($saved['result']['content'][0]['text'], true)['saved']);
        $this->assertFileExists($this->dir.'/style.yaml');
    }
}
