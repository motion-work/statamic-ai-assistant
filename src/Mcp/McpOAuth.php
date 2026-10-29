<?php

namespace MotionWork\StatamicAiAssistant\Mcp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

/**
 * OAuth for the Claude app.
 *
 * Claude's servers call this site. A translator signs in with any Control
 * Panel account. An email that is not a Control Panel account is refused,
 * including addresses on the company domain.
 */
class McpOAuth
{
    private const PREFIX = 'statamic-ai-assistant:mcp-oauth:';

    public function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public function resource(): string
    {
        return $this->issuer().'/mcp';
    }

    public function metadataUrl(): string
    {
        return $this->issuer().'/.well-known/oauth-protected-resource/mcp';
    }

    /**
     * @return array<string, mixed>
     */
    public function protectedResource(): array
    {
        return [
            'resource' => $this->resource(),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => ['translations'],
            'bearer_methods_supported' => ['header'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizationServer(): array
    {
        $issuer = $this->issuer();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/mcp/oauth/authorize',
            'token_endpoint' => $issuer.'/mcp/oauth/token',
            'registration_endpoint' => $issuer.'/mcp/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => ['translations'],
        ];
    }

    /**
     * @param  array<int, string>  $redirects
     * @return array{client_id: string, client_secret: string}|null
     */
    public function registerClient(array $redirects): ?array
    {
        $allowed = array_values(array_filter($redirects, fn ($uri) => is_string($uri) && $this->redirectIsAllowed($uri)));
        if ($allowed === []) {
            return null;
        }

        $id = (string) Str::uuid();
        $secret = Str::random(48);
        Cache::put(self::PREFIX.'client:'.$id, [
            'secret' => hash('sha256', $secret),
            'redirects' => $allowed,
        ], now()->addDays(90));

        return ['client_id' => $id, 'client_secret' => $secret];
    }

    /**
     * @return array{redirect: string}|array{error: string}
     */
    public function authorize(string $clientId, string $redirectUri, string $challenge, string $email, string $password, ?string $state): array
    {
        $client = $this->client($clientId);
        if ($client === null || ! in_array($redirectUri, $client['redirects'], true) || $challenge === '') {
            return ['error' => 'This sign-in request is not valid.'];
        }

        if (! $this->enabled()) {
            return ['error' => 'The Claude connector is not enabled on this site.'];
        }

        $user = $this->userByEmail($email);
        $hash = $user ? (string) $user->password() : '';
        $passwordMatches = $hash !== '' && Hash::check($password, $hash);
        if ($user === null || ! $passwordMatches) {
            return ['error' => 'Those credentials were not accepted.'];
        }

        $code = Str::random(48);
        Cache::put(self::PREFIX.'code:'.$code, [
            'client_id' => $clientId,
            'redirect' => $redirectUri,
            'challenge' => $challenge,
            'user_id' => (string) $user->id(),
        ], now()->addMinutes(10));

        $query = http_build_query(array_filter([
            'code' => $code,
            'state' => $state,
        ], fn ($value) => is_string($value) && $value !== ''));

        return ['redirect' => $redirectUri.(str_contains($redirectUri, '?') ? '&' : '?').$query];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public function exchange(array $input, ?string $basicSecret): ?array
    {
        $clientId = (string) ($input['client_id'] ?? '');
        $client = $this->client($clientId);
        if ($client === null || ! $this->secretMatches($client, (string) ($input['client_secret'] ?? $basicSecret ?? ''))) {
            return null;
        }

        $grant = (string) ($input['grant_type'] ?? '');
        if ($grant === 'authorization_code') {
            return $this->exchangeCode($clientId, $input);
        }
        if ($grant === 'refresh_token') {
            return $this->exchangeRefresh($clientId, (string) ($input['refresh_token'] ?? ''));
        }

        return null;
    }

    public function actor(Request $request): ?UserContract
    {
        $token = (string) $request->bearerToken();
        $record = $token === '' ? null : Cache::get(self::PREFIX.'access:'.$token);
        if (! is_array($record)) {
            return null;
        }

        return User::find($record['user_id'] ?? '');
    }

    public function enabled(): bool
    {
        return true;
    }

    public function requestIsAllowed(Request $request): bool
    {
        return $this->actor($request) !== null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function exchangeCode(string $clientId, array $input): ?array
    {
        $code = (string) ($input['code'] ?? '');
        $record = Cache::pull(self::PREFIX.'code:'.$code);
        if (! is_array($record) || ($record['client_id'] ?? null) !== $clientId) {
            return null;
        }
        if (($record['redirect'] ?? null) !== ($input['redirect_uri'] ?? null)) {
            return null;
        }
        if (! $this->pkce((string) ($input['code_verifier'] ?? ''), (string) ($record['challenge'] ?? ''))) {
            return null;
        }

        return $this->issue((string) $record['user_id'], $clientId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exchangeRefresh(string $clientId, string $refresh): ?array
    {
        $record = Cache::pull(self::PREFIX.'refresh:'.$refresh);
        if (! is_array($record) || ($record['client_id'] ?? null) !== $clientId) {
            return null;
        }

        return $this->issue((string) $record['user_id'], $clientId);
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $userId, string $clientId): array
    {
        $access = Str::random(48);
        $refresh = Str::random(48);
        Cache::put(self::PREFIX.'access:'.$access, [
            'user_id' => $userId,
            'client_id' => $clientId,
        ], now()->addHours(12));
        Cache::put(self::PREFIX.'refresh:'.$refresh, [
            'user_id' => $userId,
            'client_id' => $clientId,
        ], now()->addDays(30));

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => 43200,
            'refresh_token' => $refresh,
        ];
    }

    /**
     * @return array{secret: string, redirects: array<int, string>}|null
     */
    private function client(string $id): ?array
    {
        $client = $id === '' ? null : Cache::get(self::PREFIX.'client:'.$id);

        return is_array($client) ? $client : null;
    }

    /**
     * @param  array{secret: string, redirects: array<int, string>}  $client
     */
    private function secretMatches(array $client, string $presented): bool
    {
        if ($presented === '') {
            return true;
        }

        return hash_equals($client['secret'], hash('sha256', $presented));
    }

    private function pkce(string $verifier, string $challenge): bool
    {
        if ($verifier === '' || $challenge === '') {
            return false;
        }

        $hashed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $hashed);
    }

    private function redirectIsAllowed(string $uri): bool
    {
        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $parts['scheme'] === 'http';
        }

        return $parts['scheme'] === 'https';
    }

    private function userByEmail(string $email): ?UserContract
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        foreach (User::all() as $user) {
            if (strtolower((string) $user->email()) === $email) {
                return $user;
            }
        }

        return null;
    }
}
