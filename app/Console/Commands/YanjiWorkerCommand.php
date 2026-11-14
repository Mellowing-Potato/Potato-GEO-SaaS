<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ReflectionClass;

class YanjiWorkerCommand extends Command
{
    protected $signature = 'yanji:worker {--check : 仅检查配置，不消费任务}';
    protected $description = '检查超时约束后消费本实例的全部后台队列';

    public function handle(): int
    {
        $timeout = (int) config('worker.timeout');
        $maximum = $timeout;
        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            $maximum = max($maximum, (int) ((new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? $timeout));
        }
        if (config('queue.default') !== 'database' || config('queue.connections.database.driver') !== 'database') {
            $this->error('本部署包的队列必须使用 database 驱动。');
            return self::FAILURE;
        }
        if ((int) config('queue.connections.database.retry_after') <= $maximum) {
            $this->error("DB_QUEUE_RETRY_AFTER 必须大于最长任务超时 {$maximum} 秒；未启动 worker。");
            return self::FAILURE;
        }
        if ($this->option('check')) {
            $this->info('队列配置检查通过。');
            return self::SUCCESS;
        }
        return $this->call('queue:work', ['connection' => 'database',
            '--queue' => implode(',', config('worker.queues')), '--sleep' => 2,
            '--tries' => 1, '--timeout' => $timeout, '--max-jobs' => 500, '--memory' => 512]);
    }
}
