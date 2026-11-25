<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\LocalAccounts;
use App\Support\InstanceConfiguration;
use App\Support\InstanceKey;
use App\Support\PortableDesktopAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class YanjiInstallCommand extends Command
{
    use ReadsLocalPassword;

    protected $signature = 'yanji:install {--email=} {--password=} {--password-stdin} {--portable} {--desktop} {--name=管理员}';
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
            $desktop = (bool) $this->option('desktop');
            if ($desktop) {
                PortableDesktopAccess::assertEnabled();
                if (! $this->option('portable') || $this->option('email') !== null
                    || $this->option('password') !== null || $this->option('password-stdin')) {
                    throw new RuntimeException('本机单人模式不接受账号或密码参数。');
                }
            }
            $interactive = $this->input->isInteractive();
            $emailOption = $this->option('email');
            $promptingEmail = $emailOption === null || $emailOption === '';
            $promptingPassword = $this->option('password') === null && ! $this->option('password-stdin');
            if (! $desktop && $interactive && ($promptingEmail || $promptingPassword)) {
                $this->line('请设置首个管理员账号（密码输入不回显，至少 8 位）：');
            }
            $data = [
                'email' => $desktop ? 'desktop-'.bin2hex(random_bytes(16)).'@example.com'
                    : ($promptingEmail ? $this->promptEmail($interactive) : strtolower(trim((string) $emailOption))),
                'password' => $desktop ? bin2hex(random_bytes(32))
                    : ($promptingPassword ? $this->promptPassword($interactive) : $this->localPassword('管理员密码（至少 8 位）')),
                'name' => $desktop ? '本机用户' : trim((string) $this->option('name')),
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
            InstanceKey::finishInstall($path);
            $this->call('config:clear');
            $this->info($desktop ? '本机工作区已初始化，无需设置邮箱和密码。'
                : '初始化完成。请在本实例登录页使用管理员邮箱和密码登录。');
            return self::SUCCESS;
        } catch (RuntimeException) {
            // Database exceptions can contain bindings. Keep answers, hashes and credentials out of console logs.
            $this->error('初始化未完成。请检查实例的配套 .env、数据库及权限，已有账号请直接登录。');
            return self::FAILURE;
        } finally {
            fclose($lock);
        }
    }

    private function promptEmail(bool $interactive): string
    {
        $maxAttempts = $interactive ? 5 : 1;
        $email = '';
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $email = strtolower(trim((string) $this->ask('管理员邮箱')));
            if (! Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:254']])->fails()) {
                return $email;
            }
            if ($interactive && $attempt + 1 < $maxAttempts) {
                $this->warn('管理员邮箱格式无效，请重新输入（例如 admin@example.com）。');
            }
        }
        return $email;
    }

    private function promptPassword(bool $interactive): string
    {
        $maxAttempts = $interactive ? 5 : 1;
        $password = '';
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $password = $this->localPassword('管理员密码（至少 8 位）');
            if (! Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:8', 'max:128']])->fails()) {
                return $password;
            }
            if ($interactive && $attempt + 1 < $maxAttempts) {
                $this->warn('管理员密码长度需为 8 至 128 位，请重新输入。');
            }
        }
        return $password;
    }
}
