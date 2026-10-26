<?php

namespace BoldWeb\StatamicAiAssistant\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth app credentials + flow for Figma.
 *
 * Client ID and client secret are read from the environment (see
 * config/statamic-ai-assistant.php: figma_oauth_client_id / figma_oauth_client_secret).
 * Do not store them in the Control Panel or in YAML.
 *
 * Per-user access tokens live in FigmaTokenStore.
 */
class FigmaOAuthService
{
    public const AUTH_URL = 'https://www.figma.com/oauth';

    public const TOKEN_URL = 'https://api.figma.com/v1/oauth/token';

    public const REFRESH_URL = 'https://api.figma.com/v1/oauth/refresh';

    /**
     * Space-separated OAuth scopes. Figma deprecated `files:read`; use granular scopes and
     * enable the same scopes on your app at figma.com/developers/apps → OAuth scopes.
     *
     * - file_content:read — GET /v1/files/… (what FigmaContentFetcher uses)
     * - current_user:read — GET /v1/me (used after token exchange for profile)
     */
    public const DEFAULT_SCOPE = 'file_content:read current_user:read';

    /** @var array{client_id: string, client_secret: string}|null */
    private ?array $cache = null;

    /**
     * @return array{client_id: string, client_secret: string}
     */
    public function getAppConfig(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = [
            'client_id' => trim((string) config('statamic-ai-assistant.figma_oauth_client_id', '')),
            'client_secret' => trim((string) config('statamic-ai-assistant.figma_oauth_client_secret', '')),
        ];
    }

    public function isConfigured(): bool
    {
        $cfg = $this->getAppConfig();

        return $cfg['client_id'] !== '' && $cfg['client_secret'] !== '';
    }

    /**
     * Redirect URI the admin must register inside figma.com/developers/apps.
     * Computed from the current app URL, so it always matches the current host.
     */
    public function redirectUri(): string
    {
        return route('statamic.cp.statamic-ai-assistant.block-hints.figma.callback');
    }

    /**
     * Build the Figma authorization URL the user will be redirected to.
     */
    public function buildAuthorizationUrl(string $state, string $scope = self::DEFAULT_SCOPE): string
    {
        $cfg = $this->getAppConfig();

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => $scope,
            'state' => $state,
            'response_type' => 'code',
        ]);
    }

    /**
     * Exchange an authorization code for an access token + user profile.
     *
     * @return array{
     *   access_token: string,
     *   refresh_token: string,
     *   expires_at: int,
     *   user: array{id: string, email: string, handle: string, img_url: string}
     * }
     *
     * @throws \RuntimeException on any failure (caller converts to user-friendly error).
     */
    public function exchangeCode(string $code): array
    {
        $cfg = $this->getAppConfig();

        if (! $this->isConfigured()) {
            throw new \RuntimeException(__('Figma is not configured.'));
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($cfg['client_id'], $cfg['client_secret'])
                ->timeout(15)
                ->post(self::TOKEN_URL, [
                    'redirect_uri' => $this->redirectUri(),
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Token exchange request failed: '.$e->getMessage());
        }

        if (! $response->ok()) {
            throw new \RuntimeException('Figma rejected the token exchange: '.$response->status().' '.$response->body());
        }

        $data = $response->json();

        if (! is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Figma response did not include an access_token.');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 0);

        $profile = $this->fetchUserProfile((string) $data['access_token']);

        return [
            'access_token' => (string) $data['access_token'],
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
            'expires_at' => $expiresIn > 0 ? time() + $expiresIn - 60 : 0,
            'user' => $profile,
        ];
    }

    /**
     * Refresh an expired access token.
     *
     * @return array{access_token: string, expires_at: int}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $cfg = $this->getAppConfig();

        if (! $this->isConfigured()) {
            throw new \RuntimeException(__('Figma is not configured.'));
        }

        $response = Http::asForm()
            ->withBasicAuth($cfg['client_id'], $cfg['client_secret'])
            ->timeout(15)
            ->post(self::REFRESH_URL, [
                'refresh_token' => $refreshToken,
            ]);

        if (! $response->ok()) {
            throw new \RuntimeException('Figma refresh failed: '.$response->status().' '.$response->body());
        }

        $data = $response->json();

        if (! is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Figma refresh response did not include an access_token.');
        }

        $expiresIn = (int) ($data['expires_in'] ?? 0);

        return [
            'access_token' => (string) $data['access_token'],
            'expires_at' => $expiresIn > 0 ? time() + $expiresIn - 60 : 0,
        ];
    }

    /**
     * @return array{id: string, email: string, handle: string, img_url: string}
     */
    public function fetchUserProfile(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)->timeout(10)->get('https://api.figma.com/v1/me');
        } catch (\Throwable $e) {
            return ['id' => '', 'email' => '', 'handle' => '', 'img_url' => ''];
        }

        if (! $response->ok()) {
            return ['id' => '', 'email' => '', 'handle' => '', 'img_url' => ''];
        }

        $me = $response->json();

        if (! is_array($me)) {
            return ['id' => '', 'email' => '', 'handle' => '', 'img_url' => ''];
        }

        return [
            'id' => (string) ($me['id'] ?? ''),
            'email' => (string) ($me['email'] ?? ''),
            'handle' => (string) ($me['handle'] ?? ''),
            'img_url' => (string) ($me['img_url'] ?? ''),
        ];
    }

    /**
     * Opaque, url-safe state token for CSRF protection during OAuth.
     */
    public function makeState(): string
    {
        return Str::random(40);
    }
}
