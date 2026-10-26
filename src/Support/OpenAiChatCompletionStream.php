<?php

namespace BoldWeb\StatamicAiAssistant\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Reads an OpenAI-compatible chat completion SSE stream and reassembles assistant text.
 */
final class OpenAiChatCompletionStream
{
    /**
     * @param  callable(string): void  $onDelta
     */
    public static function collect(StreamInterface $stream, callable $onDelta): string
    {
        $full = '';
        $buffer = '';

        while (! $stream->eof()) {
            $buffer .= $stream->read(8192);
            $full = self::consumeBufferBlocks($buffer, $full, $onDelta);
        }

        if ($buffer !== '') {
            $full = self::consumeBufferBlocks($buffer."\n\n", $full, $onDelta);
        }

        return $full;
    }

    private static function consumeBufferBlocks(string &$buffer, string $full, callable $onDelta): string
    {
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $block = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);
            $full = self::consumeSseBlock($block, $full, $onDelta);
        }

        return $full;
    }

    private static function consumeSseBlock(string $block, string $full, callable $onDelta): string
    {
        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            if ($line === '' || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, strlen('data:')));

            if ($data === '' || $data === '[DONE]') {
                continue;
            }

            $json = json_decode($data, true);

            if (! is_array($json)) {
                continue;
            }

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
