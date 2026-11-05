<?php

namespace MotionWork\StatamicAiAssistant\Tests\Http;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviderException;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\User;

/**
 * The AI field buttons let provider failures bubble. An AiProviderException
 * (out of credits, budget used up, no model can serve the request) reaches the
 * browser as its editor-readable message, flagged; anything else stays a
 * generic server error with no provider details.
 */
class FieldButtonProviderErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::make()->id('field-btn-super')->email('field-btn-super@example.com')->makeSuper());
    }

    private function aiThrows(\Throwable $e): void
    {
        $ai = $this->createStub(AbstractAiService::class);
        $ai->method('generateContentFromPrompt')->willThrowException($e);
        $ai->method('generateRefactorFromPrompt')->willThrowException($e);
        $this->app->instance(AbstractAiService::class, $ai);
    }

    public function test_a_budget_refusal_reaches_the_editor(): void
    {
        $this->aiThrows(new AiProviderException('The OpenRouter key for this site has reached its spending limit.', 402, 'openrouter_key_limit'));

        $this->postJson('/cp/prompt', ['title' => 'Write an intro'])
            ->assertStatus(502)
            ->assertExactJson([
                'message' => 'The OpenRouter key for this site has reached its spending limit.',
                'ai_provider_error' => true,
                'budget_limit' => true,
            ]);
    }

    public function test_a_provider_403_never_answers_403(): void
    {
        // A 403 to the CP would read as the editor's own session failing.
        $this->aiThrows(new AiProviderException('The AI provider declined this request (content moderation).', 403));

        $this->postJson('/cp/promptrefactor', ['text' => 'Old', 'prompt' => 'Shorter'])
            ->assertStatus(502)
            ->assertJson(['ai_provider_error' => true, 'budget_limit' => false]);
    }

    public function test_other_failures_carry_no_provider_flag(): void
    {
        $this->aiThrows(new \RuntimeException('Infomaniak request failed (400): raw provider body'));

        $response = $this->postJson('/cp/prompt', ['title' => 'Write an intro'])->assertStatus(500);

        $this->assertArrayNotHasKey('ai_provider_error', $response->json());
    }
}
