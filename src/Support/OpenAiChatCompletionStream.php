<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Reads an OpenAI-compatible chat completion SSE stream and reassembles assistant text.
 *
 * Comment lines (": OPENROUTER PROCESSING") and anything that is not a `data:`
 * line are skipped. An error that arrives after the stream started (a chunk with
 * a top-level `error`, or finish_reason "error") throws instead of ending the
 * answer early as if it were complete.
 */
final class OpenAiChatCompletionStream
{
    /**
     * $meta is filled with the generation id, the model that answered, the
     * final usage chunk and the finish reason.
     *
     * @param  callable(string): void  $onDelta
     * @param  array{id?: string, model?: string, usage?: array<string, mixed>, finish_reason?: string}|null  $meta
     */
    public static function collect(StreamInterface $stream, callable $onDelta, ?array &$meta = null): string
    {
        $meta = [];
        $full = '';
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);
            $full = self::consumeBufferBlocks($buffer, $full, $onDelta, $meta);
        }

        if ($buffer !== '') {
            $full = self::consumeBufferBlocks($buffer."\n\n", $full, $onDelta, $meta);
        }

        return $full;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function consumeBufferBlocks(string &$buffer, string $full, callable $onDelta, array &$meta): string
    {
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $block = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);
            $full = self::consumeSseBlock($block, $full, $onDelta, $meta);
        }

        return $full;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function consumeSseBlock(string $block, string $full, callable $onDelta, array &$meta): string
    {
        foreach (explode("\n", $block) as $line) {
            $json = self::chunk($line);

            if ($json === null) {
                continue;
            }

            self::guardAgainstError($json);
            self::recordMeta($json, $meta);

            $parts = self::extractDeltaParts($json);

            // Reasoning/thinking tokens must not be concatenated into the final assistant
            // string: entry generation expects pure JSON in the accumulated output. The UI
            // stream may still show reasoning + content for progress feedback.
            $contentOnly = $parts['content'];
            $forDisplay = $parts['reasoning'].$contentOnly;

            if ($forDisplay !== '') {
                $full .= $contentOnly;
                $onDelta($forDisplay);
            }
        }

        return $full;
    }

    /**
     * The decoded JSON of one `data:` line, or null for comments, [DONE] and noise.
     *
     * @return array<string, mixed>|null
     */
    private static function chunk(string $line): ?array
    {
        $line = trim($line);

        if ($line === '' || ! str_starts_with($line, 'data:')) {
            return null;
        }

        $data = trim(substr($line, strlen('data:')));

        if ($data === '' || $data === '[DONE]') {
            return null;
        }

        $json = json_decode($data, true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private static function guardAgainstError(array $json): void
    {
        $error = $json['error'] ?? null;
        $finish = $json['choices'][0]['finish_reason'] ?? null;

        if ($error === null && $finish !== 'error') {
            return;
        }

        $message = match (true) {
            is_array($error) && is_string($error['message'] ?? null) => $error['message'],
            is_string($error) => $error,
            default => '',
        };

        throw new \RuntimeException(__('The AI stopped with an error while answering: :message', ['message' => $message]));
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $meta
     */
    private static function recordMeta(array $json, array &$meta): void
    {
        foreach (['id', 'model'] as $key) {
            if (is_string($json[$key] ?? null) && $json[$key] !== '') {
                $meta[$key] = $json[$key];
            }
        }

        if (is_array($json['usage'] ?? null)) {
            $meta['usage'] = $json['usage'];
        }

        $finish = $json['choices'][0]['finish_reason'] ?? null;
        if (is_string($finish) && $finish !== '') {
            $meta['finish_reason'] = $finish;
        }
    }

    /**
     * @return array{reasoning: string, content: string}
     */
    private static function extractDeltaParts(array $json): array
    {
        $choice = $json['choices'][0] ?? null;

        if (! is_array($choice)) {
            return ['reasoning' => '', 'content' => ''];
        }

        $delta = $choice['delta'] ?? null;

        if (! is_array($delta)) {
            return ['reasoning' => '', 'content' => ''];
        }

        $reasoning = '';

        foreach (['reasoning_content', 'reasoning'] as $key) {
            if (! isset($delta[$key])) {
                continue;
            }

            $v = $delta[$key];

            if (is_string($v) && $v !== '') {
                $reasoning .= $v;
            }
        }

        $content = '';

        if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
            $content = $delta['content'];
        }

        return ['reasoning' => $reasoning, 'content' => $content];
    }
}
