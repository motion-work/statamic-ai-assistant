<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\MateoService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\User;

/**
 * mateo's gateway: the site's own token, the task in the model field, the control
 * panel user as a header, and readable errors for a used-up budget.
 */
class MateoServiceTest extends TestCase
{
    /** @var array<int, Request> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic-ai-assistant.provider_name' => 'mateo',
            'statamic-ai-assistant.mateo_gateway_url' => 'https://mateoo.ch/api/sites/ai/v1/',
            'statamic-ai-assistant.mateo_gateway_token' => 'site-token',
        ]);
    }

    private function answer(int $status = 200): void
    {
        Http::fake(function (Request $request) use ($status) {
            $this->requests[] = $request;

            return $status === 200
                ? Http::response(['id' => 'msg_1', 'model' => 'claude-opus-5-5', 'choices' => [['message' => ['content' => 'Antwort'], 'finish_reason' => 'stop']], 'usage' => ['total_tokens' => 7]])
                : Http::response(['error' => ['message' => 'The AI budget for this month is used up.']], $status);
        });
    }

    public function test_mateo_is_picked_only_by_name_and_needs_its_token(): void
    {
        $this->assertSame(AiProviders::MATEO, AiProviders::active());
        $this->assertInstanceOf(MateoService::class, AiProviders::make());
        $this->assertTrue(AiProviders::hasCredentials());

        config(['statamic-ai-assistant.mateo_gateway_token' => null]);
        $this->assertFalse(AiProviders::hasCredentials());

        config(['statamic-ai-assistant.provider_name' => 'auto', 'statamic-ai-assistant.mateo_gateway_token' => 'site-token']);
        $this->assertNotSame(AiProviders::MATEO, AiProviders::active());
    }

    public function test_a_call_names_its_task_and_its_user(): void
    {
        $this->answer();
        $user = tap(User::make()->email('editor@example.com'))->save();
        $this->actingAs($user);
        $service = new MateoService;

        $this->assertSame('Antwort', $service->forTask(AiTask::Field, fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Kürzer.']])));

        $request = end($this->requests);
        $this->assertSame('https://mateoo.ch/api/sites/ai/v1/chat/completions', $request->url());
        $this->assertSame(['Bearer site-token'], $request->header('Authorization'));
        $this->assertSame(['editor@example.com'], $request->header('X-Mateo-User'));
        $this->assertSame('mateo-field', $request->data()['model']);
    }

    public function test_a_used_up_budget_says_so(): void
    {
        $this->answer(402);
        $service = new MateoService;

        $this->expectExceptionMessage('The AI budget for this month is used up.');
        $service->generateFromMessages([['role' => 'user', 'content' => 'Hallo']]);
    }
}
