<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai\ClaudeCli;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use MotionWork\StatamicAiAssistant\Services\Ai\ChatCompletionsTransport;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;

/**
 * Chat completions answered by the local Claude Code CLI instead of an HTTP
 * API (provider `claude-cli`). The answer comes back as the HTTP response an
 * OpenAI-style endpoint would give, so OpenAiCompatibleService reads it like
 * any other provider's. A stream arrives as one chunk: the CLI answers whole.
 *
 * Refused in production: the CLI is a person's sign-in, not a service.
 */
final class ClaudeCliClient implements ChatCompletionsTransport
{
    public function __construct(private readonly ClaudeCliRunner $runner = new ClaudeCliRunner) {}

    public static function allowed(): bool
    {
        return ! app()->environment('production');
    }

    public function send(array $payload, ?callable $heartbeat = null): Response
    {
        if (! self::allowed()) {
            return self::error(503, 'The Claude CLI provider runs only outside production.');
        }

        $prompt = ClaudeCliPrompt::fromPayload($payload);
        $model = (string) ($payload['model'] ?? 'sonnet');

        try {
            $run = $this->runner->run($prompt, $model, (string) ($payload['effort'] ?? 'low'), $heartbeat);
        } catch (ProcessException $e) {
            Log::error('Claude CLI could not start', ['message' => $e->getMessage()]);

            return self::error(502, $e->getMessage());
        }

        if (! $run['ok']) {
            Log::error('Claude CLI answered with an error', ['stop' => $run['stop'], 'error' => mb_substr($run['error'], 0, 1000)]);

            return self::error(502, mb_substr($run['error'], 0, 500));
        }

        return self::json(200, ClaudeCliReply::completion($prompt, $run, $model));
    }

    public function stream(array $payload): Response
    {
        $response = $this->send($payload);

        if (! $response->successful()) {
            return $response;
        }

        $reply = (array) $response->json();
        $chunk = fn (array $delta, ?string $finish = null, ?array $usage = null): string => 'data: '.json_encode(array_filter([
            'id' => $reply['id'],
            'object' => 'chat.completion.chunk',
            'model' => $reply['model'],
            'choices' => [['index' => 0, 'delta' => $delta === [] ? new \stdClass : $delta, 'finish_reason' => $finish]],
            'usage' => $usage,
        ], fn ($value) => $value !== null), JSON_UNESCAPED_UNICODE)."\n\n";

        $body = $chunk(['role' => 'assistant', 'content' => (string) ($reply['choices'][0]['message']['content'] ?? '')])
            .$chunk([], (string) $reply['choices'][0]['finish_reason'], (array) $reply['usage'])
            ."data: [DONE]\n\n";

        return new Response(new PsrResponse(200, ['Content-Type' => 'text/event-stream'], $body));
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): Response
    {
        return new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    private static function error(int $status, string $message): Response
    {
        return self::json($status, ['error' => ['message' => $message, 'code' => $status]]);
    }
}
