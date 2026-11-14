<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentWorkerTest extends TestCase
{
    public function test_worker_refuses_unsafe_retry_boundary_and_non_database_driver(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 540]);
        $this->artisan('yanji:worker', ['--check' => true])->assertExitCode(1);
        config(['queue.connections.database.retry_after' => 541]);
        $this->artisan('yanji:worker', ['--check' => true])->assertExitCode(0);
        config(['queue.default' => 'sync']);
        $this->artisan('yanji:worker', ['--check' => true])->assertExitCode(1);
    }

    public function test_worker_covers_every_queue_used_by_application(): void
    {
        $queues = ['default'];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            preg_match_all('/->onQueue\([\x27\x22]([^\x27\x22]+)[\x27\x22]\)/', file_get_contents($file->getPathname()), $matches);
            $queues = array_merge($queues, $matches[1]);
        }
        $this->assertEmpty(array_diff(array_unique($queues), config('worker.queues')));
        $this->assertSame(8, count(config('worker.queues')));
    }
}
