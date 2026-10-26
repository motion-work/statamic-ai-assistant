<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Support;

use BoldWeb\StatamicAiAssistant\Support\EntryCreationPolicy;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

class EntryCreationPolicyTest extends TestCase
{
    public function test_non_super_user_is_capped_to_a_single_entry_when_limit_enabled(): void
    {
        config([
            'statamic-ai-assistant.editor_limit_entries' => true,
            'statamic-ai-assistant.bold_agent_max_plan_entries' => 100,
        ]);

        $this->assertTrue(EntryCreationPolicy::appliesTo(isSuper: false));
        $this->assertSame(1, EntryCreationPolicy::maxPlanEntries(isSuper: false));
    }

    public function test_super_user_keeps_the_full_configured_cap(): void
    {
        config([
            'statamic-ai-assistant.editor_limit_entries' => true,
            'statamic-ai-assistant.bold_agent_max_plan_entries' => 100,
        ]);

        $this->assertFalse(EntryCreationPolicy::appliesTo(isSuper: true));
        $this->assertSame(100, EntryCreationPolicy::maxPlanEntries(isSuper: true));
    }

    public function test_disabling_the_limit_lets_non_super_users_run_batches(): void
    {
        config([
            'statamic-ai-assistant.editor_limit_entries' => false,
            'statamic-ai-assistant.bold_agent_max_plan_entries' => 42,
        ]);

        $this->assertFalse(EntryCreationPolicy::appliesTo(isSuper: false));
        $this->assertSame(42, EntryCreationPolicy::maxPlanEntries(isSuper: false));
    }

    public function test_configured_cap_is_clamped_to_the_sane_ceiling(): void
    {
        config(['statamic-ai-assistant.bold_agent_max_plan_entries' => 100000]);
        $this->assertSame(500, EntryCreationPolicy::configuredMaxPlanEntries());

        config(['statamic-ai-assistant.bold_agent_max_plan_entries' => 0]);
        $this->assertSame(1, EntryCreationPolicy::configuredMaxPlanEntries());
    }
}
