<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

use Illuminate\Http\Client\Response;

/**
 * Where an OpenAI-style chat completions payload goes: an HTTP endpoint
 * (OpenAiCompatibleClient) or the local Claude Code CLI (ClaudeCliClient).
 * Both answer with an HTTP response, so OpenAiCompatibleService reads every
 * provider's reply, errors and usage the same way.
 */
interface ChatCompletionsTransport
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(): void|null  $heartbeat  Called while the answer is received (CP keepalive).
     */
    public function send(array $payload, ?callable $heartbeat = null): Response;

    /**
     * An SSE response of chat.completion.chunk events.
     *
     * @param  array<string, mixed>  $payload
     */
    public function stream(array $payload): Response;
}
