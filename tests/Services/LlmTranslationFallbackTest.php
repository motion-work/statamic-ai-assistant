<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\DeeplService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class LlmTranslationFallbackTest extends TestCase
{
    private string $dir;

    /** @var array<int, array<string, mixed>> */
    private array $payloads = [];

    /** @var array<int, string> queued raw LLM replies; the last one repeats */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/llm-translation-'.uniqid();
        mkdir($this->dir);

        config([
            'deepl.api_key' => null,
            'statamic-ai-assistant.translation_llm_fallback' => true,
            'statamic-ai-assistant.provider_name' => 'infomaniak',
            'statamic-ai-assistant.infomaniak_model' => 'moonshotai/Kimi-K2.6',
            'statamic-ai-assistant.infomaniak_product_id' => '123',
            'statamic-ai-assistant.infomaniak_api_token' => 'token',
            'statamic-ai-assistant.translation_glossary_path' => $this->dir.'/glossary.yaml',
            'statamic-ai-assistant.translation_style_rules_path' => $this->dir.'/style.yaml',
        ]);

        Http::fake(function (Request $request) {
            $this->payloads[] = $request->data();
            $reply = count($this->replies) > 1 ? array_shift($this->replies) : ($this->replies[0] ?? '');

            return Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $reply], 'finish_reason' => 'stop']],
            ]);
        });
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function deepl(): DeeplService
    {
        return app(DeeplService::class);
    }

    private function systemPrompt(int $request = 0): string
    {
        return $this->payloads[$request]['messages'][0]['content'];
    }

    public function test_without_a_key_translations_run_on_the_llm_and_keep_empty_slots(): void
    {
        $this->replies = ['{"translations": ["Solid wood table", "Our workshop"]}'];

        $result = $this->deepl()->translateBatch(['Massivholztisch', '', 'Unsere Werkstatt'], 'de', 'en');

        $this->assertSame(['Solid wood table', '', 'Our workshop'], $result);
        $this->assertCount(1, $this->payloads);
        $this->assertSame(
            ['texts' => ['Massivholztisch', 'Unsere Werkstatt']],
            json_decode($this->payloads[0]['messages'][1]['content'], true),
        );
        $this->assertSame(['thinking' => false, 'enable_thinking' => false], $this->payloads[0]['chat_template_kwargs']);
    }

    public function test_html_texts_keep_markup_and_swiss_german_avoids_eszett(): void
    {
        $this->replies = ['{"translations": ["<p>Grosse <strong>Tische</strong></p>"]}'];

        $this->deepl()->translateBatch(['<p>Large <strong>tables</strong></p>'], 'en', 'de-CH', ['tag_handling' => 'html']);

        $this->assertStringContainsString('The texts are HTML', $this->systemPrompt());
        $this->assertStringContainsString('never "ß"', $this->systemPrompt());
    }

    public function test_glossary_terms_in_the_text_and_style_rules_reach_the_prompt(): void
    {
        file_put_contents($this->dir.'/glossary.yaml', <<<'YAML'
entries:
  - id: a
    terms: { de: Sashimono, en: Sashimono }
  - id: b
    terms: { de: Zinkung, en: dovetail joint }
YAML);
        file_put_contents($this->dir.'/style.yaml', <<<'YAML'
rules:
  en:
    instructions:
      - Use the informal "you".
YAML);

        $this->replies = ['{"translations": ["A dovetail joint"]}'];

        $this->deepl()->translateBatch(['Eine Zinkung'], 'de', 'en');

        $prompt = $this->systemPrompt();
        $this->assertStringContainsString('"Zinkung" → "dovetail joint"', $prompt);
        $this->assertStringNotContainsString('Sashimono', $prompt, 'only terms that occur in the text');
        $this->assertStringContainsString('- Use the informal "you".', $prompt);
    }

    public function test_an_unusable_batch_reply_is_retried_text_by_text(): void
    {
        $this->replies = [
            '{"translations": ["only one"]}',
            '{"translations": ["Table"]}',
            '{"translations": ["Chair"]}',
        ];

        $result = $this->deepl()->translateBatch(['Tisch', 'Stuhl'], 'de', 'en');

        $this->assertSame(['Table', 'Chair'], $result);
        $this->assertCount(3, $this->payloads);
    }

    public function test_notice_tells_the_editor_and_disappears_with_a_key(): void
    {
        $this->assertTrue($this->deepl()->usesLlmFallback());
        $this->assertNotNull($this->deepl()->llmFallbackNotice());

        config(['deepl.api_key' => 'key']);

        $this->assertFalse($this->deepl()->usesLlmFallback());
        $this->assertNull($this->deepl()->llmFallbackNotice());
    }

    public function test_fallback_can_be_switched_off(): void
    {
        config(['statamic-ai-assistant.translation_llm_fallback' => false]);

        $this->expectExceptionMessage('DeepL API key is not configured');

        $this->deepl()->translateBatch(['Tisch'], 'de', 'en');
    }
}
