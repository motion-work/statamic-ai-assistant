<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use Statamic\Facades\User;

/**
 * The user's advanced-tools toggle in the AI agent's top bar.
 */
final class AgentPreferenceController
{
    /**
     * Current advanced-tools state for the agent UI toggle: whether the user
     * holds the access grant at all (toggle hidden otherwise) and whether they
     * have opted in.
     */
    public function advancedToolsPreference(): JsonResponse
    {
        return response()->json([
            'granted' => AgentAccess::allows('advanced_tools'),
            'enabled' => AgentAccess::advancedToolsActive(),
        ]);
    }

    /**
     * Persist the per-user advanced-tools opt-in (survives sessions — it is a
     * Statamic user preference). The grant itself is still required; the
     * preference alone activates nothing.
     */
    public function saveAdvancedToolsPreference(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);

        $user = User::current();
        if (! $user || ! AgentAccess::allows('advanced_tools')) {
            return response()->json(['error' => __('Not allowed.')], 403);
        }

        $user->setPreference(AgentAccess::ADVANCED_TOOLS_PREFERENCE, (bool) $data['enabled'])->save();

        return response()->json([
            'granted' => true,
            'enabled' => AgentAccess::advancedToolsActive($user),
        ]);
    }
}
