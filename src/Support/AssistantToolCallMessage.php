<?php

namespace MotionWork\StatamicAiAssistant\Support;

/**
 * The assistant turn a tool loop sends back after the model asked for tools.
 *
 * Reasoning models (Claude, Gemini through OpenRouter) return their thinking
 * as `reasoning_details` next to the tool calls. It must come back unchanged,
 * in order, on the next request, or the provider rejects the tool turn. Plain
 * `reasoning` text is kept the same way.
 */
final class AssistantToolCallMessage
{
    private const KEPT_FIELDS = ['reasoning', 'reasoning_details'];

    /**
     * @param  array<string, mixed>  $message  The assistant message from the reply.
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<string, mixed>
     */
    public static function from(array $message, array $toolCalls): array
    {
        $turn = [
            'role' => 'assistant',
            'content' => array_key_exists('content', $message) ? $message['content'] : null,
            'tool_calls' => $toolCalls,
        ];

        foreach (self::KEPT_FIELDS as $field) {
            if (isset($message[$field]) && $message[$field] !== '' && $message[$field] !== []) {
                $turn[$field] = $message[$field];
            }
        }

        return $turn;
    }
}
