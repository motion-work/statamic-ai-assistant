<?php

namespace BoldWeb\StatamicAiAssistant\Controllers;

use BoldWeb\StatamicAiAssistant\Services\FigmaOAuthService;
use BoldWeb\StatamicAiAssistant\Services\FigmaTokenStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Statamic\Facades\User;

class FigmaOAuthController
{
    private const SESSION_STATE_KEY = 'statamic_ai_assistant_figma_oauth_state';

    public function status(FigmaOAuthService $oauth, FigmaTokenStore $tokens): JsonResponse
    {
        $user = User::current();

        if (! $user) {
            return response()->json(['error' => __('Not authenticated.')], 401);
        }

        $cfg = $oauth->getAppConfig();
        $stored = $tokens->getForCurrentUser();
        $connected = $stored !== null && ($stored['access_token'] ?? '') !== '';
        $hasClientId = ($cfg['client_id'] ?? '') !== '';
        $hasClientSecret = ($cfg['client_secret'] ?? '') !== '';

        return response()->json([
            'app_configured' => $oauth->isConfigured(),
            'redirect_uri' => $oauth->redirectUri(),
            'connected' => $connected,
            'is_super' => $user->isSuper(),
            'has_client_id' => $hasClientId,
            'has_client_secret' => $hasClientSecret,
            'figma_user' => $connected ? [
                'handle' => $stored['user']['handle'] ?? '',
                'email' => $stored['user']['email'] ?? '',
            ] : null,
        ]);
    }

    public function connect(FigmaOAuthService $oauth): RedirectResponse
    {
        $user = User::current();

        if (! $user) {
            return redirect()->route('statamic.cp.statamic-ai-assistant.block-hints.page')
                ->with('error', __('You must be logged in.'));
        }

        if (! $oauth->isConfigured()) {
            return redirect()->route('statamic.cp.statamic-ai-assistant.block-hints.page')
                ->with('error', __('Figma OAuth is not configured. Set STATAMIC_AI_ASSISTANT_FIGMA_OAUTH_CLIENT_ID and STATAMIC_AI_ASSISTANT_FIGMA_OAUTH_CLIENT_SECRET in your .env file.'));
        }

        $state = $oauth->makeState();
        Session::put(self::SESSION_STATE_KEY, $state);

        return redirect()->away($oauth->buildAuthorizationUrl($state));
    }

    public function callback(Request $request, FigmaOAuthService $oauth, FigmaTokenStore $tokens): RedirectResponse
    {
        $user = User::current();

        if (! $user) {
            return redirect()->route('statamic.cp.statamic-ai-assistant.block-hints.page')
                ->with('error', __('You must be logged in.'));
        }

        $sessionState = Session::pull(self::SESSION_STATE_KEY);
        $state = $request->query('state');

        if (! is_string($state) || $sessionState === null || ! hash_equals((string) $sessionState, $state)) {
            return redirect()->route('statamic.cp.statamic-ai-assistant.block-hints.page')
                ->with('error', __('Invalid OAuth state. Try connecting again.'));
        }

        if ($request->query('error')) {
            return redirect()->route('statamic.cp.statamic-ai-assistant.block-hints.page')
                ->with('error', __('Figma authorization was denied or failed.'));
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('statamic.cp.statamic-ai-assistant.block-hints.page')
                ->with('error', __('No authorization code received from Figma.'));
        }

        try {
            $data = $oauth->exchangeCode($code);
        } catch (\Throwable $e) {
            Log::warning('Figma OAuth code exchange failed', ['error' => $e->getMessage()]);

            return redirect()->route('statamic.cp.statamic-ai-assistant.block-hints.page')
                ->with('error', $e->getMessage());
        }

        $tokens->saveFor((string) $user->id(), [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at' => $data['expires_at'],
            'user' => $data['user'],
        ]);

        return redirect()->route('statamic.cp.statamic-ai-assistant.block-hints.page')
            ->with('success', __('Connected to Figma. Design links in prompts will be fetched when you generate content.'));
    }

    public function disconnect(FigmaTokenStore $tokens): JsonResponse
    {
        $user = User::current();

        if (! $user) {
            return response()->json(['error' => __('Not authenticated.')], 401);
        }

        $tokens->deleteForCurrentUser();

        return response()->json(['success' => true]);
    }
}
