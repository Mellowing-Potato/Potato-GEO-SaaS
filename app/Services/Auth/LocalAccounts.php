<?php

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Support\IndependentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LocalAccounts
{
    public function create(string $email, string $password, string $name, string $role = 'admin'): User
    {
        return DB::transaction(function () use ($email, $password, $name, $role) {
            $tenant = Tenant::create([
                'subdomain' => 'workspace-'.Str::lower(Str::random(16)),
                'name' => '我的工作区', 'status' => 'active', 'settings' => [],
            ]);
            // An address may have belonged to a now-deleted account.
            DB::table('password_reset_tokens')->where('email', strtolower(trim($email)))->delete();
            return User::create([
                'tenant_id' => $tenant->id, 'email' => strtolower(trim($email)),
                'password' => $password, 'name' => trim($name), 'role' => $role,
            ]);
        });
    }

    public static function assertActive(User $user): void
    {
        abort_if($user->deletion_purged_at !== null || !$user->tenant?->isActive(), 403, '账号或工作区已停用。');
        IndependentWorkspace::assertFor($user);
    }

    public static function registrationEnabled(): bool
    {
        return (bool) DB::table('instance_settings')->where('id', 1)->value('registration_enabled');
    }
}
