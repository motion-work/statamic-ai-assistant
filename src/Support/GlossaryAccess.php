<?php

namespace MotionWork\StatamicAiAssistant\Support;

use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Permission;
use Statamic\Facades\User;

/**
 * Who may change the translation glossary and style rules. They apply to
 * every translation on the site, so it is a Statamic permission granted per
 * role (Users → Roles), not open to every CP user. Super admins always may.
 */
class GlossaryAccess
{
    public const PERMISSION = 'edit translation glossary';

    public static function register(): void
    {
        Permission::extend(function () {
            Permission::group('statamic_ai_assistant', __('AI Assistant'), function () {
                Permission::register(self::PERMISSION)
                    ->label(__('Edit translation glossary and style rules'))
                    ->description(__('Applies to every translation on the site, in the Control Panel and in the Claude app.'));
            });
        });
    }

    public static function canEdit(?UserContract $user = null): bool
    {
        $user ??= User::current();

        return $user !== null && ($user->isSuper() || $user->hasPermission(self::PERMISSION));
    }
}
