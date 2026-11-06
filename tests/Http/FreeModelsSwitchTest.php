<?php

namespace MotionWork\StatamicAiAssistant\Tests\Http;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Support\FreeModels;
use MotionWork\StatamicAiAssistant\Tests\Services\Free\FakesFreeOpenRouter;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\User;

/**
 * The "Free models" card on the "Who has access" page: only super admins see
 * and change it, both switches are off until saved, config locks them, and the
 * page shows the free model each task would use.
 */
class FreeModelsSwitchTest extends TestCase
{
    use FakesFreeOpenRouter;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFreeOpenRouter();
        $this->path = sys_get_temp_dir().'/sai-free-switch-'.uniqid().'.yaml';
        config([
            'statamic.editions.pro' => true,
            'statamic-ai-assistant.access_path' => $this->path,
            'statamic-ai-assistant.openrouter_free_models.enabled' => null,
            'statamic-ai-assistant.openrouter_free_models.allow_training' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['free-editor', 'free-super'] as $id) {
            User::find($id)?->delete();
        }
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_super_admins_switch_free_models_on_and_see_the_picks(): void
    {
        $super = User::make()->id('free-super')->email('free-super@example.com')->makeSuper();
        $super->save();
        $this->actingAs($super);

        $this->assertFalse(FreeModels::enabled(), 'off until a super admin switches it on');
        $this->getJson('/cp/ai-agent-access/data')
            ->assertOk()
            ->assertJsonPath('freeModels.on', false)
            ->assertJsonPath('freeModels.training', false)
            ->assertJsonPath('freeModels.locked', false)
            ->assertJsonPath('freeModels.unavailable', null)
            ->assertJsonPath('freeModels.picks.agent', 'nvidia/super-vl:free');

        $this->postJson('/cp/ai-agent-access/save', ['access' => ['free_models' => true, 'free_models_training' => true]])->assertOk();

        $store = app(AgentAccessStore::class);
        $this->assertTrue($store->freeModels());
        $this->assertTrue($store->freeModelsTraining());
        $this->assertTrue(FreeModels::enabled());
        $this->assertSame(FreeModels::ALLOW, FreeModels::dataCollection());
        $this->getJson('/cp/ai-agent-access/data')
            ->assertJsonPath('freeModels.on', true)
            ->assertJsonPath('freeModels.picks.embedding', 'nvidia/embed-8b:free');
    }

    public function test_editors_cannot_change_the_switch(): void
    {
        $editor = User::make()->id('free-editor')->email('free-editor@example.com');
        $editor->save();
        $this->actingAs($editor);

        $this->getJson('/cp/ai-agent-access/data')->assertForbidden();
        $this->postJson('/cp/ai-agent-access/save', ['access' => ['free_models' => true]])->assertForbidden();
        $this->assertNull((new AgentAccessStore)->freeModels());
    }

    public function test_config_locks_the_switches(): void
    {
        (new AgentAccessStore)->save(['free_models' => true]);
        config(['statamic-ai-assistant.openrouter_free_models.enabled' => false]);

        $this->assertFalse(FreeModels::enabled());
        $this->assertFalse(FreeModels::configOverride('enabled'));
    }

    public function test_another_provider_makes_free_mode_unavailable(): void
    {
        config(['statamic-ai-assistant.provider_name' => 'infomaniak', 'statamic-ai-assistant.openrouter_free_models.enabled' => true]);

        $this->assertFalse(FreeModels::enabled());
        $this->assertNotNull(FreeModels::unavailableReason());
    }
}
