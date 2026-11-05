<?php

namespace MotionWork\StatamicAiAssistant\Tests\Usage;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MotionWork\StatamicAiAssistant\Jobs\GeneratePlannedEntryJob;
use MotionWork\StatamicAiAssistant\Jobs\PlanEntriesJob;
use MotionWork\StatamicAiAssistant\Jobs\TranslateEntryJob;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\OpenRouterService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Usage\UsageAttribution;
use Statamic\Facades\User;

/**
 * Queued jobs have no signed-in user: they capture it at dispatch and run
 * their AI calls attributed to it, in the usage log and towards OpenRouter.
 */
class UsageAttributionTest extends TestCase
{
    use UsageTestDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->useTempUsageDirectory();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'statamic-ai-assistant.openrouter_api_key' => 'sk-or-test',
            'statamic-ai-assistant.openrouter_profiles' => [
                'agent' => ['models' => 'strong/a'],
                'light' => ['models' => 'cheap/a'],
                'field' => ['models' => 'cheap/a'],
                'translate' => ['models' => 'cheap/a'],
                'vision' => ['models' => 'cheap/a'],
            ],
        ]);
        $this->app->instance(AbstractAiService::class, new OpenRouterService);
    }

    protected function tearDown(): void
    {
        User::find('usage-owner')?->delete();
        $this->removeTempUsageDirectory();
        parent::tearDown();
    }

    public function test_calls_inside_run_are_logged_for_the_user_and_sent_with_their_hash(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response([
            'id' => 'gen-1',
            'model' => 'strong/a',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi']]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1, 'cost' => 0.0001],
        ])]);

        UsageAttribution::run('usage-owner', ['agent_session_id' => 'sess-9'], function () {
            app(AbstractAiService::class)->generateFromMessages([['role' => 'user', 'content' => 'Hello']]);
        });

        Http::assertSent(fn (Request $request) => $request['user'] === OpenRouterService::userHashFor('usage-owner'));
        $record = $this->loggedRecords()[0];
        $this->assertSame('usage-owner', $record['user_id']);
        $this->assertSame('sess-9', $record['agent_session_id']);
    }

    public function test_jobs_capture_the_dispatching_user_and_the_planner_remembers_the_session_owner(): void
    {
        $owner = User::make()->id('usage-owner')->email('owner@example.com');
        $owner->save();
        $this->actingAs($owner);

        $this->assertSame('usage-owner', (new PlanEntriesJob('sess-1'))->userId);
        $this->assertSame('usage-owner', (new GeneratePlannedEntryJob('sess-1', 'row-1'))->userId);
        $this->assertSame('usage-owner', (new TranslateEntryJob('e', 'en', 'de', false, 'b', 1))->userId);

        UsageAttribution::rememberSessionOwner('sess-1', 'usage-owner');
        $this->assertSame('usage-owner', UsageAttribution::sessionOwner('sess-1'));
        $this->assertNull(UsageAttribution::sessionOwner('sess-unknown'));
    }

    public function test_a_job_queued_before_the_upgrade_has_no_user_and_still_runs(): void
    {
        // unserialize() skips the constructor, like a job queued by an older
        // addon version: the property default (null) applies.
        $job = (new \ReflectionClass(GeneratePlannedEntryJob::class))->newInstanceWithoutConstructor();

        $this->assertNull($job->userId);
    }
}
