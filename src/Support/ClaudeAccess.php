<?php

namespace MotionWork\StatamicAiAssistant\Support;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use Statamic\Contracts\Auth\User as UserContract;

/**
 * What an account may do through the Claude connector, on top of its Control
 * Panel role: an editor may edit in the Control Panel and only read in Claude.
 *
 * Levels are cumulative (publish includes edit, edit includes read). A role
 * gets one level; a user gets the highest level of their roles, unless the
 * user has a level of their own, which wins, also when it is lower ("none").
 * Until a super admin turns the restriction on, every account keeps the level
 * its role allows. Super admins and the local stdio server (no account) are
 * never restricted.
 */
class ClaudeAccess
{
    /** Ranked lowest first; "none" exists only as a user's own setting. */
    public const LEVELS = ['none', 'read', 'edit', 'publish'];

    public static function allows(?UserContract $user, string $level): bool
    {
        if ($user === null || $user->isSuper() || ! app(AgentAccessStore::class)->claudeRestricted()) {
            return true;
        }

        $needed = array_search($level, self::LEVELS, true);

        return $needed !== false && array_search(self::levelOf($user), self::LEVELS, true) >= $needed;
    }

    public static function levelOf(UserContract $user): string
    {
        $store = app(AgentAccessStore::class);
        $userId = (string) $user->id();
        foreach (self::LEVELS as $level) {
            if (in_array($userId, $store->feature('claude_'.$level)['users'], true)) {
                return $level;
            }
        }

        $best = 'none';
        foreach (array_slice(self::LEVELS, 1) as $level) {
            foreach ($store->feature('claude_'.$level)['roles'] as $role) {
                if ($user->hasRole($role)) {
                    $best = $level;
                }
            }
        }

        return $best;
    }

    public static function denial(string $level): string
    {
        return match ($level) {
            'read' => 'This account has no access to Claude. A super admin can grant it under AI & Claude access in the Control Panel.',
            'edit' => 'This account may only read through Claude, not change anything, although the Control Panel may allow more. A super admin can change this under AI & Claude access.',
            default => 'This account may not publish or unpublish through Claude. A super admin can change this under AI & Claude access.',
        };
    }
}
