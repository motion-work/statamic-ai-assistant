<?php

namespace MotionWork\StatamicAiAssistant\Http\Controllers;

use MotionWork\StatamicAiAssistant\Mcp\McpOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

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

        $client = $this->oauth->registerClient(array_values($redirects));
        if ($client === null) {
            return response()->json(['error' => 'invalid_redirect_uri'], 400);
        }

        return response()->json([
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'redirect_uris' => array_values($redirects),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'client_id_issued_at' => time(),
        ], 201);
    }

    public function authorizeForm(Request $request): Response
    {
        return response()->view('statamic-ai-assistant::mcp-authorize', [
            'site' => (string) config('app.name'),
            'error' => null,
            'email' => '',
        ]);
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
            'error' => $result['error'],
            'email' => (string) $request->input('email'),
        ], isset($result['error']) && str_contains($result['error'], 'not valid') ? 400 : 422);
    }

    public function token(Request $request): JsonResponse
    {
        $basic = null;
        $header = (string) $request->header('Authorization', '');
        if (str_starts_with($header, 'Basic ')) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                $basic = explode(':', $decoded, 2)[1];
            }
        }

        $issued = $this->oauth->exchange($request->all(), $basic);
        if ($issued === null) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }

        return response()->json($issued);
    }
}
