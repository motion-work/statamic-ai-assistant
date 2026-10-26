<?php

namespace MotionWork\StatamicAiAssistant\Tests\Support;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Role;
use Statamic\Facades\User;

class EntryCreationPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the access store to a scratch file per test.
        config([
            'statamic-ai-assistant.editor_limit_entries' => true,
            'statamic-ai-assistant.agent_max_plan_entries' => 100,
            'statamic-ai-assistant.access_path' => sys_get_temp_dir().'/sai-access-'.uniqid().'.yaml',
        ]);
    }

    private function superUser()
    {
        return User::make()->id('super@example.test')->email('super@example.test')->makeSuper();
    }

    private function editorUser(string $email = 'editor@example.test')
    {
        Role::make('editor')->save();
        $user = User::make()->id($email)->email($email);
        $user->assignRole('editor');

        return $user;
    }

    private function setLimits(array $limits): void
    {
        app(AgentAccessStore::class)->save(['agent' => ['roles' => [], 'users' => [], 'limits' => $limits]]);
    }

    public function test_super_user_keeps_the_full_configured_cap(): void
    {
        $super = $this->superUser();

        $this->assertFalse(EntryCreationPolicy::appliesTo($super));
        $this->assertSame(100, EntryCreationPolicy::maxPlanEntries($super));
    }

    public function test_non_super_user_defaults_to_one_entry_when_nothing_configured(): void
    {
        $editor = $this->editorUser();

        $this->assertTrue(EntryCreationPolicy::appliesTo($editor));
        $this->assertSame(1, EntryCreationPolicy::maxPlanEntries($editor));
    }

    public function test_per_role_limit_is_applied(): void
    {
        $this->setLimits(['default' => 1, 'roles' => ['editor' => 3], 'users' => []]);

        $this->assertSame(3, EntryCreationPolicy::maxPlanEntries($this->editorUser()));
    }

    public function test_per_user_override_wins_over_role_and_default(): void
    {
        $this->setLimits(['default' => 1, 'roles' => ['editor' => 3], 'users' => ['anna@example.test' => 9]]);

        Role::make('editor')->save();
        $anna = User::make()->id('anna@example.test')->email('anna@example.test');
        $anna->assignRole('editor');

        $this->assertSame(9, EntryCreationPolicy::maxPlanEntries($anna));
    }

    public function test_highest_matching_role_limit_wins(): void
    {
        Role::make('editor')->save();
        Role::make('lead')->save();
        $this->setLimits(['default' => 1, 'roles' => ['editor' => 3, 'lead' => 7], 'users' => []]);

        $user = User::make()->id('lead@example.test')->email('lead@example.test');
        $user->assignRole('editor');
        $user->assignRole('lead');

        $this->assertSame(7, EntryCreationPolicy::maxPlanEntries($user));
    }

    public function test_limit_is_clamped_to_the_configured_ceiling(): void
    {
        config(['statamic-ai-assistant.agent_max_plan_entries' => 5]);
        $this->setLimits(['default' => 1, 'roles' => ['editor' => 50], 'users' => []]);

        $this->assertSame(5, EntryCreationPolicy::maxPlanEntries($this->editorUser()));
    }

    public function test_disabling_the_limit_lets_non_super_users_run_batches(): void
    {
        config([
            'statamic-ai-assistant.editor_limit_entries' => false,
            'statamic-ai-assistant.agent_max_plan_entries' => 42,
        ]);

        $editor = $this->editorUser();
        $this->assertFalse(EntryCreationPolicy::appliesTo($editor));
        $this->assertSame(42, EntryCreationPolicy::maxPlanEntries($editor));
    }

    public function test_configured_cap_is_clamped_to_the_sane_ceiling(): void
    {
        config(['statamic-ai-assistant.agent_max_plan_entries' => 100000]);
        $this->assertSame(500, EntryCreationPolicy::configuredMaxPlanEntries());

        config(['statamic-ai-assistant.agent_max_plan_entries' => 0]);
        $this->assertSame(1, EntryCreationPolicy::configuredMaxPlanEntries());
    }
}
