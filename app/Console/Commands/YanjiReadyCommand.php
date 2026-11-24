<?php

namespace App\Console\Commands;

use App\Support\InstanceKey;
use Illuminate\Console\Command;
use Throwable;

class YanjiReadyCommand extends Command
{
    protected $signature = 'yanji:ready {--portable : 要求数据库具有可验证的密钥证据}';
    protected $description = '检查实例是否已初始化并验证持久密钥，不输出账户或密钥';

    public function handle(): int
    {
        try {
            InstanceKey::assertReady($this->laravel->environmentFilePath(), (bool) $this->option('portable'));
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('实例未就绪；请核对配套的数据库与 .env。旧库缺少密钥验证材料时须使用原实例验证的备份。');
            return self::FAILURE;
        }
    }
}
