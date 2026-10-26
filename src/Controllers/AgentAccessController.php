<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Super-admin-only management of the AI agent access configuration
 * (which roles / users may use each capability, plus the agent entry limits).
 */
class AgentAccessController
{
    public function __construct(private AgentAccessStore $store) {}

    /**
     * Return all roles + users to choose from, the current grants, and the cap
     * ceiling so the UI can bound the limit inputs.
     */
    public function index(): JsonResponse
    {
        abort_unless(AgentAccess::canManage(), 403);

        return response()->json([
            'roles' => Role::all()->map(fn ($role) => [
                'handle' => $role->handle(),
                'title' => $role->title(),
            ])->values()->all(),
            // Super admins already have full access, so there's nothing to grant them.
            'users' => User::all()
                ->reject(fn ($user) => $user->isSuper())
                ->map(fn ($user) => [
                    'id' => (string) $user->id(),
                    'name' => (string) ($user->name() ?: $user->email()),
                    'email' => (string) $user->email(),
                ])->values()->all(),
            'access' => $this->store->all(),
            'ceiling' => EntryCreationPolicy::configuredMaxPlanEntries(),
        ]);
    }

    /**
     * Persist the submitted access grants + agent limits.
     */
    public function save(Request $request): JsonResponse
    {
        abort_unless(AgentAccess::canManage(), 403);

        $ceiling = EntryCreationPolicy::configuredMaxPlanEntries();

        $data = $request->validate([
            'access' => 'required|array',
            'access.*.roles' => 'sometimes|array',
            'access.*.roles.*' => 'string',
            'access.*.users' => 'sometimes|array',
            'access.*.users.*' => 'string',
            'access.agent.limits.default' => 'sometimes|integer|min:1|max:'.$ceiling,
            'access.agent.limits.roles' => 'sometimes|array',
            'access.agent.limits.roles.*' => 'integer|min:1|max:'.$ceiling,
            'access.agent.limits.users' => 'sometimes|array',
            'access.agent.limits.users.*' => 'integer|min:1|max:'.$ceiling,
        ]);

        $this->store->save($data['access']);

        return response()->json([
            'success' => true,
            'access' => $this->store->all(),
        ]);
    }
}
