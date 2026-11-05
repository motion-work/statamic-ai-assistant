<?php

namespace MotionWork\StatamicAiAssistant\Tests\Http;

use Illuminate\Support\Facades\Queue;
use MotionWork\StatamicAiAssistant\Jobs\BuildEmbeddingIndexJob;
use MotionWork\StatamicAiAssistant\Semantic\SemanticSearch;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Tests\Semantic\FakesEmbeddings;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\User;

/**
 * The "Semantic search" card on the AI usage page: status per site and
 * "Rebuild index", for super admins only.
 */
class SemanticSearchStatusTest extends TestCase
{
    use FakesEmbeddings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSemanticSearch();
        config(['statamic.editions.pro' => true]);
    }

    protected function tearDown(): void
    {
        foreach (['semantic-super', 'semantic-editor'] as $id) {
            User::find($id)?->delete();
        }
        Collection::find('semantic_status')?->delete();
        $this->tearDownSemanticSearch();
        parent::tearDown();
    }

    public function test_super_admins_see_the_status_and_queue_a_rebuild(): void
    {
        Queue::fake();
        $this->actingAs($this->user('semantic-super', true));

        $this->getJson('/cp/ai-usage/semantic-search')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('model', 'openai/text-embedding-3-small')
            ->assertJsonPath('sites.0.reason', 'no_entries')
            ->assertJsonPath('sites.0.indexed', 0);

        Collection::make('semantic_status')->title('Status')->sites([Site::default()->handle()])->save();
        Entry::make()->collection('semantic_status')->locale(Site::default()->handle())->slug('one')->data(['title' => 'One'])->saveQuietly();

        $this->getJson('/cp/ai-usage/semantic-search')
            ->assertJsonPath('sites.0.reason', 'not_built')
            ->assertJsonPath('sites.0.entries', 1);

        $this->postJson('/cp/ai-usage/semantic-search/rebuild')->assertOk()->assertJsonPath('queued', true);
        Queue::assertPushed(BuildEmbeddingIndexJob::class);
    }

    public function test_the_status_says_why_it_is_unavailable_and_refuses_a_rebuild(): void
    {
        Queue::fake();
        config(['statamic-ai-assistant.provider_name' => 'infomaniak', 'statamic-ai-assistant.infomaniak_api_token' => 'token']);
        $this->app->forgetInstance(AbstractAiService::class);
        $this->app->forgetInstance(SemanticSearch::class);
        $this->actingAs($this->user('semantic-super', true));

        $this->getJson('/cp/ai-usage/semantic-search')->assertOk()->assertJsonPath('available', false)->assertJsonPath('reason', 'provider');
        $this->postJson('/cp/ai-usage/semantic-search/rebuild')->assertStatus(422);
        Queue::assertNotPushed(BuildEmbeddingIndexJob::class);
    }

    public function test_other_users_cannot_reach_it(): void
    {
        $this->actingAs($this->user('semantic-editor', false));

        $this->getJson('/cp/ai-usage/semantic-search')->assertForbidden();
        $this->postJson('/cp/ai-usage/semantic-search/rebuild')->assertForbidden();
    }

    private function user(string $id, bool $super): \Statamic\Contracts\Auth\User
    {
        $user = User::make()->id($id)->email($id.'@example.com');
        if ($super) {
            $user->makeSuper();
        }
        $user->save();

        return $user;
    }
}
