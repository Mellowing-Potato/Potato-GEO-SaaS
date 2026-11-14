<?php

namespace App\Support;

use App\Models\User;

final class IndependentWorkspace
{
    public static function assertFor(User $user): void
    {
        abort_unless(
            in_array($user->role, ['admin', 'platform_admin'], true)
                && $user->tenant_id
                && !User::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->whereKeyNot($user->id)->exists(),
            409,
            '账户工作区归属异常，请联系支持处理后重新登录。',
        );
    }
}
