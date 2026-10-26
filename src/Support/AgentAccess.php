<?php

namespace MotionWork\StatamicAiAssistant\Support;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

/**
 * Central access policy for the AI agent's gated capabilities.
 *
 * Resolution is deliberately simple and native:
 *   - super admins always pass (and are never stored in the access config);
 *   - otherwise a user passes a feature if their id is granted directly, or if
 *     they hold any granted role (`$user->hasRole()` — which already accounts
 *     for roles inherited via user groups).
 *
 * Default-deny: an empty/absent access config grants nothing to non-super users.
 * Managing the access config itself is always super-only (see canManage()).
 */
class AgentAccess
{
    /** Gated capabilities — kept in sync with AgentAccessStore::FEATURES. */
    public const FEATURES = AgentAccessStore::FEATURES;

    /** Laravel Gate ability string for a feature (used by ->can() / can: middleware). */
    public static function gateAbility(string $feature): string
    {
        return 'statamic-ai-assistant.'.$feature;
    }

    /** Gate ability for managing the access config itself (super-only). */
    public static function manageGateAbility(): string
    {
        return 'statamic-ai-assistant.manage_access';
    }

    /**
     * Whether the given user (or the current CP user) may use the feature.
     */
    public static function allows(string $feature, ?UserContract $user = null): bool
    {
        $user ??= User::current();

        if (! $user) {
            return false;
        }

        if ($user->isSuper()) {
            return true;
        }

        if (! in_array($feature, self::FEATURES, true)) {
            return false;
        }

        $grant = self::store()->feature($feature);

        $userId = (string) $user->id();
        if ($userId !== '' && in_array($userId, $grant['users'], true)) {
            return true;
        }

        foreach ($grant['roles'] as $roleHandle) {
            if ($user->hasRole($roleHandle)) {
                return true;
            }
        }

        return false;
    }

    /** Statamic user-preference key for the per-user advanced-tools opt-in. */
    public const ADVANCED_TOOLS_PREFERENCE = 'statamic_ai_assistant_advanced_tools';

    /**
     * Whether the advanced structure tools are ACTIVE for a user: the user must
     * hold the 'advanced_tools' access grant AND have opted in via the toggle
     * in the agent UI (a per-user preference, default OFF). The explicit opt-in
     * exists because structural changes apply immediately — on a production
     * site even a fully-granted super admin should have to arm them first.
     */
    public static function advancedToolsActive(?UserContract $user = null): bool
    {
        $user ??= User::current();

        if (! $user || ! self::allows('advanced_tools', $user)) {
            return false;
        }

        return (bool) $user->getPreference(self::ADVANCED_TOOLS_PREFERENCE);
    }

    /**
     * Whether the current user may VIEW/EDIT the access configuration.
     * Always super-only — granting access is a super-admin responsibility.
     */
    public static function canManage(?UserContract $user = null): bool
    {
        $user ??= User::current();

        return (bool) $user?->isSuper();
    }

    private static function store(): AgentAccessStore
    {
        return app(AgentAccessStore::class);
    }
}
