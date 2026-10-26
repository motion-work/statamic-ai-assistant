<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Tools;

use BoldWeb\StatamicAiAssistant\Tests\TestCase;
use BoldWeb\StatamicAiAssistant\Tools\ChatTool;
use BoldWeb\StatamicAiAssistant\Tools\ChatToolRunner;
use BoldWeb\StatamicAiAssistant\Tools\ToolContext;

class ChatToolRunnerTest extends TestCase
{
    private function fakeTool(string $name, ?int $maxCalls = null, ?callable $handler = null): ChatTool
    {
        return new class($name, $maxCalls, $handler) implements ChatTool
        {
            public function __construct(private string $n, private ?int $max, private $handler) {}

            public function name(): string
            {
                return $this->n;
            }

            public function definition(): array
            {
                return ['type' => 'function', 'function' => ['name' => $this->n]];
            }

            public function handle(string $argumentsJson, ToolContext $context): array
            {
                if ($this->handler) {
                    return ($this->handler)($argumentsJson, $context);
                }

                return ['ok' => true, 'args' => $argumentsJson];
            }

            public function maxCalls(): ?int
            {
                return $this->max;
            }
        };
    }

    private function toolCall(string $name, string $args = '{}', string $id = 'c1'): array
    {
        return ['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $args]];
    }

    public function test_dispatches_registered_tool_and_builds_tool_message(): void
    {
        $runner = new ChatToolRunner([$this->fakeTool('echo')], new ToolContext);

        $working = [];
        $runner->consume([$this->toolCall('echo', '{"a":1}', 'call-7')], $working);

        $this->assertCount(1, $working);
        $this->assertSame('tool', $working[0]['role']);
        $this->assertSame('call-7', $working[0]['tool_call_id']);
        $decoded = json_decode($working[0]['content'], true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame(1, $runner->callCount('echo'));
    }

    public function test_enforces_per_tool_call_budget(): void
    {
        $runner = new ChatToolRunner([$this->fakeTool('limited', 1)], new ToolContext);

        $working = [];
        $runner->consume([$this->toolCall('limited', '{}', 'a')], $working);
        $runner->consume([$this->toolCall('limited', '{}', 'b')], $working);

        $this->assertTrue(json_decode($working[0]['content'], true)['ok']);
        $second = json_decode($working[1]['content'], true);
        $this->assertFalse($second['ok']);
        $this->assertSame('limited_limit_reached', $second['error']);
    }

    public function test_unknown_tool_routes_to_fallback_then_errors(): void
    {
        $runner = new ChatToolRunner([$this->fakeTool('known')], new ToolContext);

        // With a fallback that handles the name.
        $working = [];
        $runner->consume([$this->toolCall('job_tool', '{}')], $working, function (string $name, string $args) {
            return $name === 'job_tool' ? ['ok' => true, 'handled_by' => 'fallback'] : null;
        });
        $this->assertSame('fallback', json_decode($working[0]['content'], true)['handled_by']);

        // With no fallback → standard unknown-tool error.
        $working2 = [];
        $runner->consume([$this->toolCall('mystery', '{}')], $working2);
        $this->assertStringContainsString('unknown tool', json_decode($working2[0]['content'], true)['error']);
    }

    public function test_handler_exception_becomes_tool_error(): void
    {
        $runner = new ChatToolRunner([
            $this->fakeTool('boom', null, fn () => throw new \RuntimeException('kaboom')),
        ], new ToolContext);

        $working = [];
        $runner->consume([$this->toolCall('boom')], $working);

        $this->assertSame('tool_error', json_decode($working[0]['content'], true)['error']);
    }

    public function test_definitions_lists_all_registered_tools(): void
    {
        $runner = new ChatToolRunner([$this->fakeTool('a'), $this->fakeTool('b')], new ToolContext);

        $names = array_map(fn ($d) => $d['function']['name'], $runner->definitions());

        $this->assertEqualsCanonicalizing(['a', 'b'], $names);
    }
}
