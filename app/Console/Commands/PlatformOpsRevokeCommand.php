<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
class PlatformOpsRevokeCommand extends Command
{
    protected $signature = 'platform:ops-revoke {email}';
    protected $description = '按邮箱撤销平台运营权限';
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->first();
        if (!$user || $user->deletion_purged_at !== null) {
            $this->error('未找到本地账号。');
            return self::FAILURE;
        }
        \App\Support\IndependentWorkspace::assertFor($user);
        if ($user->role === 'admin') { $this->info('无需撤销。'); return self::SUCCESS; }
        $user->forceFill(['role' => 'admin'])->save();
        $this->info('平台运营权限已撤销。');
        return self::SUCCESS;
    }
}
