<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai\ClaudeCli;

/**
 * A CLI answer as an OpenAI chat completion: the JSON reply asked for by
 * ClaudeCliPrompt becomes `content` and `tool_calls` (only tools the payload
 * offered), and usage carries the tokens with `cost` 0, since the calls run
 * on the CLI's subscription, not on API credits.
 */
final class ClaudeCliReply
{
    /**
     * @param  array{text: string, stop: string, model: ?string, usage: array{input: int, output: int, cache_write: int, cache_read: int}}  $run
     * @return array<string, mixed>
     */
    public static function completion(ClaudeCliPrompt $prompt, array $run, string $requestedModel): array
    {
        [$content, $toolCalls] = self::parse($prompt, $run['text']);
        $message = ['role' => 'assistant', 'content' => $content === '' && $toolCalls !== [] ? null : $content];

        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => 'claude-cli-'.bin2hex(random_bytes(8)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $run['model'] ?? $requestedModel,
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => self::finishReason($toolCalls, $run['stop'])]],
            'usage' => self::usage($run['usage']),
        ];
    }

    /**
     * @param  array{input: int, output: int, cache_write: int, cache_read: int}  $usage
     * @return array<string, mixed>
     */
    public static function usage(array $usage): array
    {
        $prompt = $usage['input'] + $usage['cache_write'] + $usage['cache_read'];

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $usage['output'],
            'total_tokens' => $prompt + $usage['output'],
            'prompt_tokens_details' => ['cached_tokens' => $usage['cache_read'], 'cache_write_tokens' => $usage['cache_write']],
            'cost' => 0.0,
            'billing' => 'subscription',
        ];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>} */
    private static function parse(ClaudeCliPrompt $prompt, string $text): array
    {
        if (! $prompt->wantsJson) {
            return [$text, []];
        }

        $answer = self::decodeJson($text);

        if ($prompt->toolNames === []) {
            return [is_array($answer) ? (string) json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $text, []];
        }

        if (! is_array($answer) || ! (array_key_exists('content', $answer) || array_key_exists('tool_calls', $answer))) {
            // Not the tool envelope: hand the text on, the planner recovers tool calls printed as text.
            return [$text, []];
        }

        return [is_string($answer['content'] ?? null) ? $answer['content'] : '', self::toolCalls($prompt, (array) ($answer['tool_calls'] ?? []))];
    }

    /**
     * @param  array<int, mixed>  $calls
     * @return list<array<string, mixed>>
     */
    private static function toolCalls(ClaudeCliPrompt $prompt, array $calls): array
    {
        $out = [];

        foreach ($calls as $call) {
            if (! is_array($call) || ! in_array($call['name'] ?? null, $prompt->toolNames, true)) {
                continue;
            }

            $arguments = is_array($call['arguments'] ?? null) && $call['arguments'] !== [] ? $call['arguments'] : new \stdClass;
            $out[] = [
                'id' => 'call_'.bin2hex(random_bytes(8)),
                'type' => 'function',
                'function' => ['name' => $call['name'], 'arguments' => (string) json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ];
        }

        return $out;
    }

    /** The JSON object in a reply, also inside a code fence or after a sentence; null when there is none. */
    public static function decodeJson(string $text): mixed
    {
        $text = trim($text);
        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return $start === false || $end === false || $end < $start ? null : json_decode(substr($text, $start, $end - $start + 1), true);
    }

    /** @param list<array<string, mixed>> $toolCalls */
    private static function finishReason(array $toolCalls, string $stop): string
    {
        return match (true) {
            $toolCalls !== [] => 'tool_calls',
            $stop === 'max_tokens' => 'length',
            default => 'stop',
        };
    }
}
