<?php

namespace MotionWork\StatamicAiAssistant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use MotionWork\StatamicAiAssistant\Mcp\McpOAuth;

class McpOAuthController extends Controller
{
    public function __construct(private McpOAuth $oauth) {}

    public function protectedResource(): JsonResponse
    {
        return response()->json($this->oauth->protectedResource());
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json($this->oauth->authorizationServer());
    }

    public function register(Request $request): JsonResponse
    {
        $redirects = $request->input('redirect_uris', []);
        if (! is_array($redirects)) {
            $redirects = [];
        }

        $client = $this->oauth->registerClient(
            array_values($redirects),
            (string) $request->input('token_endpoint_auth_method', 'none'),
        );
        if ($client === null) {
            return response()->json([
                'error' => 'invalid_redirect_uri',
                'error_description' => 'This site only connects the apps its administrator allowed.',
            ], 400);
        }

        return response()->json(array_filter([
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'redirect_uris' => array_values($redirects),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $client['token_endpoint_auth_method'],
            'client_id_issued_at' => time(),
        ], fn ($value) => $value !== null), 201);
    }

    public function authorizeForm(Request $request): Response
    {
        $host = $this->oauth->redirectHost(
            (string) $request->query('client_id', ''),
            (string) $request->query('redirect_uri', ''),
        );

        return response()->view('statamic-ai-assistant::mcp-authorize', [
            'site' => (string) config('app.name'),
            'host' => $host,
            'error' => $host === null ? 'This sign-in request is not valid.' : null,
            'email' => '',
        ], $host === null ? 400 : 200);
    }

    public function authorizeSubmit(Request $request): Response|RedirectResponse
    {
        $result = $this->oauth->authorize(
            (string) $request->query('client_id', $request->input('client_id')),
            (string) $request->query('redirect_uri', $request->input('redirect_uri')),
            (string) $request->query('code_challenge', $request->input('code_challenge')),
            (string) $request->input('email'),
            (string) $request->input('password'),
            $request->query('state', $request->input('state')),
        );

        if (isset($result['redirect'])) {
            return redirect()->away($result['redirect']);
        }

        return response()->view('statamic-ai-assistant::mcp-authorize', [
            'site' => (string) config('app.name'),
            'host' => $this->oauth->redirectHost(
                (string) $request->query('client_id', $request->input('client_id')),
                (string) $request->query('redirect_uri', $request->input('redirect_uri')),
            ),
            'error' => $result['error'],
            'email' => (string) $request->input('email'),
        ], isset($result['error']) && str_contains($result['error'], 'not valid') ? 400 : 422);
    }

    public function token(Request $request): JsonResponse
    {
        $basicId = null;
        $basicSecret = null;
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Basic ')) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$basicId, $basicSecret] = explode(':', $decoded, 2);
            }
        }

        $input = $request->all();
        if ($basicId !== null && ! isset($input['client_id'])) {
            $input['client_id'] = $basicId;
        }

        $issued = $this->oauth->exchange($input, $basicSecret);
        if ($issued === null) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        return response()->json($issued);
    }
}
