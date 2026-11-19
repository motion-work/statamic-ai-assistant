<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai\ClaudeCli;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The conversation turns of a chat completions payload as the content of one
 * CLI user message. A single user turn goes in as it is; a longer exchange
 * (a tool loop, a chat) is written out as tagged turns, with the assistant's
 * tool calls and the tool results in between. Images (OpenAI `image_url`
 * parts) go first as base64 image blocks and are referenced as [Image n].
 */
final class ClaudeCliTranscript
{
    /**
     * @param  list<array<string, mixed>>  $messages  the non-system messages
     * @return list<array<string, mixed>>
     */
    public static function content(array $messages): array
    {
        $images = [];
        $turns = [];

        foreach ($messages as $message) {
            $turns[] = ['role' => (string) ($message['role'] ?? 'user'), 'text' => self::turnText($message, $images)];
        }

        $text = count($turns) === 1 && $turns[0]['role'] === 'user'
            ? $turns[0]['text']
            : "The conversation so far. Reply as the assistant to the last message.\n\n".implode("\n\n", array_map(
                fn (array $turn): string => "<{$turn['role']}>\n{$turn['text']}\n</{$turn['role']}>",
                $turns,
            ));

        return [...array_values(array_filter(array_map(self::imageBlock(...), $images))), ['type' => 'text', 'text' => $text]];
    }

    /** Plain text of a message's content (a string or OpenAI content parts). */
    public static function text(mixed $content): string
    {
        $images = [];

        return self::partsText($content, $images);
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  list<string>  $images  collects image URLs
     */
    private static function turnText(array $message, array &$images): string
    {
        $lines = [self::partsText($message['content'] ?? '', $images)];

        foreach ((array) ($message['tool_calls'] ?? []) as $call) {
            $function = is_array($call) ? (array) ($call['function'] ?? []) : [];
            $lines[] = '<tool_call name="'.($function['name'] ?? '').'" id="'.($call['id'] ?? '').'">'.($function['arguments'] ?? '{}').'</tool_call>';
        }

        if (($message['role'] ?? '') === 'tool') {
            return '<tool_result id="'.($message['tool_call_id'] ?? '').'">'.$lines[0].'</tool_result>';
        }

        return trim(implode("\n", array_filter($lines, fn (string $line) => $line !== '')));
    }

    /** @param list<string> $images */
    private static function partsText(mixed $content, array &$images): string
    {
        if (is_string($content)) {
            return $content;
        }

        $lines = [];

        foreach (is_array($content) ? $content : [] as $part) {
            $type = is_array($part) ? ($part['type'] ?? null) : null;

            if ($type === 'text') {
                $lines[] = (string) ($part['text'] ?? '');
            } elseif ($type === 'image_url' && is_string($part['image_url']['url'] ?? null)) {
                $images[] = $part['image_url']['url'];
                $lines[] = '[Image '.count($images).']';
            }
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed>|null a base64 image block from a data: URL or a web URL */
    private static function imageBlock(string $url): ?array
    {
        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $url, $m) === 1) {
            return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => strtolower($m[1]), 'data' => $m[2]]];
        }

        try {
            $response = Http::timeout(30)->get($url);
            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

            if ($response->successful() && str_starts_with($type, 'image/')) {
                return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $type, 'data' => base64_encode($response->body())]];
            }
        } catch (\Throwable $e) {
            Log::warning('Claude CLI: could not fetch an image', ['url' => mb_substr($url, 0, 200), 'message' => $e->getMessage()]);
        }

        return null;
    }
}
