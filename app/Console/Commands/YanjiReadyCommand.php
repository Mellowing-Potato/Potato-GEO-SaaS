<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class YanjiReadyCommand extends Command
{
    protected $signature = 'yanji:ready';
    protected $description = '检查实例是否已初始化，不输出账户或密钥';

    public function handle(): int
    {
        try {
            return config('app.key') && User::withoutGlobalScopes()->where('role', 'platform_admin')->exists()
                ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            return self::FAILURE;
        }
    }
}
