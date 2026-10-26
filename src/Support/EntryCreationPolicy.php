<?php

namespace MotionWork\StatamicAiAssistant\Support;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

/**
 * Central rule for how many entries one AI agent request may create/update.
 *
 * Super users get the full configured batch cap (`agent_max_plan_entries`).
 * For everyone else — while `editor_limit_entries` (env `EDITOR_LIMIT_ENTRIES`)
 * is on — the cap is resolved from the dashboard-managed access config: a
 * per-user override wins, else the highest limit among the user's granted roles,
 * else the configured default (1 when nothing is set). The result is clamped to
 * [1, configured ceiling].
 *
 * The check must happen where the CP user is known (the controller), because the
 * planner itself runs in a queued job with no authenticated user. The resolved
 * cap is therefore computed up-front and carried on the batch session.
 */
class EntryCreationPolicy
{
    /**
     * Fallback per-request cap for a limited non-super user when the dashboard
     * config sets no role/user/default limit. Historically the fixed editor cap.
     */
    public const EDITOR_ENTRY_LIMIT = 1;

    /**
     * Whether the single-entry (dashboard-configurable) limit for non-super users
     * is active at all.
     */
    public static function limitEnabled(): bool
    {
        return (bool) config('statamic-ai-assistant.editor_limit_entries', true);
    }

    /**
     * The configured, user-agnostic batch cap (super-user ceiling).
     */
    public static function configuredMaxPlanEntries(): int
    {
        return max(1, min(500, (int) config('statamic-ai-assistant.agent_max_plan_entries', 100)));
    }

    /**
     * Whether the given user (or current CP user) is subject to a reduced limit.
     */
    public static function appliesTo(?UserContract $user = null): bool
    {
        $user ??= User::current();

        return self::limitEnabled() && $user !== null && ! $user->isSuper();
    }

    /**
     * Effective max entries the given user (or current CP user) may create/update
     * per request. Do NOT call inside a queued job (no authenticated user there) —
     * resolve it in the request layer and carry the value on the session.
     */
    public static function maxPlanEntries(?UserContract $user = null): int
    {
        $user ??= User::current();
        $ceiling = self::configuredMaxPlanEntries();

        // No user context (or unlimited): fall back to the full ceiling rather
        // than over-restricting.
        if ($user === null || $user->isSuper() || ! self::limitEnabled()) {
            return $ceiling;
        }

        return max(1, min($ceiling, self::resolveUserLimit($user)));
    }

    /**
     * The dashboard-configured limit for this non-super user: user override,
     * else the highest of their granted-role limits, else the default.
     */
    private static function resolveUserLimit(UserContract $user): int
    {
        $limits = app(AgentAccessStore::class)->agentLimits();

        $userId = (string) $user->id();
        if ($userId !== '' && isset($limits['users'][$userId])) {
            return (int) $limits['users'][$userId];
        }

        $roleLimits = [];
        foreach ($limits['roles'] as $roleHandle => $limit) {
            if ($user->hasRole($roleHandle)) {
                $roleLimits[] = (int) $limit;
            }
        }
        if ($roleLimits !== []) {
            return max($roleLimits);
        }

        return (int) ($limits['default'] ?? self::EDITOR_ENTRY_LIMIT);
    }
}
