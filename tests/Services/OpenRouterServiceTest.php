<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviderException;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\OpenRouterService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\User;

/**
 * What OpenRouterService sends: the task profile's models and reasoning,
 * provider preferences, attribution headers and the hashed editor id, and how
 * it reports usage and errors.
 */
class OpenRouterServiceTest extends TestCase
{
    /** @var array<int, Request> */
    private array $requests = [];

    private OpenRouterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://client.example',
            'app.name' => 'Client Site',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'statamic-ai-assistant.openrouter_api_key' => 'sk-or-test',
            'statamic-ai-assistant.openrouter_app_title' => null,
            'statamic-ai-assistant.quick_tasks_without_thinking' => true,
            'statamic-ai-assistant.ai_http_retry_times' => 3,
            'statamic-ai-assistant.openrouter_profiles' => [
                'agent' => ['models' => 'strong/a, strong/b', 'reasoning' => 'medium'],
                'light' => ['models' => ['cheap/a'], 'reasoning' => 'none', 'max_tokens' => 300],
                'field' => ['models' => 'cheap/field'],
                'translate' => ['models' => 'cheap/translate', 'preset' => 'translate-preset'],
                'vision' => ['models' => '', 'preset' => ''],
            ],
            'statamic-ai-assistant.openrouter_provider' => [
                'data_collection' => 'deny',
                'zdr' => true,
                'only' => 'anthropic, openai',
                'ignore' => '',
                'sort' => 'latency',
            ],
        ]);

        $this->fakeReply();
        $this->service = new OpenRouterService;
    }

    /** @var array<string, mixed> */
    private array $replyBody = [];

    private int $replyStatus = 200;

    /**
     * Set the reply of the faked endpoint (faked once; Laravel keeps the first
     * fake that matches).
     *
     * @param  array<string, mixed>  $body
     */
    private function fakeReply(array $body = [], int $status = 200): void
    {
        $this->replyBody = $body ?: [
            'id' => 'gen-123',
            'model' => 'strong/a',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Antwort'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'cost' => 0.0021],
        ];
        $this->replyStatus = $status;

        if ($this->faked) {
            return;
        }

        $this->faked = true;
        Http::fake(function (Request $request) {
            $this->requests[] = $request;

            return Http::response($this->replyBody, $this->replyStatus);
        });
    }

    private bool $faked = false;

    private function lastRequest(): Request
    {
        $this->assertNotEmpty($this->requests, 'expected an OpenRouter request');

        return end($this->requests);
    }

    /** @return array<string, mixed> */
    private function lastPayload(): array
    {
        return $this->lastRequest()->data();
    }

    public function test_agent_calls_send_the_model_fallbacks_reasoning_and_provider_preferences(): void
    {
        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']], 4000);

        $request = $this->lastRequest();
        $payload = $request->data();

        $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $request->url());
        $this->assertSame('strong/a', $payload['model']);
        $this->assertSame(['strong/a', 'strong/b'], $payload['models']);
        $this->assertSame(['effort' => 'medium'], $payload['reasoning']);
        $this->assertSame(4000, $payload['max_tokens']);
        $this->assertSame([
            'data_collection' => 'deny',
            'zdr' => true,
            'only' => ['anthropic', 'openai'],
            'sort' => 'latency',
        ], $payload['provider']);
        $this->assertSame(['Bearer sk-or-test'], $request->header('Authorization'));
        $this->assertSame(['https://client.example'], $request->header('HTTP-Referer'));
        $this->assertSame(['Client Site'], $request->header('X-OpenRouter-Title'));
        $this->assertArrayNotHasKey('user', $payload, 'no signed-in editor, no user id');
    }

    public function test_quick_tasks_use_their_profile_and_switch_reasoning_off(): void
    {
        $this->service->forTask(AiTask::Light, fn () => $this->service->generateFromMessages([['role' => 'user', 'content' => 'Hint?']]));
        $payload = $this->lastPayload();
        $this->assertSame('cheap/a', $payload['model']);
        $this->assertArrayNotHasKey('models', $payload, 'one model needs no fallback list');
        $this->assertSame(['effort' => 'none'], $payload['reasoning']);
        $this->assertSame(300, $payload['max_tokens'], 'the profile budget applies when the caller sets none');

        $this->service->generateContentFromPrompt('Zwei Sätze über Eichentische.');
        $this->assertSame('cheap/field', $this->lastPayload()['model']);
        $this->assertSame(['effort' => 'none'], $this->lastPayload()['reasoning']);
    }

    public function test_a_preset_is_sent_next_to_the_models_or_on_its_own(): void
    {
        $this->service->forTask(AiTask::Translate, fn () => $this->service->generateFromMessages([['role' => 'user', 'content' => 'Übersetze.']]));
        $this->assertSame('cheap/translate', $this->lastPayload()['model']);
        $this->assertSame('@preset/translate-preset', $this->lastPayload()['preset']);

        config(['statamic-ai-assistant.openrouter_profiles.translate' => ['models' => [], 'preset' => '@preset/only-preset']]);
        $this->service->forTask(AiTask::Translate, fn () => $this->service->generateFromMessages([['role' => 'user', 'content' => 'Übersetze.']]));
        $this->assertSame('@preset/only-preset', $this->lastPayload()['model']);
        $this->assertArrayNotHasKey('preset', $this->lastPayload());
    }

    public function test_the_task_is_restored_after_the_closure_even_when_it_throws(): void
    {
        $this->assertSame(AiTask::Agent, $this->service->currentTask());

        $inner = $this->service->forTask(AiTask::Light, fn () => $this->service->currentTask());
        $this->assertSame(AiTask::Light, $inner);
        $this->assertSame(AiTask::Agent, $this->service->currentTask());

        try {
            $this->service->forTask(AiTask::Field, function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(AiTask::Agent, $this->service->currentTask());
        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
        $this->assertSame(['effort' => 'medium'], $this->lastPayload()['reasoning'], 'thinking is back on');
    }

    public function test_using_fast_model_is_the_light_task(): void
    {
        $this->service->usingFastModel(fn () => $this->service->generateFromMessages([['role' => 'user', 'content' => 'Hint?']], 256));

        $this->assertSame('cheap/a', $this->lastPayload()['model']);
        $this->assertSame(256, $this->lastPayload()['max_tokens'], 'an explicit budget wins over the profile');
        $this->assertNull(config('statamic-ai-assistant.openrouter_profiles.light.model'), 'no config is swapped');
    }

    public function test_tool_calls_do_not_require_parameters_but_structured_output_does(): void
    {
        $tools = [['type' => 'function', 'function' => ['name' => 'create_entry_job', 'parameters' => ['type' => 'object']]]];

        $this->service->createChatCompletion([['role' => 'user', 'content' => 'Plan.']], 2000, $tools, 'required');
        $payload = $this->lastPayload();
        $this->assertSame('required', $payload['tool_choice']);
        $this->assertArrayNotHasKey('require_parameters', $payload['provider']);

        $schema = ['type' => 'json_schema', 'json_schema' => ['name' => 'entry', 'strict' => true, 'schema' => ['type' => 'object']]];
        $this->service->withRequestOptions(['response_format' => $schema, 'session_id' => 'agent-1'], fn () => $this->service->generateFromMessages([['role' => 'user', 'content' => 'Entry.']]));
        $payload = $this->lastPayload();
        $this->assertSame($schema, $payload['response_format']);
        $this->assertSame('agent-1', $payload['session_id']);
        $this->assertTrue($payload['provider']['require_parameters']);

        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Again.']]);
        $this->assertArrayNotHasKey('session_id', $this->lastPayload(), 'request options end with the closure');
    }

    public function test_a_nested_call_for_another_task_does_not_inherit_the_response_format(): void
    {
        $schema = ['type' => 'json_schema', 'json_schema' => ['name' => 'entry', 'strict' => false, 'schema' => ['type' => 'object']]];

        $this->service->withRequestOptions(['response_format' => $schema, 'session_id' => 'agent-1'], function () use ($schema) {
            // e.g. alt text written by a tool while the entry is generated
            $this->service->forTask(AiTask::Field, fn () => $this->service->generateFromMessages([['role' => 'user', 'content' => 'Alt text.']]));
            $nested = $this->lastPayload();
            $this->assertArrayNotHasKey('response_format', $nested);
            $this->assertSame('agent-1', $nested['session_id'], 'other request options still apply');

            $this->service->generateFromMessages([['role' => 'user', 'content' => 'Entry.']]);
            $this->assertSame($schema, $this->lastPayload()['response_format'], 'restored for the outer call');
        });
    }

    public function test_request_options_cannot_replace_the_messages(): void
    {
        $this->service->withRequestOptions(['messages' => [], 'stream' => true], fn () => $this->service->generateFromMessages([['role' => 'user', 'content' => 'Keep me.']]));

        $this->assertSame('Keep me.', $this->lastPayload()['messages'][0]['content']);
        $this->assertFalse($this->lastPayload()['stream']);
    }

    public function test_the_signed_in_editor_is_sent_as_a_stable_hash(): void
    {
        $user = User::make()->id('editor-42')->email('editor@example.com');
        $user->save();

        try {
            $this->actingAs($user);

            $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
            $first = $this->lastPayload()['user'];
            $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);

            $this->assertSame($first, $this->lastPayload()['user']);
            $this->assertSame(32, strlen($first));
            $this->assertStringNotContainsString('editor', $first);
        } finally {
            $user->delete();
        }
    }

    public function test_a_completion_event_carries_task_models_usage_and_context(): void
    {
        Event::fake([AiCompletionFinished::class]);

        $this->service->withCompletionContext(['agent_session_id' => 's-1'], fn () => $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]));

        Event::assertDispatched(AiCompletionFinished::class, function (AiCompletionFinished $event) {
            return $event->provider === 'openrouter'
                && $event->task === AiTask::Agent
                && $event->requestedModels === ['strong/a', 'strong/b']
                && $event->model === 'strong/a'
                && $event->generationId === 'gen-123'
                && $event->cost() === 0.0021
                && $event->usage['total_tokens'] === 15
                && $event->streamed === false
                && $event->context === ['agent_session_id' => 's-1']
                && ! $event->usedFallback();
        });
    }

    public function test_a_fallback_model_answering_is_reported(): void
    {
        Event::fake([AiCompletionFinished::class]);
        $this->fakeReply([
            'id' => 'gen-9',
            'model' => 'strong/b',
            'choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']],
        ]);

        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);

        Event::assertDispatched(AiCompletionFinished::class, fn (AiCompletionFinished $e) => $e->model === 'strong/b' && $e->usedFallback());
    }

    public function test_vision_needs_a_vision_profile(): void
    {
        $this->assertFalse($this->service->supportsVision());

        config(['statamic-ai-assistant.openrouter_profiles.vision' => ['models' => 'vision/a']]);

        $this->assertTrue($this->service->supportsVision());
        $this->assertSame('Antwort', $this->service->describeImage('data:image/png;base64,AAAA', 'Beschreibe.'));

        $payload = $this->lastPayload();
        $this->assertSame('vision/a', $payload['model']);
        $this->assertSame(['effort' => 'none'], $payload['reasoning']);
        $this->assertSame('image_url', $payload['messages'][0]['content'][1]['type']);
    }

    public function test_an_empty_reply_cut_off_by_the_budget_says_so(): void
    {
        $this->fakeReply(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']]]);

        $this->expectExceptionMessage('whole output budget');

        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
    }

    public function test_a_200_carrying_only_an_error_is_a_failure(): void
    {
        $this->fakeReply(['error' => ['code' => 502, 'message' => 'upstream died']]);

        $this->expectExceptionMessage('upstream died');

        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
    }

    public function test_out_of_credits_is_readable_and_not_retried(): void
    {
        $this->fakeReply(['error' => ['code' => 402, 'message' => 'Insufficient credits', 'metadata' => ['limit_source' => 'openrouter_credits']]], 402);

        try {
            $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
            $this->fail('expected an exception');
        } catch (AiProviderException $e) {
            $this->assertSame(402, $e->status);
            $this->assertTrue($e->isBudgetLimit());
            $this->assertStringContainsString('out of credits', $e->getMessage());
        }

        $this->assertCount(1, $this->requests, '402 is never retried');
    }

    public function test_a_used_up_budget_names_the_reset_and_is_not_retried(): void
    {
        $this->fakeReply(['error' => ['code' => 403, 'message' => 'Budget exceeded', 'metadata' => [
            'limit_source' => 'openrouter_key_limit',
            'resets_at' => '2026-12-01T00:00:00Z',
        ]]], 403);

        try {
            $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
            $this->fail('expected an exception');
        } catch (AiProviderException $e) {
            $this->assertSame('openrouter_key_limit', $e->limitSource);
            $this->assertStringContainsString('budget for this site is used up', $e->getMessage());
            $this->assertStringContainsString('2026', $e->getMessage());
        }

        $this->assertCount(1, $this->requests, '403 is never retried');
    }

    public function test_other_statuses_get_their_own_message(): void
    {
        $cases = [
            401 => 'invalid or disabled',
            404 => 'No AI model can serve this request',
            503 => 'No AI provider can take this request',
        ];

        config(['statamic-ai-assistant.ai_http_retry_times' => 1]);

        foreach ($cases as $status => $expected) {
            $this->fakeReply(['error' => ['code' => $status, 'message' => 'nope']], $status);

            try {
                $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
                $this->fail("expected an exception for {$status}");
            } catch (AiProviderException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), "status {$status}");
            }
        }
    }

    public function test_moderation_blocks_are_named_as_such(): void
    {
        $this->fakeReply(['error' => ['code' => 403, 'message' => 'flagged', 'metadata' => ['reasons' => ['violence']]]], 403);

        $this->expectExceptionMessage('content moderation');

        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
    }

    public function test_a_missing_key_is_reported(): void
    {
        config(['statamic-ai-assistant.openrouter_api_key' => '']);

        $this->expectExceptionMessage('OPENROUTER_API_KEY is not configured.');

        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
    }
}
