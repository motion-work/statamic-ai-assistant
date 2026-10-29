<?php

namespace MotionWork\StatamicAiAssistant\Http\Controllers;

use MotionWork\StatamicAiAssistant\Mcp\McpOAuth;
use MotionWork\StatamicAiAssistant\Mcp\TranslationMcpServer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class McpController extends Controller
{
    public function __construct(
        private McpOAuth $oauth,
        private TranslationMcpServer $server,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $this->oauth->enabled()) {
            abort(404);
        }

        if ($request->isMethod('DELETE')) {
            return response('', 204);
        }

        if ($request->isMethod('GET')) {
            return response('', 405, ['Allow' => 'POST, DELETE']);
        }

        if (! $this->oauth->requestIsAllowed($request)) {
            return response(json_encode([
                'error' => 'unauthorized',
                'message' => $this->oauth->allowsAnyStatamicUser()
                    ? 'Sign in with a Control Panel account.'
                    : 'Sign in with a Control Panel account on the email domain configured for this site.',
            ]), 401, [
                'Content-Type' => 'application/json',
                'WWW-Authenticate' => 'Bearer resource_metadata="'.$this->oauth->metadataUrl().'"',
                'Cache-Control' => 'no-store',
            ]);
        }

        $actor = $this->oauth->actor($request);
        if ($actor !== null) {
            $this->server->bridge()->setActor($actor);
        }

        $payload = $request->json()->all();
        if (! is_array($payload) || ($payload['jsonrpc'] ?? null) !== '2.0') {
            return $this->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error'],
            ], 400);
        }

        $result = $this->server->dispatch($payload);
        if ($result === null) {
            return response('', 202);
        }

        return $this->json($result, 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $status): Response
    {
        return response(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
        ]);
    }
}
