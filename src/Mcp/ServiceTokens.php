<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use MotionWork\StatamicAiAssistant\Support\YamlFile;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

/**
 * Long-lived bearer tokens for a service account (a control plane calling
 * /mcp without a person to sign in). Each token belongs to one Control Panel
 * user and acts exactly as that user: same role, same Claude level, same
 * policies. Only the SHA-256 of a token is stored; the plaintext is shown
 * once, when it is minted.
 *
 * Off unless `mcp_service_tokens` is true: then every token is refused and
 * /mcp behaves as before (OAuth only).
 *
 * File shape (default storage/app/statamic-ai-assistant/service-tokens.yaml):
 *   tokens:
 *     - name: mateo
 *       user_id: 3f2c…
 *       hash: 9b1d…        # sha256 of the token
 *       created_at: '2026-11-07T22:00:00+00:00'
 */
final class ServiceTokens
{
    /** Tells a service token from an OAuth access token at a glance. */
    public const PREFIX = 'sai_svc_';

    public static function enabled(): bool
    {
        return (bool) config('statamic-ai-assistant.mcp_service_tokens', false);
    }

    public function storagePath(): string
    {
        $path = config('statamic-ai-assistant.mcp_service_tokens_path');
        if (! is_string($path) || $path === '') {
            return storage_path('app/statamic-ai-assistant/service-tokens.yaml');
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    /**
     * The account a request's bearer token belongs to, or null (also when
     * service tokens are off or the user no longer exists).
     */
    public function actor(Request $request): ?UserContract
    {
        $token = (string) $request->bearerToken();
        if (! self::enabled() || ! str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $hash = hash('sha256', $token);
        foreach ($this->records() as $record) {
            if (hash_equals($record['hash'], $hash)) {
                return User::find($record['user_id']);
            }
        }

        return null;
    }

    /**
     * Mint a token for the user and return its plaintext (stored hashed). A
     * token of the same name for the same user is replaced, so minting again
     * rotates it.
     */
    public function mint(UserContract $user, string $name): string
    {
        $token = self::PREFIX.Str::random(48);
        $records = $this->without((string) $user->id(), $name);
        $records[] = [
            'name' => $name,
            'user_id' => (string) $user->id(),
            'hash' => hash('sha256', $token),
            'created_at' => now()->toIso8601String(),
        ];
        $this->write($records);

        return $token;
    }

    /**
     * Remove the user's tokens of that name; returns how many were removed.
     */
    public function revoke(UserContract $user, string $name): int
    {
        $before = count($this->records());
        $after = $this->without((string) $user->id(), $name);
        $this->write($after);

        return $before - count($after);
    }

    /**
     * @return array<int, array{name: string, user_id: string, hash: string, created_at: string}>
     */
    private function records(): array
    {
        $records = [];
        foreach ((array) (YamlFile::read($this->storagePath())['tokens'] ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['hash'] ?? null) || $row['hash'] === '' || ! isset($row['user_id'])) {
                continue;
            }
            $records[] = [
                'name' => (string) ($row['name'] ?? ''),
                'user_id' => (string) $row['user_id'],
                'hash' => $row['hash'],
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $records;
    }

    /**
     * @return array<int, array{name: string, user_id: string, hash: string, created_at: string}>
     */
    private function without(string $userId, string $name): array
    {
        return array_values(array_filter(
            $this->records(),
            fn (array $record) => $record['user_id'] !== $userId || $record['name'] !== $name,
        ));
    }

    /**
     * @param  array<int, array<string, string>>  $records
     */
    private function write(array $records): void
    {
        $path = $this->storagePath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, YAML::dump(['tokens' => array_values($records)]));
        // Only hashes, but nobody besides the app needs to read them.
        chmod($path, 0600);
    }
}
