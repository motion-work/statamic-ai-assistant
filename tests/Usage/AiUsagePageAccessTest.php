<?php

namespace MotionWork\StatamicAiAssistant\Tests\Usage;

use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Usage\UsageLog;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The "AI usage" page is for super admins only.
 */
class AiUsagePageAccessTest extends TestCase
{
    use UsageTestDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->useTempUsageDirectory();
        config([
            'statamic.editions.pro' => true,
            'statamic-ai-assistant.provider_name' => 'infomaniak',
            'statamic-ai-assistant.infomaniak_api_token' => 'token',
        ]);
        Role::make('usage_editor')->permissions(['access cp'])->save();
    }

    protected function tearDown(): void
    {
        foreach (['usage-editor', 'usage-super'] as $id) {
            User::find($id)?->delete();
        }
        Role::find('usage_editor')?->delete();
        $this->removeTempUsageDirectory();
        parent::tearDown();
    }

    public function test_super_admins_see_the_month_with_names_instead_of_ids(): void
    {
        $super = User::make()->id('usage-super')->email('super@example.com')->set('name', 'Sam Super')->makeSuper();
        $super->save();
        (new UsageLog)->append([
            'ts' => now()->toIso8601String(),
            'provider' => 'infomaniak',
            'task' => 'agent',
            'model' => 'mistral24b',
            'cost' => null,
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'user_id' => 'usage-super',
        ]);

        $this->actingAs($super);
        $this->get('/cp/ai-usage')->assertOk();
        $this->getJson('/cp/ai-usage/data')
            ->assertOk()
            ->assertJsonPath('month', now()->format('Y-m'))
            ->assertJsonPath('summary.totals.calls', 1)
            ->assertJsonPath('users.usage-super', 'Sam Super')
            ->assertJsonPath('budget', null);

        $this->getJson('/cp/ai-usage/data?month=../../etc')
            ->assertJsonPath('month', now()->format('Y-m'));
    }

    public function test_other_cp_users_are_refused(): void
    {
        $editor = User::make()->id('usage-editor')->email('editor@example.com')->assignRole('usage_editor');
        $editor->save();

        $this->actingAs($editor);
        // Statamic answers a forbidden CP page with a redirect, an XHR with 403.
        $this->get('/cp/ai-usage')->assertRedirect();
        $this->getJson('/cp/ai-usage/data')->assertForbidden();
    }
}
