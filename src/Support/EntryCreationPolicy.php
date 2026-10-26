<?php

namespace BoldWeb\StatamicAiAssistant\Support;

use Statamic\Facades\User;

/**
 * Central rule for how many entries one BOLD agent request may create/update.
 *
 * Super users get the full configured batch cap
 * (`bold_agent_max_plan_entries`). Everyone else is capped to a SINGLE entry
 * per request when `editor_limit_entries` (env `EDITOR_LIMIT_ENTRIES`) is on —
 * so editors can still use the agent, but a mistaken "create every page of this
 * site" prompt can never fan out into a bulk run.
 *
 * The check must happen where the CP user is known (the controller), because
 * the planner itself runs in a queued job with no authenticated user. The
 * resolved cap is therefore computed up-front and carried on the batch session.
 */
class EntryCreationPolicy
{
    /**
     * Entries a non-super user may create/update per request while the limit is
     * enabled. Deliberately a single entry — "no multiple entries" for editors.
     */
    public const EDITOR_ENTRY_LIMIT = 1;

    /**
     * Whether the single-entry limit for non-super users is active.
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
        return max(1, min(500, (int) config('statamic-ai-assistant.bold_agent_max_plan_entries', 100)));
    }

    /**
     * Whether the given user (or the current CP user) is subject to the limit.
     */
    public static function appliesTo(?bool $isSuper = null): bool
    {
        $isSuper ??= (bool) User::current()?->isSuper();

        return self::limitEnabled() && ! $isSuper;
    }

    /**
     * Effective max entries a request may create/update.
     *
     * Pass the user's super status explicitly from the request layer; when
     * omitted the current CP user is used (do NOT rely on that inside a queued
     * job, where there is no authenticated user).
     */
    public static function maxPlanEntries(?bool $isSuper = null): int
    {
        $configured = self::configuredMaxPlanEntries();

        if (! self::appliesTo($isSuper)) {
            return $configured;
        }

        return min($configured, self::EDITOR_ENTRY_LIMIT);
    }
}
