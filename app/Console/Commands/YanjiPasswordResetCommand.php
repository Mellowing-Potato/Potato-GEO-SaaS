<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\InstanceKey;
use App\Support\LocalSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class YanjiPasswordResetCommand extends Command
{
    use ReadsLocalPassword;

    protected $signature = 'yanji:reset-password {email} {--password=} {--password-stdin} {--portable}';
    protected $description = '按邮箱重置本地账号密码并撤销全部会话';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        try {
            if ($this->option('portable') || getenv('YANJI_ENV_PATH')) {
                InstanceKey::assertReady($this->laravel->environmentFilePath(), (bool) $this->option('portable'));
            }
            $password = $this->localPassword('新密码（至少 8 位）');
        } catch (\Throwable) {
            $this->error('重置未完成。请检查配套的数据库、实例密钥与密码输入方式。');
            return self::FAILURE;
        }
        if (Validator::make(['password' => $password], ['password' => 'required|string|min:8|max:128'])->fails()) {
            $this->error('密码长度必须为 8 至 128 位。');
            return self::FAILURE;
        }
        return DB::transaction(function () use ($email, $password) {
            $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            if (!$user || $user->deletion_purged_at !== null) {
                $this->error('未找到可重置的账号。');
                return self::FAILURE;
            }
            $user->forceFill(['password' => $password, 'remember_token' => null])->save();
            LocalSession::revokeAll($user);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $this->info('密码已重置，全部会话已撤销。');
            return self::SUCCESS;
        });
    }
}
