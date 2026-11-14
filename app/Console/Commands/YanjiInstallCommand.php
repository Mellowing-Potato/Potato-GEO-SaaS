<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\LocalAccounts;
use App\Support\InstanceConfiguration;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class YanjiInstallCommand extends Command
{
    protected $signature = 'yanji:install {--email=} {--password=} {--name=管理员}';
    protected $description = '初始化本实例并创建首个管理员；不会覆盖已有账号或密钥';

    public function handle(LocalAccounts $accounts): int
    {
        InstanceConfiguration::validate();
        $lock = fopen(storage_path('app/yanji-install.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('另一次初始化正在进行，请稍后重试。');
            return self::FAILURE;
        }
        try {
            $path = $this->laravel->environmentFilePath();
            $contents = is_file($path) ? file_get_contents($path) : 'APP_URL='.config('app.url')."\n";
            preg_match('/^APP_KEY=(.*)$/m', $contents, $match);
            $existing = trim($match[1] ?? '', " \t\n\r\0\x0B\"'");
            if ($existing === '') {
                $key = config('app.key') ?: 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
                $contents = isset($match[0])
                    ? preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $contents)
                    : rtrim($contents)."\nAPP_KEY=".$key."\n";
                if (file_put_contents($path, $contents, LOCK_EX) === false) {
                    $this->error('无法保存 APP_KEY，请检查环境文件权限。');
                    return self::FAILURE;
                }
                chmod($path, 0600);
                config(['app.key' => $key]);
            } else {
                config(['app.key' => $existing]);
            }
            if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) return self::FAILURE;
            if (User::withoutGlobalScopes()->where('role', 'platform_admin')->exists()) {
                $this->error('已有管理员，拒绝再次创建；密钥和账户均保留。');
                return self::FAILURE;
            }
            $data = [
                'email' => strtolower(trim((string) ($this->option('email') ?: $this->ask('管理员邮箱')))),
                'password' => $this->option('password') ?: $this->secret('管理员密码（至少 8 位）'),
                'name' => trim((string) $this->option('name')),
            ];
            $validator = Validator::make($data, ['email' => ['required', 'email', 'max:254', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'max:128'], 'name' => ['required', 'string', 'max:80']]);
            if ($validator->fails()) {
                $this->error('邮箱、密码或姓名不符合要求，或邮箱已被使用。');
                return self::FAILURE;
            }
            DB::transaction(function () use ($accounts, $data) {
                DB::table('instance_settings')->where('id', 1)->lockForUpdate()->first();
                if (User::withoutGlobalScopes()->where('role', 'platform_admin')->exists()) {
                    throw new \RuntimeException('已有管理员，拒绝再次创建。');
                }
                $accounts->create($data['email'], $data['password'], $data['name'], 'platform_admin');
            });
            $this->call('config:clear');
            $this->info('初始化完成。请在本实例登录页使用管理员邮箱和密码登录。');
            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
