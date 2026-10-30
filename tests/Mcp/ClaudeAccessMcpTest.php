<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use MotionWork\StatamicAiAssistant\Mcp\McpOAuth;
use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class ClaudeAccessMcpTest extends TestCase
{
    private string $accessPath;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->accessPath = sys_get_temp_dir().'/sai-access-'.uniqid().'.yaml';
        config(['statamic.editions.pro' => true, 'statamic-ai-assistant.access_path' => $this->accessPath]);

        Blueprint::make('page')->setNamespace('collections.mcp_access')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ]]]]],
        ])->save();
        Collection::make('mcp_access')->title('Pages')->save();

        // In the Control Panel this role may edit and publish everything here.
        Role::make('mcp_full_editor')->title('Editor')->permissions([
            'access cp',
            'view mcp_access entries',
            'edit mcp_access entries',
            'publish mcp_access entries',
        ])->save();
    }

    protected function tearDown(): void
    {
        Entry::query()->where('collection', 'mcp_access')->get()->each->delete();
        foreach (['michi', 'anna', 'boss'] as $id) {
            User::find($id)?->delete();
        }
        Role::find('mcp_full_editor')?->delete();
        Collection::findByHandle('mcp_access')?->delete();
        @unlink($this->accessPath);
        parent::tearDown();
    }

    public function test_until_restricted_the_control_panel_role_alone_decides(): void
    {
        $this->grant(['claude_read' => ['users' => ['michi']]], restricted: false);
        $michi = $this->serverAs($this->user('michi'));

        $this->assertContains('set_published', $this->toolNames($michi));
        $this->assertTrue($this->tool($michi, 'set_published', ['entry_id' => $this->page()->id(), 'published' => false, 'confirmed' => true])['saved']);
    }

    public function test_michi_edits_in_the_control_panel_but_only_reads_through_claude(): void
    {
        $this->grant(['claude_read' => ['users' => ['michi']]]);
        $page = $this->page();
        $michi = $this->serverAs($this->user('michi'));

        $tools = $this->toolNames($michi);
        $this->assertContains('read_entry', $tools);
        $this->assertNotContains('preview_entry_edit', $tools, 'Claude is not even offered the write tools.');
        $this->assertNotContains('set_published', $tools);

        $this->assertSame('Seite', $this->tool($michi, 'read_entry', ['entry_id' => $page->id()])['title']);
        $refused = $this->tool($michi, 'preview_entry_edit', ['entry_id' => $page->id(), 'edits' => ['0' => 'Neu']]);
        $this->assertSame('forbidden', $refused['error']);
        $this->assertStringContainsString('only read', $refused['message']);
        $this->assertTrue(User::find('michi')->can('edit', Entry::find($page->id())), 'The Control Panel role is unchanged.');
    }

    public function test_levels_add_up_and_the_role_still_applies_on_top(): void
    {
        $this->grant(['claude_edit' => ['roles' => ['mcp_full_editor']], 'claude_publish' => ['users' => ['anna']]]);
        $page = $this->page();

        $michi = $this->serverAs($this->user('michi'));
        $this->assertContains('read_entry', $this->toolNames($michi), 'Edit includes read.');
        $preview = $this->tool($michi, 'preview_entry_edit', ['entry_id' => $page->id(), 'edits' => ['0' => 'Neu']]);
        $this->assertTrue($this->tool($michi, 'save_entry_edit', ['confirmation_token' => $preview['confirmation_token'], 'confirmed' => true])['saved']);
        $this->assertSame('forbidden', $this->tool($michi, 'set_published', ['entry_id' => $page->id(), 'published' => false])['error']);

        $anna = $this->serverAs($this->user('anna'));
        $this->assertTrue($this->tool($anna, 'set_published', ['entry_id' => $page->id(), 'published' => false, 'confirmed' => true])['saved']);

        $outsider = User::make()->id('boss')->email('boss@example.com');
        $outsider->save();
        $this->assertSame('forbidden', $this->tool($this->serverAs($outsider), 'set_published', ['entry_id' => $page->id(), 'published' => true])['error'], 'A Claude grant never goes beyond the Control Panel role.');
    }

    public function test_without_any_level_the_account_cannot_sign_in_and_super_admins_always_can(): void
    {
        $this->grant([]);
        $michi = $this->user('michi');
        $michi->password('correct-horse')->save();
        $boss = User::make()->id('boss')->email('boss@example.com')->makeSuper();
        $boss->password('correct-horse');
        $boss->save();

        $oauth = $this->app->make(McpOAuth::class);
        $client = $oauth->registerClient(['https://claude.ai/api/mcp/auth_callback']);
        $signIn = fn ($email) => $oauth->authorize($client['client_id'], 'https://claude.ai/api/mcp/auth_callback', 'challenge', $email, 'correct-horse', null);

        $this->assertStringContainsString('no access to Claude', $signIn('michi@example.com')['error']);
        $this->assertArrayHasKey('redirect', $signIn('boss@example.com'));
        $this->assertSame([], $this->toolNames($this->serverAs($michi)), 'An account signed in before the restriction sees no tools.');
        $this->assertContains('set_published', $this->toolNames($this->serverAs($boss)));
    }

    /**
     * @param  array<string, array<string, array<int, string>>>  $grants
     */
    private function grant(array $grants, bool $restricted = true): void
    {
        app(AgentAccessStore::class)->save($grants + ['claude_restricted' => $restricted]);
    }

    private function page(): \Statamic\Contracts\Entries\Entry
    {
        $page = Entry::make()->collection('mcp_access')->blueprint('page')->slug('seite')->data(['title' => 'Seite'])->published(true);
        $page->save();

        return $page;
    }

    private function user(string $id): \Statamic\Contracts\Auth\User
    {
        $user = User::make()->id($id)->email($id.'@example.com')->assignRole('mcp_full_editor');
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

        $decoded = json_decode($response['result']['content'][0]['text'], true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
