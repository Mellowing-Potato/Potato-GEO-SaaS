<?php

namespace App\Console\Commands;

use App\Support\PortableDesktopAccess;
use Illuminate\Console\Command;
use Throwable;

final class YanjiDesktopLaunchCommand extends Command
{
    protected $signature = 'yanji:desktop-launch {--output=}';
    protected $description = '为本机单人便携实例生成私有的一次性浏览器启动授权';

    public function handle(): int
    {
        try {
            PortableDesktopAccess::createLaunchFile((string) $this->option('output'));

            return self::SUCCESS;
        } catch (Throwable) {
            // Neither the ticket nor an output path may be echoed into installation logs.
            $this->error('无法建立本机浏览器授权，请检查实例、单人工作区与私有目录权限。');

            return self::FAILURE;
        }
    }
}
