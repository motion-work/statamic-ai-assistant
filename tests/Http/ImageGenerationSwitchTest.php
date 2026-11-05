<?php

namespace MotionWork\StatamicAiAssistant\Tests\Http;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Support\ImageGeneration;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\User;

/**
 * The site-wide image generation switch on the "Who has access" page: only
 * super admins see and change it, it is off until switched on, and a config
 * value overrides it.
 */
class ImageGenerationSwitchTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/sai-image-switch-'.uniqid().'.yaml';
        config([
            'statamic.editions.pro' => true,
            'statamic-ai-assistant.access_path' => $this->path,
            'statamic-ai-assistant.provider_name' => 'auto',
            'statamic-ai-assistant.openrouter_api_key' => 'sk-or-test',
            'statamic-ai-assistant.openrouter_profiles.image' => ['models' => 'flux/klein'],
            'statamic-ai-assistant.image_generation.enabled' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['image-editor', 'image-super'] as $id) {
            User::find($id)?->delete();
        }
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_super_admins_switch_image_generation_on_for_the_site(): void
    {
        $super = User::make()->id('image-super')->email('super@example.com')->makeSuper();
        $super->save();
        $this->actingAs($super);

        $this->assertFalse(ImageGeneration::enabled(), 'off until a super admin switches it on');
        $this->getJson('/cp/ai-agent-access/data')
            ->assertOk()
            ->assertJsonPath('imageGeneration.on', false)
            ->assertJsonPath('imageGeneration.locked', false)
            ->assertJsonPath('imageGeneration.unavailable', null);

        $this->postJson('/cp/ai-agent-access/save', ['access' => ['image_generation' => true]])->assertOk();

        $this->assertTrue((new AgentAccessStore)->imageGeneration());
        $this->assertTrue(ImageGeneration::enabled());
        $this->getJson('/cp/ai-agent-access/data')->assertJsonPath('imageGeneration.on', true);
    }

    public function test_editors_cannot_change_the_switch(): void
    {
        $editor = User::make()->id('image-editor')->email('editor@example.com');
        $editor->save();
        $this->actingAs($editor);

        $this->getJson('/cp/ai-agent-access/data')->assertForbidden();
        $this->postJson('/cp/ai-agent-access/save', ['access' => ['image_generation' => true]])->assertForbidden();
        $this->assertNull((new AgentAccessStore)->imageGeneration());
    }

    public function test_config_overrides_the_switch(): void
    {
        (new AgentAccessStore)->save(['image_generation' => true]);
        config(['statamic-ai-assistant.image_generation.enabled' => false]);

        $this->assertFalse(ImageGeneration::enabled());
        $this->assertFalse(ImageGeneration::configOverride());
    }
}
