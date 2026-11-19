<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use MotionWork\StatamicAiAssistant\Events\AiCompletionFinished;
use MotionWork\StatamicAiAssistant\Services\Ai\AiProviders;
use MotionWork\StatamicAiAssistant\Services\Ai\AiTask;
use MotionWork\StatamicAiAssistant\Services\ClaudeCliService;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

/**
 * The claude-cli provider: every call runs `claude -p` on the CLI's own sign-in,
 * tools and JSON answers travel as JSON, usage is logged at cost 0, and the
 * provider refuses to run in production.
 */
class ClaudeCliServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'statamic-ai-assistant.provider_name' => 'claude-cli',
            'statamic-ai-assistant.claude_cli_path' => '/usr/local/bin/claude',
        ]);
    }

    /** The CLI's stream-json output ending in a result event with $text. */
    private function answer(string $text, bool $ok = true): void
    {
        $events = [
            ['type' => 'system', 'subtype' => 'init'],
            [
                'type' => 'result',
                'subtype' => $ok ? 'success' : 'error_during_execution',
                'is_error' => ! $ok,
                'result' => $text,
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 12, 'output_tokens' => 5, 'cache_creation_input_tokens' => 600, 'cache_read_input_tokens' => 0],
                'modelUsage' => ['claude-haiku-5-5' => ['inputTokens' => 12]],
            ],
        ];

        Process::fake(['*' => Process::result(implode("\n", array_map('json_encode', $events))."\n")]);
    }

    public function test_claude_cli_is_picked_only_by_name_and_never_in_production(): void
    {
        $this->assertSame(AiProviders::CLAUDE_CLI, AiProviders::active());
        $this->assertInstanceOf(ClaudeCliService::class, AiProviders::make());
        $this->assertTrue(AiProviders::hasCredentials());

        $this->app['env'] = 'production';
        $this->assertFalse(AiProviders::hasCredentials());

        $this->app['env'] = 'testing';
        config(['statamic-ai-assistant.provider_name' => 'auto']);
        $this->assertNotSame(AiProviders::CLAUDE_CLI, AiProviders::active());
    }

    public function test_a_call_runs_the_cli_on_its_own_sign_in_without_tools(): void
    {
        $this->answer('Kürzer.');
        $service = new ClaudeCliService;

        $reply = $service->forTask(AiTask::Field, fn () => $service->generateFromMessages([
            ['role' => 'system', 'content' => 'Du kürzt Texte.'],
            ['role' => 'user', 'content' => 'Bitte kürzer.'],
        ]));

        $this->assertSame('Kürzer.', $reply);
        Process::assertRan(function (PendingProcess $process): bool {
            $command = $process->command;
            $input = json_decode((string) $process->input, true);

            return $command[0] === '/usr/local/bin/claude'
                && $command[array_search('--model', $command) + 1] === 'haiku'
                && $command[array_search('--effort', $command) + 1] === 'low'
                && $command[array_search('--tools', $command) + 1] === ''
                && $command[array_search('--max-turns', $command) + 1] === '1'
                && in_array('--no-session-persistence', $command, true)
                && $process->environment['ANTHROPIC_API_KEY'] === false
                && $input['message']['content'] === [['type' => 'text', 'text' => 'Bitte kürzer.']];
        });
    }

    public function test_the_agent_task_uses_the_agent_model(): void
    {
        $this->answer('Plan.');
        $service = new ClaudeCliService;

        $service->forTask(AiTask::Agent, fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Plane.']]));

        Process::assertRan(fn (PendingProcess $process): bool => $process->command[array_search('--model', $process->command) + 1] === 'sonnet'
            && $process->command[array_search('--effort', $process->command) + 1] === 'medium');
    }

    public function test_tool_calls_come_back_as_openai_tool_calls(): void
    {
        $this->answer('{"content": null, "tool_calls": [{"name": "find_entries", "arguments": {"query": "Umzug"}}, {"name": "delete_everything", "arguments": {}}]}');
        $tools = [['type' => 'function', 'function' => ['name' => 'find_entries', 'description' => 'Finds entries.', 'parameters' => ['type' => 'object']]]];

        $data = (new ClaudeCliService)->createChatCompletion([
            ['role' => 'user', 'content' => 'Finde die Umzugsseite.'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'find_entries', 'arguments' => '{"query":"Umzug"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '[]'],
        ], null, $tools, ['type' => 'function', 'function' => ['name' => 'find_entries']]);

        $calls = $data['choices'][0]['message']['tool_calls'];
        $this->assertCount(1, $calls, 'only tools the request offered');
        $this->assertSame('find_entries', $calls[0]['function']['name']);
        $this->assertSame(['query' => 'Umzug'], json_decode($calls[0]['function']['arguments'], true));
        $this->assertSame('tool_calls', $data['choices'][0]['finish_reason']);

        Process::assertRan(function (PendingProcess $process): bool {
            $text = json_decode((string) $process->input, true)['message']['content'][0]['text'];

            return str_contains($text, '<tool_call name="find_entries" id="call_1">{"query":"Umzug"}</tool_call>')
                && str_contains($text, '<tool_result id="call_1">[]</tool_result>');
        });
    }

    public function test_a_json_answer_is_unwrapped_from_a_code_fence(): void
    {
        $this->answer("Hier:\n```json\n{\"title\": \"Umzug\"}\n```");
        $service = new ClaudeCliService;

        $reply = $service->withRequestOptions(
            ['response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'entry', 'schema' => ['type' => 'object']]]],
            fn () => $service->generateFromMessages([['role' => 'user', 'content' => 'Eintrag.']]),
        );

        $this->assertSame(['title' => 'Umzug'], json_decode($reply, true));
    }

    public function test_images_go_in_as_image_blocks(): void
    {
        $this->answer('Ein Umzugswagen.');

        (new ClaudeCliService)->describeImage('data:image/png;base64,iVBORw0KGgo=', 'Beschreibe das Bild.');

        Process::assertRan(function (PendingProcess $process): bool {
            $content = json_decode((string) $process->input, true)['message']['content'];

            return $content[0] === ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'iVBORw0KGgo=']]
                && $content[1]['text'] === "Beschreibe das Bild.\n[Image 1]";
        });
    }

    public function test_usage_is_logged_at_cost_zero(): void
    {
        $this->answer('Antwort');
        Event::fake([AiCompletionFinished::class]);

        (new ClaudeCliService)->generateFromMessages([['role' => 'user', 'content' => 'Hallo']]);

        Event::assertDispatched(AiCompletionFinished::class, fn (AiCompletionFinished $event): bool => $event->provider === 'claude-cli'
            && $event->cost() === 0.0
            && $event->model === 'claude-haiku-5-5'
            && ($event->usage['completion_tokens'] ?? null) === 5);
    }

    public function test_streaming_delivers_the_answer_in_one_piece(): void
    {
        $this->answer('Ganzer Text.');
        $deltas = [];

        $reply = (new ClaudeCliService)->generateFromMessages([['role' => 'user', 'content' => 'Hallo']], null, function (string $delta) use (&$deltas): void {
            $deltas[] = $delta;
        });

        $this->assertSame('Ganzer Text.', $reply);
        $this->assertSame(['Ganzer Text.'], $deltas);
    }

    public function test_a_failed_cli_call_reaches_the_editor_as_a_message(): void
    {
        $this->answer('Not logged in · Please run /login', ok: false);

        $this->expectExceptionMessage('Claude CLI request failed (502): Not logged in');
        (new ClaudeCliService)->generateFromMessages([['role' => 'user', 'content' => 'Hallo']]);
    }

    public function test_production_refuses_without_starting_the_cli(): void
    {
        $this->answer('nie');
        $this->app['env'] = 'production';

        try {
            (new ClaudeCliService)->generateFromMessages([['role' => 'user', 'content' => 'Hallo']]);
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only outside production', $e->getMessage());
        }

        Process::assertNothingRan();
    }
}
