<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai\ClaudeCli;

/**
 * An OpenAI-style chat completions payload as one Claude Code CLI call: the
 * system messages become the system prompt, the conversation one user
 * message (images first, as image blocks), and the payload's tools and
 * response_format become instructions to answer with one JSON object, which
 * ClaudeCliReply turns back into tool calls or JSON content. The CLI cannot
 * hand the addon's own tools back as native tool calls, so they travel as
 * JSON (the same contract mateo's gateway uses in its CLI mode).
 */
final class ClaudeCliPrompt
{
    /**
     * @param  list<array<string, mixed>>  $content  stream-json user content blocks
     * @param  list<string>  $toolNames  tools the model may call
     */
    private function __construct(
        public readonly string $system,
        public readonly array $content,
        public readonly array $toolNames,
        public readonly bool $wantsJson,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $messages = array_values(array_filter((array) ($payload['messages'] ?? []), 'is_array'));
        $system = self::systemText($messages);
        $tools = ($payload['tool_choice'] ?? 'auto') === 'none' ? [] : self::tools((array) ($payload['tools'] ?? []));

        if ($tools !== []) {
            $system .= "\n\n".self::toolInstructions($tools, $payload['tool_choice'] ?? 'auto');
        } elseif (is_array($payload['response_format'] ?? null)) {
            $system .= "\n\n".self::jsonInstructions($payload['response_format']);
        }

        return new self(
            trim($system),
            ClaudeCliTranscript::content(array_values(array_filter($messages, fn (array $m) => ($m['role'] ?? '') !== 'system'))),
            array_column($tools, 'name'),
            $tools !== [] || is_array($payload['response_format'] ?? null),
        );
    }

    /** @param list<array<string, mixed>> $messages */
    private static function systemText(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system') {
                $parts[] = ClaudeCliTranscript::text($message['content'] ?? '');
            }
        }

        return implode("\n\n", array_filter($parts, fn (string $part) => trim($part) !== ''));
    }

    /**
     * @param  array<int, mixed>  $tools  OpenAI tool definitions
     * @return list<array{name: string, description?: string, parameters?: mixed}>
     */
    private static function tools(array $tools): array
    {
        $out = [];

        foreach ($tools as $tool) {
            $function = is_array($tool) ? ($tool['function'] ?? null) : null;

            if (is_array($function) && is_string($function['name'] ?? null) && $function['name'] !== '') {
                $out[] = array_filter([
                    'name' => $function['name'],
                    'description' => $function['description'] ?? null,
                    'parameters' => $function['parameters'] ?? null,
                ], fn ($value) => $value !== null);
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @param  string|array<string, mixed>  $choice
     */
    private static function toolInstructions(array $tools, string|array $choice): string
    {
        $forced = is_array($choice) ? ($choice['function']['name'] ?? null) : null;
        $rule = match (true) {
            is_string($forced) => "Call the tool \"{$forced}\" now.",
            $choice === 'required' => 'Call at least one tool now.',
            default => 'Call a tool only when you need its result; otherwise leave tool_calls empty and answer in content.',
        };

        return "You can call these tools. They run on the caller's side; their results come back in the next message as <tool_result>.\n"
            .json_encode($tools, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\nReply with exactly one JSON object and nothing else, no code fence: "
            .'{"content": "your answer, or null", "tool_calls": [{"name": "tool", "arguments": {}}]}. '
            .$rule;
    }

    /** @param array<string, mixed> $format OpenAI response_format */
    private static function jsonInstructions(array $format): string
    {
        $schema = $format['json_schema']['schema'] ?? null;

        return is_array($schema)
            ? 'Reply with only one JSON object that matches this JSON Schema, no text before or after it, no code fence: '
                .json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : 'Reply with only one JSON object, no text before or after it, no code fence.';
    }
}
