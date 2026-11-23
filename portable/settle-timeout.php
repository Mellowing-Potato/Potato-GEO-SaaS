<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\DatabaseJobRecord;

umask(0077);
$run = getenv('YANJI_ENV_PATH').'/run';
$path = $run.'/expired-job.json';
if (!is_file($path)) {
    exit(0);
}
try {
    $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    // The old child has already been reaped and its successor has not started. Re-running after a crash is safe.
    $db = $app['db']->connection();
    $app['events']->listen(JobFailed::class, static function (JobFailed $event) use ($app): void {
        $app['queue.failer']->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
    });
    $db->transaction(static function () use ($db, $app, $state): void {
        $row = $db->table(config('queue.connections.database.table'))->lockForUpdate()->find($state['id']);
        if (!$row) {
            return;
        }
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        if ($payload['uuid'] !== $state['uuid'] || (int) $row->attempts !== $state['attempts']
            || (int) $row->reserved_at !== $state['reserved_at']) {
            throw new RuntimeException('队列保留记录与已终止作业不匹配。');
        }
        $job = new DatabaseJob($app, $app['queue']->connection('database'), new DatabaseJobRecord($row), 'database', $row->queue);
        // TimeoutExceededException rolls back the entire SQLite connection in Laravel. Here the interrupted
        // process is already gone. Preserve one transaction for removal, the real job callback and failed_jobs.
        $job->fail(new RuntimeException('作业超过执行时限并已停止，请核对已发送请求的结果。'));
    });
    unlink($path);
} catch (Throwable) {
    fwrite(STDERR, "超时作业确认失败，消费仍暂停。请检查实例数据库后重新启动。\n");
    exit(1);
}
