<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionStartupTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_login_page_boots_without_a_billing_database(): void
    {
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(Illuminate\Http\Request::create('http://localhost/login'));
if ($response->getStatusCode() !== 200 || !str_contains($response->getContent(), 'geoLoginForm')) exit(1);
echo 'production-login-ok';
PHP
        ], base_path(), [
            'APP_ENV' => 'production',
            'APP_RUNNING_IN_CONSOLE' => 'false',
            'APP_URL' => 'http://localhost',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), 'Production HTTP bootstrap failed.');
        $this->assertSame('production-login-ok', $process->getOutput());
    }

    public function test_production_job_start_does_not_require_removed_billing_tables(): void
    {
        $this->assertFalse(Schema::hasTable('point_unit_state'));
        $previous = $this->app['env'];
        $this->app->instance('env', 'production');
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn([]);
        try {
            Event::dispatch(new JobProcessing('sync', $job));
        } finally {
            $this->app->instance('env', $previous);
        }
        $this->assertFalse(Schema::hasTable('point_unit_state'));
    }
}
