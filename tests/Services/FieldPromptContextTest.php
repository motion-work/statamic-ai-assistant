<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Controllers\PromptController;
use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FieldPromptContextTest extends TestCase
{
    private string $hintsPath;

    /** @var array<int, array<string, mixed>> */
    private array $payloads = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hintsPath = sys_get_temp_dir().'/field-hints-'.uniqid().'.yaml';
        file_put_contents($this->hintsPath, <<<'YAML'
site_instructions: Sprich die Leserschaft mit du an.
field_hints:
  lead:
    ai_description: Short intro shown below the hero.
    when_to_use:
      - 2 sentences max
YAML);

        config([
            'statamic-ai-assistant.set_hints_path' => $this->hintsPath,
            'statamic-ai-assistant.infomaniak_model' => 'moonshotai/Kimi-K2.6',
            'statamic-ai-assistant.infomaniak_product_id' => '123',
            'statamic-ai-assistant.infomaniak_api_token' => 'token',
        ]);

        Http::fake(function (HttpRequest $request) {
            $this->payloads[] = $request->data();

            return Http::response(['choices' => [['message' => ['content' => '<p>Neu</p>']]]]);
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->hintsPath);

        parent::tearDown();
    }

    private function system(int $i = 0): string
    {
        return $this->payloads[$i]['messages'][0]['content'];
    }

    public function test_field_context_reaches_the_model(): void
    {
        app(AbstractAiService::class)->generateContentFromPrompt('Schreib den Lead.', [
            'field_label' => 'Lead',
            'field_handle' => 'lead',
            'entry_title' => 'Unsere Geschichte',
            'max_words' => 40,
        ]);

        $system = $this->system();
        $this->assertStringContainsString('the field "Lead"', $system);
        $this->assertStringContainsString('Short intro shown below the hero.', $system);
        $this->assertStringContainsString('- 2 sentences max', $system);
        $this->assertStringContainsString('the page "Unsere Geschichte"', $system);
        $this->assertStringContainsString('Sprich die Leserschaft mit du an.', $system);
        $this->assertStringContainsString('at most 40 words', $system);
        $this->assertSame('Schreib den Lead.', $this->payloads[0]['messages'][1]['content'], 'no hidden instruction prepended to the editor prompt');
    }

    public function test_refactor_without_context_is_unchanged(): void
    {
        app(AbstractAiService::class)->generateRefactorFromPrompt('Text', 'kürzer');

        $this->assertStringNotContainsString('the field', $this->system());
        $this->assertStringNotContainsString('at most', $this->system());
    }

    public function test_html_refactor_handles_text_runs_between_bard_sets(): void
    {
        $request = Request::create('/cp/promptHtmlrefactor', 'POST', [
            'texts' => ['<p>Eins</p>', '<p></p>', '<p>Zwei</p>'],
            'prompt' => 'kürzer',
            'context' => ['field_label' => 'Inhalt'],
        ]);

        $response = app(PromptController::class)->handleHtmlRefactor($request)->getData(true);

        $this->assertCount(3, $response['contents']);
        $this->assertSame('<p></p>', $response['contents'][1], 'empty runs are not sent to the model');
        $this->assertCount(2, $this->payloads);
        $this->assertStringContainsString('the field "Inhalt"', $this->system(1));
    }
}
