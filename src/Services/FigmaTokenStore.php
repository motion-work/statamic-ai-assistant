<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;
use Statamic\Facades\YAML;

/**
 * Per-user Figma OAuth token persistence.
 *
 * File layout:
 *   storage/app/statamic-ai-assistant/figma-tokens/{user_id}.yaml
 *
 * Each token file:
 *   access_token: "..."
 *   refresh_token: "..."
 *   expires_at: 1234567890
 *   user:
 *     id: "..."
 *     email: "..."
 *     handle: "..."
 *     img_url: "..."
 */
class FigmaTokenStore
{
    public function baseDir(): string
    {
        return storage_path('app/statamic-ai-assistant/figma-tokens');
    }

    public function pathFor(string $userId): string
    {
        // Sanitize — user IDs in Statamic are usually UUID-like, but be defensive.
        $safe = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $userId);

        return $this->baseDir().'/'.$safe.'.yaml';
    }

    public function currentUserId(): ?string
    {
        $user = User::current();

        if (! $user) {
            return null;
        }

        return (string) $user->id();
    }

    /**
     * @return array{
     *   access_token: string,
     *   refresh_token: string,
     *   expires_at: int,
     *   user: array{id: string, email: string, handle: string, img_url: string}
     * }|null
     */
    public function getFor(string $userId): ?array
    {
        $path = $this->pathFor($userId);

        if (! is_file($path)) {
            return null;
        }

        try {
            $data = YAML::parse((string) file_get_contents($path));
        } catch (\Throwable $e) {
            Log::warning('Failed to parse figma token file', ['user' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($data) || empty($data['access_token'])) {
            return null;
        }

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expires_at' => (int) ($data['expires_at'] ?? 0),
            'user' => [
                'id' => (string) ($data['user']['id'] ?? ''),
                'email' => (string) ($data['user']['email'] ?? ''),
                'handle' => (string) ($data['user']['handle'] ?? ''),
                'img_url' => (string) ($data['user']['img_url'] ?? ''),
            ],
        ];
    }

    /**
     * @return array{
     *   access_token: string,
     *   refresh_token: string,
     *   expires_at: int,
     *   user: array{id: string, email: string, handle: string, img_url: string}
     * }|null
     */
    public function getForCurrentUser(): ?array
    {
        $userId = $this->currentUserId();

        return $userId === null ? null : $this->getFor($userId);
    }

    /**
     * @param  array{
     *   access_token: string,
     *   refresh_token?: string,
     *   expires_at?: int,
     *   user?: array<string, mixed>
     * }  $payload
     */
    public function saveFor(string $userId, array $payload): void
    {
        $dir = $this->baseDir();

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $this->pathFor($userId);

        $existing = $this->getFor($userId) ?? [];

        $merged = [
            'access_token' => (string) ($payload['access_token'] ?? $existing['access_token'] ?? ''),
            'refresh_token' => (string) ($payload['refresh_token'] ?? $existing['refresh_token'] ?? ''),
            'expires_at' => (int) ($payload['expires_at'] ?? $existing['expires_at'] ?? 0),
            'user' => [
                'id' => (string) ($payload['user']['id'] ?? $existing['user']['id'] ?? ''),
                'email' => (string) ($payload['user']['email'] ?? $existing['user']['email'] ?? ''),
                'handle' => (string) ($payload['user']['handle'] ?? $existing['user']['handle'] ?? ''),
                'img_url' => (string) ($payload['user']['img_url'] ?? $existing['user']['img_url'] ?? ''),
            ],
        ];

        file_put_contents($path, YAML::dump($merged));

        @chmod($path, 0600);
    }

    public function deleteFor(string $userId): void
    {
        $path = $this->pathFor($userId);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function deleteForCurrentUser(): void
    {
        $userId = $this->currentUserId();

        if ($userId !== null) {
            $this->deleteFor($userId);
        }
    }

    public function isExpired(array $token): bool
    {
        $expiresAt = (int) ($token['expires_at'] ?? 0);

        return $expiresAt > 0 && $expiresAt <= time();
    }
}
