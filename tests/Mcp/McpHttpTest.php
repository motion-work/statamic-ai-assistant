<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

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

        $page = $this->get('/mcp/oauth/authorize?client_id='.$registered->json('client_id'));
        $page->assertOk();
        $page->assertSee('Connect Claude');
    }

    public function test_only_a_control_panel_account_can_sign_in(): void
    {
        $editor = User::make()
            ->id('editor-woodkit')
            ->email('editor@woodkit.ch');
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
                'someone@homie.io',
                'correct-horse',
                null,
            );
            $this->assertSame('Those credentials were not accepted.', $outsider['error']);

            $wrongPassword = $oauth->authorize(
                $client['client_id'],
                'https://claude.ai/api/mcp/auth_callback',
                'challenge',
                'editor@woodkit.ch',
                'nope',
                null,
            );
            $this->assertSame('Those credentials were not accepted.', $wrongPassword['error']);

            $signedIn = $oauth->authorize(
                $client['client_id'],
                'https://claude.ai/api/mcp/auth_callback',
                'challenge',
                'editor@woodkit.ch',
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
}
