<?php

namespace MotionWork\StatamicAiAssistant\Tests\Tools;

use MotionWork\StatamicAiAssistant\Tests\TestCase;
use MotionWork\StatamicAiAssistant\Tools\ChatTool;
use MotionWork\StatamicAiAssistant\Tools\ChatToolRunner;
use MotionWork\StatamicAiAssistant\Tools\ToolContext;

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

    public function test_compaction_elides_old_large_tool_results_only(): void
    {
        $big = json_encode(['ok' => true, 'blob' => str_repeat('x', 5000)]);
        $small = json_encode(['ok' => true]);

        $working = [
            ['role' => 'user', 'content' => 'go'],
            ['role' => 'tool', 'tool_call_id' => 'a', 'content' => $big],   // old + big → elided
            ['role' => 'tool', 'tool_call_id' => 'b', 'content' => $small], // old + small → kept
            ['role' => 'tool', 'tool_call_id' => 'c', 'content' => $big],   // recent → kept
            ['role' => 'tool', 'tool_call_id' => 'd', 'content' => $big],   // recent → kept
        ];

        ChatToolRunner::compactToolMessages($working, keepLast: 2, maxBytes: 2048);

        $this->assertStringContainsString('"elided":true', $working[1]['content']);
        $this->assertSame($small, $working[2]['content']);
        $this->assertSame($big, $working[3]['content']);
        $this->assertSame($big, $working[4]['content']);
        // Non-tool messages are never touched.
        $this->assertSame('go', $working[0]['content']);
    }

    public function test_result_observer_sees_every_dispatched_result(): void
    {
        $seen = [];
        $runner = new ChatToolRunner(
            [$this->fakeTool('good'), $this->fakeTool('bad', null, fn () => throw new \RuntimeException('boom'))],
            new ToolContext,
            function (string $name, array $result) use (&$seen) {
                $seen[] = [$name, $result['ok']];
            },
        );

        $working = [];
        $runner->consume([$this->toolCall('good', '{}', 'a'), $this->toolCall('bad', '{}', 'b')], $working);

        $this->assertSame([['good', true], ['bad', false]], $seen);
    }

    public function test_oversized_result_is_replaced_with_actionable_error(): void
    {
        $huge = $this->fakeTool('huge', null, fn () => ['ok' => true, 'blob' => str_repeat('x', 150_000)]);
        $runner = new ChatToolRunner([$huge], new ToolContext);

        $working = [];
        $runner->consume([$this->toolCall('huge')], $working);

        $decoded = json_decode($working[0]['content'], true);
        $this->assertFalse($decoded['ok']);
        $this->assertSame('result_too_large', $decoded['error']);
        $this->assertStringContainsString('narrower', $decoded['hint']);
        $this->assertLessThan(100_000, strlen($working[0]['content']));
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
