<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * What the generation tool loop offers the model: URL fetching only when the
 * user gave a URL (and forced on the first round), never with an attached
 * document, and the same loop on the update path.
 */
class GenerationToolLoopTest extends TestCase
{
    /** @var array<int, array{tools: array<int, string>, choice: mixed, system: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Reuse the fixture 'packages' collection so the run leaves no new collection files.
        Blueprint::make('package')->setNamespace('collections.packages')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'body', 'field' => ['type' => 'bard']],
        ]]]]]])->save();
        Collection::make('packages')->title('Packages')->save();

        $ai = $this->createStub(AbstractAiService::class);
        $ai->method('supportsChatTools')->willReturn(true);
        $ai->method('cleanResult')->willReturnArgument(0);
        $ai->method('createChatCompletion')->willReturnCallback(function (array $messages, $max, array $tools, $choice) {
            $this->calls[] = [
                'tools' => array_map(fn ($tool) => $tool['function']['name'], $tools),
                'choice' => $choice,
                'system' => $messages[0]['content'],
            ];

            return ['choices' => [['message' => ['role' => 'assistant', 'content' => '{"title":"Tea","body":"<p>Hi</p>"}']]]];
        });
        $this->app->instance(AbstractAiService::class, $ai);
        $this->app->forgetInstance(EntryGeneratorService::class);
    }

    public function test_a_url_in_the_prompt_forces_a_fetch_first(): void
    {
        $result = app(EntryGeneratorService::class)->generateContent('packages', 'package', 'Copy https://example.com/a', 'default');

        $this->assertSame('Tea', $result['data']['title']);
        $this->assertContains('fetch_page_content', $this->calls[0]['tools']);
        $this->assertSame(['type' => 'function', 'function' => ['name' => 'fetch_page_content']], $this->calls[0]['choice']);
        $this->assertStringStartsWith('URL HANDLING RULES', $this->calls[0]['system']);
    }

    public function test_an_attachment_is_the_source_so_nothing_is_fetched(): void
    {
        app(EntryGeneratorService::class)->generateContent('packages', 'package', 'Use https://example.com/x', 'default', 'FILE TEXT');

        $this->assertNotContains('fetch_page_content', $this->calls[0]['tools']);
        $this->assertSame('auto', $this->calls[0]['choice']);
        $this->assertStringContainsString('ATTACHMENT RULE', $this->calls[0]['system']);
    }

    public function test_without_a_url_the_model_may_fetch_but_is_not_forced(): void
    {
        app(EntryGeneratorService::class)->generateContent('packages', 'package', 'Write about tea', 'default');

        $this->assertContains('read_entry', $this->calls[0]['tools']);
        $this->assertSame('auto', $this->calls[0]['choice']);
    }

    public function test_the_update_path_uses_the_same_loop_without_saving_images(): void
    {
        $entry = Entry::make()->collection('packages')->locale('default')->slug('tea')->data(['title' => 'Old']);
        $entry->save();

        $result = app(EntryGeneratorService::class)->generateUpdateForEntry((string) $entry->id(), 'Refresh from https://example.com/b', 'default');

        $this->assertSame('Tea', $result['data']['title']);
        $this->assertSame('packages', $result['collection']);
        $this->assertNotContains('save_remote_image', $this->calls[0]['tools']);
        $this->assertSame('fetch_page_content', $this->calls[0]['choice']['function']['name']);
    }
}
