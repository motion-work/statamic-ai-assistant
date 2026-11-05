<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\AbstractAiService;
use MotionWork\StatamicAiAssistant\Services\EntryGeneratorService;
use MotionWork\StatamicAiAssistant\Support\AssistantToolCallMessage;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * Reasoning models return their thinking next to tool calls; the next request
 * must send it back unchanged, or providers reject the tool turn.
 */
class ToolTurnReasoningTest extends TestCase
{
    private const DETAILS = [['type' => 'reasoning.encrypted', 'data' => 'opaque', 'id' => 'r1', 'format' => 'google-gemini-v1', 'index' => 0]];

    /** @var array<int, array<int, array<string, mixed>>> */
    private array $sentMessages = [];

    public function test_the_helper_keeps_reasoning_and_drops_nothing_else(): void
    {
        $toolCalls = [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read_entry', 'arguments' => '{}']]];

        $turn = AssistantToolCallMessage::from([
            'role' => 'assistant',
            'content' => null,
            'reasoning' => 'Look it up first.',
            'reasoning_details' => self::DETAILS,
            'refusal' => null,
        ], $toolCalls);

        $this->assertSame([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $toolCalls,
            'reasoning' => 'Look it up first.',
            'reasoning_details' => self::DETAILS,
        ], $turn);

        $plain = AssistantToolCallMessage::from(['content' => ''], $toolCalls);
        $this->assertArrayNotHasKey('reasoning_details', $plain);
        $this->assertArrayNotHasKey('reasoning', $plain);
    }

    public function test_the_generation_loop_sends_reasoning_details_back_with_the_tool_turn(): void
    {
        Blueprint::make('package')->setNamespace('collections.packages')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ]]]]]])->save();
        Collection::make('packages')->title('Packages')->save();

        $ai = $this->createStub(AbstractAiService::class);
        $ai->method('supportsChatTools')->willReturn(true);
        $ai->method('cleanResult')->willReturnArgument(0);
        $ai->method('createChatCompletion')->willReturnCallback(function (array $messages) {
            $this->sentMessages[] = $messages;

            if (count($this->sentMessages) === 1) {
                return ['choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'reasoning_details' => self::DETAILS,
                    'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'read_entry', 'arguments' => '{"id":"missing"}']]],
                ], 'finish_reason' => 'tool_calls']]];
            }

            return ['choices' => [['message' => ['role' => 'assistant', 'content' => '{"title":"Tea"}'], 'finish_reason' => 'stop']]];
        });
        $this->app->instance(AbstractAiService::class, $ai);
        $this->app->forgetInstance(EntryGeneratorService::class);

        app(EntryGeneratorService::class)->generateContent('packages', 'package', 'Write about tea', 'default');

        $this->assertGreaterThanOrEqual(2, count($this->sentMessages), 'a second round follows the tool call');
        $assistantTurns = array_values(array_filter($this->sentMessages[1], fn ($m) => ($m['role'] ?? '') === 'assistant' && isset($m['tool_calls'])));
        $this->assertCount(1, $assistantTurns);
        $this->assertSame(self::DETAILS, $assistantTurns[0]['reasoning_details']);
    }
}
