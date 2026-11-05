<?php

namespace MotionWork\StatamicAiAssistant\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use MotionWork\StatamicAiAssistant\Support\AgentAccess;
use MotionWork\StatamicAiAssistant\Support\CpFeatures;
use MotionWork\StatamicAiAssistant\Support\EntryCreationPolicy;
use MotionWork\StatamicAiAssistant\Support\ImageGeneration;
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
                    'roles' => $user->roles()->map(fn ($role) => $role->handle())->values()->all(),
                ])->values()->all(),
            'access' => $this->store->all(),
            'ceiling' => EntryCreationPolicy::configuredMaxPlanEntries(),
            // Without AI features only the Claude connector section applies.
            'aiEnabled' => CpFeatures::aiEnabled() && (bool) config('statamic-ai-assistant.entry_generator', true),
            'imageGeneration' => $this->imageGeneration(),
        ]);
    }

    /**
     * The image generation switch as the page shows it: on or off, whether
     * config locks it, and why it cannot run (another provider, no model).
     *
     * @return array{on: bool, locked: bool, unavailable: ?string}
     */
    private function imageGeneration(): array
    {
        return [
            'on' => ImageGeneration::switchedOn(),
            'locked' => ImageGeneration::configOverride() !== null,
            'unavailable' => ImageGeneration::unavailableReason(),
        ];
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
            'access.claude_restricted' => 'sometimes|boolean',
            'access.image_generation' => 'sometimes|boolean',
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
