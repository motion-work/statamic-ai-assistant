<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use Illuminate\Support\Facades\Cache;
use MotionWork\StatamicAiAssistant\Mcp\McpOAuth;
use MotionWork\StatamicAiAssistant\Tests\TestCase;
use Statamic\Facades\User;

class McpHttpTest extends TestCase
{
    public function test_a_shared_token_is_not_a_control_panel_account(): void
    {
        config(['app.url' => 'https://cms.example']);

        $denied = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], ['Authorization' => 'Bearer site-token']);

        $denied->assertUnauthorized();
        $denied->assertJsonPath('message', 'Sign in with a Control Panel account.');
    }

    public function test_the_claude_app_must_authenticate_before_it_can_call_tools(): void
    {
        config(['app.url' => 'https://cms.example']);

        $denied = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $denied->assertUnauthorized();
        $this->assertStringContainsString(
            'https://cms.example/.well-known/oauth-protected-resource/mcp',
            (string) $denied->headers->get('WWW-Authenticate'),
        );

        $metadata = $this->getJson('/.well-known/oauth-protected-resource/mcp');
        $metadata->assertOk();
        $metadata->assertJsonPath('resource', 'https://cms.example/mcp');
        $metadata->assertJsonPath('authorization_servers.0', 'https://cms.example');
    }

    public function test_claude_can_register_and_the_sign_in_page_is_offered(): void
    {
        $registered = $this->postJson('/mcp/oauth/register', [
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $registered->assertCreated();
        $this->assertNotSame('', $registered->json('client_id'));

        $this->assertNull($registered->json('client_secret'), 'A public client gets no secret.');

        $page = $this->get('/mcp/oauth/authorize?'.http_build_query([
            'client_id' => $registered->json('client_id'),
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        ]));
        $page->assertOk();
        $page->assertSee('claude.ai');
        $page->assertSee('name="password"', false);
    }

    public function test_a_stranger_cannot_register_a_client_that_sends_the_code_to_their_server(): void
    {
        $this->postJson('/mcp/oauth/register', [
            'redirect_uris' => ['https://attacker.example/cb'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

        $this->postJson('/mcp/oauth/register', [
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback', 'https://attacker.example/cb'],
        ])->assertStatus(400);

        config(['statamic-ai-assistant.mcp_redirect_hosts' => ['claude.ai', 'chatgpt.com']]);
        $this->postJson('/mcp/oauth/register', [
            'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
        ])->assertCreated();
    }

    public function test_a_client_registered_for_a_host_no_longer_allowed_cannot_sign_in(): void
    {
        $oauth = $this->app->make(McpOAuth::class);
        config(['statamic-ai-assistant.mcp_redirect_hosts' => ['claude.ai', 'chatgpt.com']]);
        $client = $oauth->registerClient(['https://chatgpt.com/connector_platform_oauth_redirect']);
        config(['statamic-ai-assistant.mcp_redirect_hosts' => ['claude.ai']]);

        $page = $this->get('/mcp/oauth/authorize?'.http_build_query([
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
        ]));
        $page->assertStatus(400);
        $page->assertDontSee('name="password"', false);

        $result = $oauth->authorize($client['client_id'], 'https://chatgpt.com/connector_platform_oauth_redirect', 'challenge', 'x@globex.io', 'pw', null);
        $this->assertSame('This sign-in request is not valid.', $result['error']);
    }

    public function test_connected_apps_survive_clearing_the_default_cache_in_their_own_store(): void
    {
        $callback = 'https://claude.ai/api/mcp/auth_callback';
        $oauth = $this->app->make(McpOAuth::class);

        $shared = $oauth->registerClient([$callback]);
        Cache::flush();
        $this->assertNull($oauth->redirectHost($shared['client_id'], $callback), 'In the default store a cache:clear signs the app out.');

        config(['cache.stores.mcp_test' => ['driver' => 'array'], 'statamic-ai-assistant.mcp_cache_store' => 'mcp_test']);
        $own = $oauth->registerClient([$callback]);
        Cache::flush();
        $this->assertSame('claude.ai', $oauth->redirectHost($own['client_id'], $callback));
    }

    public function test_a_confidential_client_must_present_its_secret(): void
    {
        $translator = User::make()->id('translator-secret')->email('secret@freelance.example');
        $translator->password('correct-horse');
        $translator->save();

        try {
            $registered = $this->postJson('/mcp/oauth/register', [
                'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
                'token_endpoint_auth_method' => 'client_secret_post',
            ]);
            $registered->assertJsonPath('token_endpoint_auth_method', 'client_secret_post');
            $secret = $registered->json('client_secret');
            $this->assertNotEmpty($secret);

            $exchange = function (?string $presented) use ($registered) {
                $verifier = str_repeat('s', 64);
                $signIn = $this->post('/mcp/oauth/authorize?'.http_build_query([
                    'client_id' => $registered->json('client_id'),
                    'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                    'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                ]), ['email' => 'secret@freelance.example', 'password' => 'correct-horse']);
                parse_str((string) parse_url((string) $signIn->headers->get('Location'), PHP_URL_QUERY), $query);

                return $this->postJson('/mcp/oauth/token', array_filter([
                    'grant_type' => 'authorization_code',
                    'client_id' => $registered->json('client_id'),
                    'client_secret' => $presented,
                    'code' => $query['code'],
                    'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                    'code_verifier' => $verifier,
                ]));
            };

            $exchange(null)->assertStatus(400);
            $exchange('wrong')->assertStatus(400);
            $exchange($secret)->assertOk();
        } finally {
            $translator->delete();
        }
    }

    public function test_only_a_control_panel_account_can_sign_in(): void
    {
        $editor = User::make()
            ->id('editor-globex')
            ->email('editor@globex.io');
        $editor->password('correct-horse');
        $editor->save();

        try {
            $oauth = $this->app->make(McpOAuth::class);
            $client = $oauth->registerClient(['https://claude.ai/api/mcp/auth_callback']);
            $this->assertNotNull($client);

            $outsider = $oauth->authorize(
                $client['client_id'],
                'https://claude.ai/api/mcp/auth_callback',
                'challenge',
                'someone@globex.io',
                'correct-horse',
                null,
            );
            $this->assertSame('Those credentials were not accepted.', $outsider['error']);

            $wrongPassword = $oauth->authorize(
                $client['client_id'],
                'https://claude.ai/api/mcp/auth_callback',
                'challenge',
                'editor@globex.io',
                'nope',
                null,
            );
            $this->assertSame('Those credentials were not accepted.', $wrongPassword['error']);

            $signedIn = $oauth->authorize(
                $client['client_id'],
                'https://claude.ai/api/mcp/auth_callback',
                'challenge',
                'editor@globex.io',
                'correct-horse',
                null,
            );
            $this->assertArrayHasKey('redirect', $signedIn);
            $this->assertStringStartsWith('https://claude.ai/api/mcp/auth_callback?code=', $signedIn['redirect']);
        } finally {
            $editor->delete();
        }
    }

    public function test_a_control_panel_account_on_any_domain_can_call_tools_until_it_is_deleted(): void
    {
        $translator = User::make()
            ->id('translator-elsewhere')
            ->email('translator@freelance.example');
        $translator->password('correct-horse');
        $translator->save();

        try {
            $registered = $this->postJson('/mcp/oauth/register', [
                'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            ]);
            $clientId = $registered->json('client_id');
            $verifier = str_repeat('v', 64);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            $signIn = $this->post('/mcp/oauth/authorize?'.http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'code_challenge' => $challenge,
            ]), ['email' => 'translator@freelance.example', 'password' => 'correct-horse']);
            $signIn->assertRedirect();
            parse_str((string) parse_url((string) $signIn->headers->get('Location'), PHP_URL_QUERY), $query);

            $token = $this->postJson('/mcp/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'code' => $query['code'],
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'code_verifier' => $verifier,
            ]);
            $token->assertOk();
            $headers = ['Authorization' => 'Bearer '.$token->json('access_token')];
            $listTools = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

            $this->postJson('/mcp', $listTools, $headers)->assertOk();

            $translator->delete();

            $this->postJson('/mcp', $listTools, $headers)->assertUnauthorized();
        } finally {
            $translator->delete();
        }
    }

    public function test_a_client_can_authenticate_with_http_basic_alone(): void
    {
        $translator = User::make()
            ->id('translator-basic')
            ->email('basic@freelance.example');
        $translator->password('correct-horse');
        $translator->save();

        try {
            $registered = $this->postJson('/mcp/oauth/register', [
                'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
                'token_endpoint_auth_method' => 'client_secret_basic',
            ]);
            $clientId = $registered->json('client_id');
            $secret = $registered->json('client_secret');
            $verifier = str_repeat('b', 64);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

            $signIn = $this->post('/mcp/oauth/authorize?'.http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'code_challenge' => $challenge,
            ]), ['email' => 'basic@freelance.example', 'password' => 'correct-horse']);
            parse_str((string) parse_url((string) $signIn->headers->get('Location'), PHP_URL_QUERY), $query);

            $token = $this->postJson('/mcp/oauth/token', [
                'grant_type' => 'authorization_code',
                'code' => $query['code'],
                'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
                'code_verifier' => $verifier,
            ], ['Authorization' => 'Basic '.base64_encode($clientId.':'.$secret)]);

            $token->assertOk();
            $this->assertNotSame('', (string) $token->json('access_token'));
        } finally {
            $translator->delete();
        }
    }
}
