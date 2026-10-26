<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\InfomaniakService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class QuickTaskThinkingTest extends TestCase
{
    private const THINKING_OFF = ['thinking' => false, 'enable_thinking' => false];

    private InfomaniakService $service;

    /** @var array<int, array<string, mixed>> */
    private array $payloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic-ai-assistant.infomaniak_model' => 'moonshotai/Kimi-K2.6',
            'statamic-ai-assistant.infomaniak_model_fast' => 'Qwen/Qwen3.8-27B-FP8',
            'statamic-ai-assistant.infomaniak_product_id' => '123',
            'statamic-ai-assistant.infomaniak_api_token' => 'token',
            'statamic-ai-assistant.quick_tasks_without_thinking' => true,
        ]);

        Http::fake(function (Request $request) {
            $this->payloads[] = $request->data();

            return Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Antwort'], 'finish_reason' => 'stop']],
            ]);
        });

        $this->service = new InfomaniakService;
    }

    private function lastPayload(): array
    {
        $this->assertNotEmpty($this->payloads, 'expected an Infomaniak request');

        return end($this->payloads);
    }

    public function test_fieldtype_generate_and_refactor_switch_thinking_off(): void
    {
        $this->service->generateContentFromPrompt('Zwei Sätze über Eichentische.');
        $this->assertSame(self::THINKING_OFF, $this->lastPayload()['chat_template_kwargs'] ?? null);

        $this->service->generateRefactorFromPrompt('Text', 'kürzer');
        $this->assertSame(self::THINKING_OFF, $this->lastPayload()['chat_template_kwargs'] ?? null);

        $this->service->generateHtmlRefactorFromPrompt('<p>Text</p>', 'kürzer');
        $this->assertSame(self::THINKING_OFF, $this->lastPayload()['chat_template_kwargs'] ?? null);
    }

    public function test_fast_tier_calls_switch_thinking_off(): void
    {
        $this->service->usingFastModel(fn () => $this->service->generateFromMessages([['role' => 'user', 'content' => 'Hinweis?']], 256));

        $payload = $this->lastPayload();
        $this->assertSame('Qwen/Qwen3.8-27B-FP8', $payload['model']);
        $this->assertSame(self::THINKING_OFF, $payload['chat_template_kwargs'] ?? null);
    }

    public function test_agent_calls_keep_thinking_on(): void
    {
        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Generate the entry.']], 4000);
        $this->assertArrayNotHasKey('chat_template_kwargs', $this->lastPayload());

        $this->service->createChatCompletion([['role' => 'user', 'content' => 'Plan.']], 4000, [], 'auto');
        $this->assertArrayNotHasKey('chat_template_kwargs', $this->lastPayload());
    }

    public function test_mistral_models_never_get_the_switch(): void
    {
        // Mistral tokenizers answer chat_template_kwargs with HTTP 400.
        config(['statamic-ai-assistant.infomaniak_model' => 'mistralai/Mistral-Small-4-119B-2603']);

        $this->service->generateContentFromPrompt('Zwei Sätze.');
        $this->assertArrayNotHasKey('chat_template_kwargs', $this->lastPayload());

        config(['statamic-ai-assistant.infomaniak_model' => 'mistral24b']);

        $this->service->generateContentFromPrompt('Zwei Sätze.');
        $this->assertArrayNotHasKey('chat_template_kwargs', $this->lastPayload());
    }

    public function test_image_analysis_switches_thinking_off_except_for_mistral_vision(): void
    {
        config(['statamic-ai-assistant.infomaniak_vision_model' => 'moonshotai/Kimi-K2.6']);
        $this->service->describeImage('data:image/png;base64,AAAA', 'Beschreibe das Bild.');
        $this->assertSame(self::THINKING_OFF, $this->lastPayload()['chat_template_kwargs'] ?? null);

        config(['statamic-ai-assistant.infomaniak_vision_model' => 'mistralai/Ministral-3-14B-Instruct-2512']);
        $this->service->describeImage('data:image/png;base64,AAAA', 'Beschreibe das Bild.');
        $this->assertArrayNotHasKey('chat_template_kwargs', $this->lastPayload());
    }

    public function test_config_can_keep_thinking_everywhere(): void
    {
        config(['statamic-ai-assistant.quick_tasks_without_thinking' => false]);

        $this->service->generateContentFromPrompt('Zwei Sätze.');
        $this->assertArrayNotHasKey('chat_template_kwargs', $this->lastPayload());
    }

    public function test_thinking_is_back_on_after_a_quick_task_throws(): void
    {
        try {
            $this->service->withoutThinking(function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->service->generateFromMessages([['role' => 'user', 'content' => 'Generate the entry.']], 4000);
        $this->assertArrayNotHasKey('chat_template_kwargs', $this->lastPayload());
    }
}
