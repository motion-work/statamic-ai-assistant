<?php

namespace MotionWork\StatamicAiAssistant\Tests\Support;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class AgentAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic-ai-assistant.access_path' => sys_get_temp_dir().'/sai-access-'.uniqid().'.yaml']);
    }

    private function grant(array $access): void
    {
        app(AgentAccessStore::class)->save($access);
    }

    private function user(string $id, ?string $role = null)
    {
        $u = User::make()->id($id)->email($id);
        if ($role) {
            Role::make($role)->save();
            $u->assignRole($role);
        }

        return $u;
    }

    public function test_super_user_passes_every_feature_even_with_empty_store(): void
    {
        $super = User::make()->id('s@x.test')->email('s@x.test')->makeSuper();

        foreach (AgentAccess::FEATURES as $feature) {
            $this->assertTrue(AgentAccess::allows($feature, $super));
        }
        $this->assertTrue(AgentAccess::canManage($super));
    }

    public function test_empty_store_denies_non_super(): void
    {
        $editor = $this->user('e@x.test', 'editor');

        foreach (AgentAccess::FEATURES as $feature) {
            $this->assertFalse(AgentAccess::allows($feature, $editor));
        }
        $this->assertFalse(AgentAccess::canManage($editor));
    }

    public function test_user_id_grant_allows_only_that_user(): void
    {
        $this->grant(['agent' => ['roles' => [], 'users' => ['anna@x.test']]]);

        $this->assertTrue(AgentAccess::allows('agent', $this->user('anna@x.test')));
        $this->assertFalse(AgentAccess::allows('agent', $this->user('ben@x.test')));
    }

    public function test_role_grant_allows_any_user_with_that_role(): void
    {
        $this->grant(['agent' => ['roles' => ['editor'], 'users' => []]]);

        $this->assertTrue(AgentAccess::allows('agent', $this->user('e@x.test', 'editor')));
        $this->assertFalse(AgentAccess::allows('agent', $this->user('other@x.test', 'author')));
    }

    public function test_grants_are_isolated_per_feature(): void
    {
        $this->grant(['agent' => ['roles' => ['editor'], 'users' => []]]);
        $editor = $this->user('e@x.test', 'editor');

        $this->assertTrue(AgentAccess::allows('agent', $editor));
        $this->assertFalse(AgentAccess::allows('bulk_translations', $editor));
        $this->assertFalse(AgentAccess::allows('agent_settings', $editor));
    }

    public function test_gate_ability_string_is_namespaced(): void
    {
        $this->assertSame('statamic-ai-assistant.agent', AgentAccess::gateAbility('agent'));
    }
}
