<?php

namespace MotionWork\StatamicAiAssistant\Services\Ai\ClaudeCli;

use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

/**
 * One `claude -p` call: no Claude Code tools, settings, hooks, MCP servers or
 * saved session, our system prompt in place of Claude Code's, the prompt as
 * one stream-json user message. ANTHROPIC_API_KEY is removed from the CLI's
 * environment, so it always answers on its own sign-in (the subscription).
 */
final class ClaudeCliRunner
{
    /**
     * @param  callable(): void|null  $heartbeat  Called about once a second while the CLI works (the planner's cancellation poll; an exception from it stops the CLI).
     * @return array{ok: bool, text: string, stop: string, error: string, model: ?string, usage: array{input: int, output: int, cache_write: int, cache_read: int}}
     */
    public function run(ClaudeCliPrompt $prompt, string $model, string $effort, ?callable $heartbeat = null): array
    {
        $systemFile = (string) tempnam(sys_get_temp_dir(), 'sai-claude-');

        try {
            // A file, not an argument: the agent's system prompt can be larger than argv allows.
            file_put_contents($systemFile, $prompt->system);
            chmod($systemFile, 0600);

            $process = Process::path(sys_get_temp_dir())
                ->forever()
                ->env(self::environment())
                ->input(json_encode(
                    ['type' => 'user', 'message' => ['role' => 'user', 'content' => $prompt->content]],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                )."\n")
                ->start($this->command($model, $effort, $systemFile));

            if (! $this->waitFor($process, $heartbeat)) {
                return self::failure('no answer within '.self::timeout().' seconds (STATAMIC_AI_ASSISTANT_CLAUDE_CLI_TIMEOUT)');
            }

            $result = $process->wait();
        } finally {
            @unlink($systemFile);
        }

        $event = self::resultEvent($result->output());

        if ($event === null) {
            $error = trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output());

            return self::failure('no result (exit '.$result->exitCode().'): '.mb_substr($error, -500));
        }

        return self::outcome($event);
    }

    /** False when the CLI ran past the timeout (it is stopped then). */
    private function waitFor(InvokedProcess $process, ?callable $heartbeat): bool
    {
        $deadline = microtime(true) + self::timeout();
        $beat = microtime(true);

        while ($process->running()) {
            if (microtime(true) > $deadline) {
                $process->signal(15); // SIGTERM

                return false;
            }

            if ($heartbeat !== null && microtime(true) - $beat >= 1.0) {
                $beat = microtime(true);
                $this->beat($process, $heartbeat);
            }

            usleep(100_000);
        }

        return true;
    }

    /** Runs the heartbeat; when it throws (the run was cancelled), the CLI is stopped first. */
    private function beat(InvokedProcess $process, callable $heartbeat): void
    {
        try {
            $heartbeat();
        } catch (\Throwable $e) {
            $process->signal(15); // SIGTERM

            throw $e;
        }
    }

    private static function timeout(): int
    {
        return max(30, (int) config('statamic-ai-assistant.claude_cli_timeout', 300));
    }

    /** @return list<string> */
    public function command(string $model, string $effort, string $systemFile): array
    {
        return [
            self::binary() ?? 'claude',
            '-p',
            '--input-format', 'stream-json',
            '--output-format', 'stream-json',
            '--verbose',
            '--model', $model,
            '--effort', $effort,
            '--system-prompt-file', $systemFile,
            '--tools', '',
            '--setting-sources', '',
            '--strict-mcp-config',
            '--no-session-persistence',
            '--max-turns', '1',
        ];
    }

    /** The `claude` binary: the configured one, else on PATH or in the usual install folders (PHP-FPM has a short PATH). */
    public static function binary(): ?string
    {
        $configured = trim((string) config('statamic-ai-assistant.claude_cli_path', ''));

        if ($configured !== '') {
            return $configured;
        }

        $home = self::home();

        foreach ([...explode(PATH_SEPARATOR, (string) getenv('PATH')), $home.'/.local/bin', '/opt/homebrew/bin', '/usr/local/bin'] as $dir) {
            if ($dir !== '' && is_executable($dir.'/claude')) {
                return $dir.'/claude';
            }
        }

        return null;
    }

    /** @return array<string, string|false> */
    private static function environment(): array
    {
        return array_filter([
            'ANTHROPIC_API_KEY' => false,
            'CLAUDECODE' => false,
            'CLAUDE_CODE_ENTRYPOINT' => false,
            // PHP-FPM may run without HOME; the CLI finds its sign-in there.
            'HOME' => getenv('HOME') === false ? self::home() : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private static function home(): string
    {
        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return $home;
        }

        return function_exists('posix_getpwuid') ? (string) (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '';
    }

    /** @return array<string, mixed>|null the last `result` event of the CLI's stream */
    public static function resultEvent(string $output): ?array
    {
        $result = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $event = json_decode(trim($line), true);

            if (is_array($event) && ($event['type'] ?? null) === 'result') {
                $result = $event;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{ok: bool, text: string, stop: string, error: string, model: ?string, usage: array{input: int, output: int, cache_write: int, cache_read: int}}
     */
    private static function outcome(array $event): array
    {
        $usage = (array) ($event['usage'] ?? []);
        $text = trim((string) ($event['result'] ?? ''));
        $ok = ($event['is_error'] ?? true) === false && ($event['subtype'] ?? '') === 'success';
        $models = array_keys((array) ($event['modelUsage'] ?? []));

        return [
            'ok' => $ok,
            'text' => $text,
            'stop' => (string) ($event['stop_reason'] ?? $event['subtype'] ?? ''),
            'error' => match (true) {
                $ok => '',
                $text !== '' => $text,
                default => (string) json_encode($event['errors'] ?? $event['subtype'] ?? 'error', JSON_UNESCAPED_UNICODE),
            },
            'model' => is_string($models[0] ?? null) ? $models[0] : null,
            'usage' => [
                'input' => (int) ($usage['input_tokens'] ?? 0),
                'output' => (int) ($usage['output_tokens'] ?? 0),
                'cache_write' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
                'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            ],
        ];
    }

    /** @return array{ok: bool, text: string, stop: string, error: string, model: ?string, usage: array{input: int, output: int, cache_write: int, cache_read: int}} */
    private static function failure(string $error): array
    {
        return ['ok' => false, 'text' => '', 'stop' => 'error', 'error' => $error, 'model' => null, 'usage' => ['input' => 0, 'output' => 0, 'cache_write' => 0, 'cache_read' => 0]];
    }
}
