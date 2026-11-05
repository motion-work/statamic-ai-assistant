<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\InfomaniakService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * Infomaniak on the shared client: same endpoint and models as before,
 * request options limited to what it supports, and the completion event for
 * every call.
 */
class OpenAiCompatibleProvidersTest extends TestCase
{
    /** @var array<int, Request> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic-ai-assistant.infomaniak_product_id' => '123',
            'statamic-ai-assistant.infomaniak_api_token' => 'token',
            'statamic-ai-assistant.infomaniak_model' => 'strong-model',
            'statamic-ai-assistant.infomaniak_model_fast' => 'fast-model',
        ]);

        Http::fake(function (Request $request) {
            $this->requests[] = $request;

            return Http::response([
                'id' => 'chatcmpl-1',
                'model' => 'strong-model',
                'choices' => [['message' => ['content' => 'Antwort'], 'finish_reason' => 'stop']],
                'usage' => ['total_tokens' => 7],
            ]);
        });
    }

    private function lastRequest(): Request
    {
        $this->assertNotEmpty($this->requests);

        return end($this->requests);
    }

    public function test_infomaniak_keeps_its_endpoint_and_task_models(): void
    {
        $service = new InfomaniakService;

        $service->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
        $this->assertSame('https://api.infomaniak.com/2/ai/123/openai/v1/chat/completions', $this->lastRequest()->url());
        $this->assertSame(['Bearer token'], $this->lastRequest()->header('Authorization'));
        $this->assertSame('strong-model', $this->lastRequest()->data()['model']);

        $service->forTask(AiTask::Light, fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Hint?']]));
        $this->assertSame('fast-model', $this->lastRequest()->data()['model']);

        $service->forTask(AiTask::Field, fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Text.']]));
        $this->assertSame('strong-model', $this->lastRequest()->data()['model'], 'field calls keep the primary model, as before');
    }

    public function test_infomaniak_keeps_response_format_but_drops_openrouter_only_options(): void
    {
        $service = new InfomaniakService;
        $format = ['type' => 'json_object'];

        $service->withRequestOptions(['response_format' => $format, 'session_id' => 's-1', 'plugins' => []], fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'JSON.']]));

        $payload = $this->lastRequest()->data();
        $this->assertSame($format, $payload['response_format']);
        $this->assertArrayNotHasKey('session_id', $payload);
        $this->assertArrayNotHasKey('plugins', $payload);
        $this->assertArrayNotHasKey('provider', $payload);
    }

    public function test_plain_and_tool_completions_are_reported(): void
    {
        Event::fake([AiCompletionFinished::class]);

        (new InfomaniakService)->generateFromMessages([['role' => 'user', 'content' => 'Plan.']]);
        (new InfomaniakService)->createChatCompletion([['role' => 'user', 'content' => 'Plan.']], 100);

        Event::assertDispatched(AiCompletionFinished::class, fn (AiCompletionFinished $e) => $e->provider === 'infomaniak'
            && $e->requestedModels === ['strong-model']
            && $e->generationId === 'chatcmpl-1'
            && $e->usage === ['total_tokens' => 7]
            && $e->cost() === null);
        Event::assertDispatchedTimes(AiCompletionFinished::class, 2);
    }
}
