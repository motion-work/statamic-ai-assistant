<?php

namespace MotionWork\StatamicAiAssistant\Tests\Mcp;

use MotionWork\StatamicAiAssistant\Mcp\McpOAuth;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

class McpHttpTest extends TestCase
{
    public function test_the_connector_is_absent_until_a_domain_is_configured(): void
    {
        config([
            'app.url' => 'https://cms.example',
            'statamic-ai-assistant.mcp_email_domain' => '',
            'statamic-ai-assistant.mcp_token' => 'site-token',
        ]);

        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], ['Authorization' => 'Bearer site-token'])->assertNotFound();

        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertNotFound();
        $this->postJson('/mcp/oauth/register', [
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertNotFound();
    }

    public function test_the_claude_app_must_authenticate_before_it_can_call_tools(): void
    {
        config([
            'app.url' => 'https://cms.example',
            'statamic-ai-assistant.mcp_email_domain' => 'khamso.ch',
        ]);

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
        config(['statamic-ai-assistant.mcp_email_domain' => 'khamso.ch']);

        $registered = $this->postJson('/mcp/oauth/register', [
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $registered->assertCreated();
        $this->assertNotSame('', $registered->json('client_id'));

        $page = $this->get('/mcp/oauth/authorize?client_id='.$registered->json('client_id'));
        $page->assertOk();
        $page->assertSee('Connect Claude');
    }

    public function test_sign_in_is_limited_to_the_configured_email_domain(): void
    {
        config([
            'statamic-ai-assistant.mcp_email_domain' => 'khamso.ch',
            'statamic-ai-assistant.mcp_token' => 'site-token',
        ]);

        $denied = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], ['Authorization' => 'Bearer site-token']);
        $denied->assertUnauthorized();

        $oauth = $this->app->make(McpOAuth::class);
        $client = $oauth->registerClient(['https://claude.ai/api/mcp/auth_callback']);
        $this->assertNotNull($client);

        $other = $oauth->authorize(
            $client['client_id'],
            'https://claude.ai/api/mcp/auth_callback',
            'challenge',
            'person@gmail.com',
            'secret',
            null,
        );
        $this->assertSame('This site only accepts @khamso.ch accounts.', $other['error']);

        $sameDomain = $oauth->authorize(
            $client['client_id'],
            'https://claude.ai/api/mcp/auth_callback',
            'challenge',
            'person@khamso.ch',
            'secret',
            null,
        );
        $this->assertSame('Those credentials were not accepted, or this account cannot use translations.', $sameDomain['error']);
    }
}
