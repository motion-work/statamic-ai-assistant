<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

class AgentAccessStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/sai-access-store-'.uniqid().'.yaml';
        config(['statamic-ai-assistant.access_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function store(): AgentAccessStore
    {
        return new AgentAccessStore;
    }

    public function test_missing_file_returns_empty_default_deny_shape(): void
    {
        $store = $this->store();

        foreach (AgentAccessStore::FEATURES as $feature) {
            $this->assertSame(['roles' => [], 'users' => []], $store->feature($feature));
        }
        $this->assertSame(['default' => 1, 'roles' => [], 'users' => []], $store->agentLimits());
    }

    public function test_save_round_trips_and_normalises(): void
    {
        $this->store()->save([
            'agent' => [
                'roles' => ['editor', 'editor', ' '],   // dedupe + drop blanks
                'users' => ['u1'],
                'limits' => ['default' => 2, 'roles' => ['editor' => 3, 'x' => 0], 'users' => ['u1' => 9]],
            ],
            'bulk_translations' => ['roles' => ['translator'], 'users' => []],
            // agent_settings omitted → should be present + empty after normalise.
        ]);

        $fresh = $this->store(); // re-read from disk (no cache)
        $this->assertSame(['editor'], $fresh->feature('agent')['roles']);
        $this->assertSame(['u1'], $fresh->feature('agent')['users']);
        $this->assertSame(['translator'], $fresh->feature('bulk_translations')['roles']);
        $this->assertSame(['roles' => [], 'users' => []], $fresh->feature('agent_settings'));

        // Limits: positive ints only (x=0 dropped), default kept.
        $this->assertSame(['default' => 2, 'roles' => ['editor' => 3], 'users' => ['u1' => 9]], $fresh->agentLimits());
    }
}
