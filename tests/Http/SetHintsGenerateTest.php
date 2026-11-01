<?php

namespace MotionWork\StatamicAiAssistant\Tests\Http;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

/**
 * "Suggest" on the AI instructions page: the model drafts a block hint from
 * the block's fields; unusable answers are reported, never saved.
 */
class SetHintsGenerateTest extends TestCase
{
    private string $hintsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hintsPath = sys_get_temp_dir().'/hints-generate-'.uniqid().'.yaml';
        config(['statamic-ai-assistant.set_hints_path' => $this->hintsPath]);

        // Reuse the fixture 'packages' collection so the run leaves no new collection files.
        Blueprint::make('package')->setNamespace('collections.packages')->setContents(['fields' => [
            ['handle' => 'blocks', 'field' => ['type' => 'replicator', 'sets' => ['main' => ['sets' => [
                'hero' => ['display' => 'Hero', 'fields' => [['handle' => 'headline', 'field' => ['type' => 'text']]]],
            ]]]]],
        ]])->save();
        Collection::make('packages')->title('Packages')->save();

        $this->actingAs(User::make()->id('hints-super')->email('hints-super@example.com')->makeSuper());
    }

    protected function tearDown(): void
    {
        @unlink($this->hintsPath);

        parent::tearDown();
    }

    private function aiReplies(?string $raw): void
    {
        $ai = $this->createStub(AbstractAiService::class);
        $ai->method('usingFastModel')->willReturnCallback(fn (callable $run) => $run());
        $ai->method('generateFromMessages')->willReturn($raw);
        $this->app->instance(AbstractAiService::class, $ai);
    }

    public function test_a_suggestion_is_cleaned_up(): void
    {
        $this->aiReplies('```json
{"ai_description": " Big opener. ", "when_to_use": ["Top of page", " ", "Top of page", 5, "Campaigns"]}
```');

        $this->postJson('/cp/ai-block-hints/generate', ['handle' => 'hero'])
            ->assertOk()
            ->assertExactJson(['handle' => 'hero', 'ai_description' => 'Big opener.', 'when_to_use' => ['Top of page', 'Campaigns']]);
    }

    public function test_unknown_blocks_and_unusable_answers_are_errors(): void
    {
        $this->aiReplies('{"ai_description": "", "when_to_use": []}');

        $this->postJson('/cp/ai-block-hints/generate', ['handle' => 'nope'])->assertNotFound();
        $this->postJson('/cp/ai-block-hints/generate', ['handle' => 'hero'])->assertStatus(502);

        $this->aiReplies('');
        $this->postJson('/cp/ai-block-hints/generate', ['handle' => 'hero'])->assertStatus(502);
    }
}
