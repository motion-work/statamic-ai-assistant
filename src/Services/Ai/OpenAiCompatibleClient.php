<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP transport for one OpenAI-style chat completions endpoint (Infomaniak,
 * OpenRouter): bearer auth, extra headers, timeout, and the shared retry
 * policy. It sends payloads; building them and reading replies is the
 * service's job (OpenAiCompatibleService).
 *
 * Responses come back with `throw: false`, so the caller maps error statuses
 * to editor-readable messages. A connection failure still throws.
 */
final class OpenAiCompatibleClient
{
    /**
     * @param  array<string, string>  $headers  Extra request headers (attribution etc.)
     */
    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly int $timeout,
        private readonly array $headers = [],
    ) {}

    /**
     * One non-streaming request, retried on transient failures.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(): void|null  $heartbeat  Called while the body is received (CP keepalive).
     */
    public function send(array $payload, ?callable $heartbeat = null): Response
    {
        $options = $heartbeat === null ? [] : [
            'progress' => static function () use ($heartbeat): void {
                $heartbeat();
            },
        ];

        return $this->request()
            ->retry(AiRetryPolicy::times(), AiRetryPolicy::sleepMs(), AiRetryPolicy::when(), throw: false)
            ->withOptions($options)
            ->post($this->url, $payload);
    }

    /**
     * A streaming (SSE) request. Not retried: part of the answer may already
     * have reached the editor.
     *
     * @param  array<string, mixed>  $payload
     */
    public function stream(array $payload): Response
    {
        return $this->request()
            ->withOptions(['stream' => true, 'read_timeout' => $this->timeout])
            ->post($this->url, $payload);
    }

    private function request(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withToken($this->token)
            ->withHeaders($this->headers);
    }
}
