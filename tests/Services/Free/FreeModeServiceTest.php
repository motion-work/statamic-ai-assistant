<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services\Free;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response as ClientResponse;
use MotionWork\StatamicAiAssistant\Semantic\SemanticSearch;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviderException;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\Ai\Free\FreeModelFeedback;
use MotionWork\StatamicAiAssistant\Services\Ai\Free\FreeModelHealth;
use MotionWork\StatamicAiAssistant\Services\Ai\Free\FreeModelPicker;
use MotionWork\StatamicAiAssistant\Services\Ai\OpenRouterErrors;
use MotionWork\StatamicAiAssistant\Services\OpenRouterService;
use MotionWork\StatamicAiAssistant\Support\FreeModels;
use MotionWork\StatamicAiAssistant\Support\ImageGeneration;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * Free mode on the OpenRouter service: the picker's models replace the
 * profiles, privacy and token budget follow, failures teach the health record,
 * and image generation and semantic search say why they are off.
 */
class FreeModeServiceTest extends TestCase
{
    use FakesFreeOpenRouter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFreeOpenRouter();
        config([
            'statamic-ai-assistant.openrouter_profiles' => [
                'agent' => ['models' => 'paid/strong', 'reasoning' => 'medium', 'preset' => 'agent-preset'],
                'field' => ['models' => 'paid/cheap', 'max_tokens' => 300],
                'image' => ['models' => 'flux/klein'],
                'embedding' => ['models' => 'openai/text-embedding-3-small', 'dimensions' => 512],
            ],
            'statamic-ai-assistant.openrouter_provider' => ['data_collection' => 'deny'],
        ]);
    }

    public function test_agent_calls_use_the_free_models_without_the_preset(): void
    {
        (new OpenRouterService)->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);

        $payload = $this->chatPayloads()[0];
        $this->assertSame('nvidia/super-vl:free', $payload['model']);
        $this->assertSame(['nvidia/super-vl:free', 'cohere/mini:free', FreeModelPicker::ROUTER], $payload['models']);
        $this->assertArrayNotHasKey('preset', $payload);
        $this->assertSame(['effort' => 'medium'], $payload['reasoning'], 'the profile reasoning is kept');
        $this->assertSame('deny', $payload['provider']['data_collection']);
    }

    public function test_allowing_training_sends_data_collection_allow(): void
    {
        config(['statamic-ai-assistant.openrouter_free_models.allow_training' => true]);

        (new OpenRouterService)->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);

        $this->assertSame('allow', $this->chatPayloads()[0]['provider']['data_collection']);
    }

    public function test_quick_tasks_get_room_to_reason_and_skip_it_only_when_the_model_can(): void
    {
        $service = new OpenRouterService;
        $service->forTask(AiTask::Field, fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Kurz.']], 200));

        $payload = $this->chatPayloads()[0];
        $this->assertSame(3000, $payload['max_tokens'], 'raised to min_max_tokens');
        $this->assertSame(['effort' => 'none'], $payload['reasoning'], 'super-vl can answer without reasoning');

        app(FreeModelHealth::class)->busy('nvidia/super-vl:free');
        app(FreeModelHealth::class)->busy('google/gemma:free');
        app(FreeModelHealth::class)->busy('cohere/mini:free');
        $service->forTask(AiTask::Field, fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Kurz.']]));

        $this->assertSame('nvidia/ultra:free', $this->chatPayloads()[1]['model']);
        $this->assertArrayNotHasKey('reasoning', $this->chatPayloads()[1], 'ultra must reason, so effort none is not sent');
    }

    public function test_free_mode_off_restores_the_profiles(): void
    {
        config(['statamic-ai-assistant.openrouter_free_models.enabled' => false]);

        (new OpenRouterService)->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);

        $this->assertSame('paid/strong', $this->chatPayloads()[0]['model']);
        $this->assertSame('@preset/agent-preset', $this->chatPayloads()[0]['preset']);
    }

    public function test_a_fallback_answer_moves_the_skipped_models_down(): void
    {
        $this->chatReply['model'] = 'cohere/mini:free';

        (new OpenRouterService)->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);

        $health = app(FreeModelHealth::class);
        $this->assertFalse($health->usable('nvidia/super-vl:free', FreeModels::DENY), 'passed over by OpenRouter');
        $this->assertTrue($health->usable('cohere/mini:free', FreeModels::DENY));
    }

    public function test_busy_free_models_get_a_plain_message_and_are_moved_down(): void
    {
        $this->chatStatus = 429;
        $this->chatReply = ['error' => ['code' => 429, 'message' => 'Provider returned error']];

        try {
            (new OpenRouterService)->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
            $this->fail('expected an exception');
        } catch (AiProviderException $e) {
            $this->assertSame(__('The free models are busy right now. Try again in a moment.'), $e->getMessage());
        }

        $this->assertFalse(app(FreeModelHealth::class)->usable('nvidia/super-vl:free', FreeModels::DENY));
    }

    public function test_the_daily_limit_and_privacy_blocks_have_their_own_messages(): void
    {
        $daily = OpenRouterErrors::exception($this->response(429, 'Rate limit exceeded: free-models-per-day. Add 10 credits to unlock 1000 free model requests per day'), true);
        $this->assertSame(__('The daily limit for free models is reached. It continues tomorrow, or switch the free models off.'), $daily->getMessage());

        $policy = OpenRouterErrors::exception($this->response(404, 'No endpoints found matching your data policy (Free model training).'), true);
        $this->assertStringContainsString(__('No free model can take this request with the current privacy setting.'), $policy->getMessage());

        $paid = OpenRouterErrors::exception($this->response(429, 'Rate limit exceeded'), false);
        $this->assertStringNotContainsString('free', strtolower($paid->getMessage()));
    }

    public function test_a_privacy_block_is_remembered_for_that_policy(): void
    {
        app(FreeModelFeedback::class)->failed(
            ['nvidia/super-vl:free', FreeModelPicker::ROUTER],
            $this->response(404, 'No endpoints found matching your data policy (Free model training).'),
            FreeModels::DENY,
        );

        $health = app(FreeModelHealth::class);
        $this->assertFalse($health->usable('nvidia/super-vl:free', FreeModels::DENY));
        $this->assertTrue($health->usable('nvidia/super-vl:free', FreeModels::ALLOW));
        $this->assertTrue($health->usable(FreeModelPicker::ROUTER, FreeModels::DENY), 'the router is never demoted');
    }

    public function test_image_generation_is_off_and_says_why(): void
    {
        $this->app->forgetInstance(AbstractAiService::class);

        $this->assertFalse(ImageGeneration::available());
        $this->assertSame(__('Image generation is off while only free models are used: OpenRouter has no free image models.'), ImageGeneration::unavailableReason());
    }

    public function test_semantic_search_needs_training_and_then_switches_to_a_free_embedding_model(): void
    {
        $this->app->forgetInstance(AbstractAiService::class);
        $this->assertSame(SemanticSearch::FREE_TRAINING, app(SemanticSearch::class)->providerReason());

        config(['statamic-ai-assistant.openrouter_free_models.allow_training' => true]);
        $this->assertNull(app(SemanticSearch::class)->providerReason());
        $this->assertSame('nvidia/embed-8b:free', (new OpenRouterService)->embeddingModel(), 'a model change makes the index outdated until rebuilt');
    }

    private function response(int $status, string $message): ClientResponse
    {
        return new ClientResponse(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode(['error' => ['code' => $status, 'message' => $message]])));
    }
}
