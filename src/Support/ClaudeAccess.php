<?php

namespace MotionWork\StatamicAiAssistant\Support;

use MotionWork\StatamicAiAssistant\Services\AgentAccessStore;
use Statamic\Contracts\Auth\User as UserContract;

/**
 * What an account may do through the Claude connector, on top of its Control
 * Panel role: an editor may edit in the Control Panel and only read in Claude.
 *
 * Levels are cumulative (publish includes edit, edit includes read) and are
 * granted per role or user on the access page. Until a super admin turns the
 * restriction on, every account keeps the level its role allows. Super admins
 * and the local stdio server (no account) are never restricted.
 */
class ClaudeAccess
{
    public const LEVELS = ['read', 'edit', 'publish'];

    public static function allows(?UserContract $user, string $level): bool
    {
        if ($user === null || $user->isSuper() || ! app(AgentAccessStore::class)->claudeRestricted()) {
            return true;
        }

        $index = array_search($level, self::LEVELS, true);
        foreach (array_slice(self::LEVELS, $index === false ? count(self::LEVELS) : $index) as $granted) {
            if (AgentAccess::allows('claude_'.$granted, $user)) {
                return true;
            }
        }

        return false;
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
