<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\LocalAccounts;
use App\Support\InstanceConfiguration;
use App\Support\InstanceKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class YanjiInstallCommand extends Command
{
    use ReadsLocalPassword;

    protected $signature = 'yanji:install {--email=} {--password=} {--password-stdin} {--portable} {--name=管理员}';
    protected $description = '初始化本实例并创建首个管理员；不会覆盖已有账号或密钥';

    public function handle(LocalAccounts $accounts): int
    {
        InstanceConfiguration::validate();
        $lock = fopen(storage_path('app/yanji-install.lock'), 'c');
        if (! $lock) {
            $this->error('无法打开初始化锁。');
            return self::FAILURE;
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->error('另一次初始化正在进行，请稍后重试。');
                return self::FAILURE;
            }
            $data = [
                'email' => strtolower(trim((string) ($this->option('email') ?: $this->ask('管理员邮箱')))),
                'password' => $this->localPassword('管理员密码（至少 8 位）'),
                'name' => trim((string) $this->option('name')),
            ];
            $validator = Validator::make($data, ['email' => ['required', 'email', 'max:254'],
                'password' => ['required', 'string', 'min:8', 'max:128'], 'name' => ['required', 'string', 'max:80']]);
            if ($validator->fails()) {
                $this->error('邮箱、密码或姓名不符合要求。');
                return self::FAILURE;
            }
            $path = $this->laravel->environmentFilePath();
            InstanceKey::prepareInstall($path);
            if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
            DB::transaction(function () use ($accounts, $data, $path) {
                DB::table('instance_settings')->where('id', 1)->lockForUpdate()->first();
                if (User::withoutGlobalScopes()->exists()) {
                    throw new RuntimeException('已有本地账号，拒绝再次创建。');
                }
                InstanceKey::bind($path);
                $accounts->create($data['email'], $data['password'], $data['name'], 'platform_admin');
            });
            $this->call('config:clear');
            $this->info('初始化完成。请在本实例登录页使用管理员邮箱和密码登录。');
            return self::SUCCESS;
        } catch (RuntimeException) {
            // Database exceptions can contain bindings. Keep answers, hashes and credentials out of console logs.
            $this->error('初始化未完成。请检查实例的配套 .env、数据库及权限，已有账号请直接登录。');
            return self::FAILURE;
        } finally {
            fclose($lock);
        }
    }
}
