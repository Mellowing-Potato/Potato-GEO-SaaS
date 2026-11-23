<?php

namespace Tests\Feature;

use App\Support\InstanceEnvironment;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstanceEnvironmentTest extends TestCase
{
    public function test_concurrent_writers_preserve_each_other_and_readers_never_see_the_staged_file(): void
    {
        $directory = sys_get_temp_dir().'/yanji-env-test-'.bin2hex(random_bytes(12));
        File::makeDirectory($directory, 0700);
        $envFile = $directory.'/.env';
        $original = "KEEP=\"first\nsecond\"\n";
        file_put_contents($envFile, $original);
        $script = <<<'PHP'
require 'vendor/autoload.php';
App\Support\InstanceEnvironment::update($argv[1], function ($text) use ($argv) {
    file_put_contents($argv[2].'/started', '');
    $limit = microtime(true) + 5;
    while (!is_file($argv[2].'/release') && microtime(true) < $limit) usleep(10000);
    if (!is_file($argv[2].'/release')) exit(2);
    return $text."FIRST=1\n";
});
PHP;
        $second = <<<'PHP'
require 'vendor/autoload.php';
App\Support\InstanceEnvironment::update($argv[1], fn ($text) => $text."SECOND=2\n");
PHP;
        $first = new Process([PHP_BINARY, '-r', $script, $envFile, $directory], base_path());
        $next = new Process([PHP_BINARY, '-r', $second, $envFile], base_path());
        try {
            $first->start();
            $limit = microtime(true) + 5;
            while (! is_file($directory.'/started') && microtime(true) < $limit && $first->isRunning()) {
                usleep(10000);
            }
            $this->assertFileExists($directory.'/started');
            $next->start();
            $this->assertSame($original, file_get_contents($envFile));
            touch($directory.'/release');
            $this->assertSame(0, $first->wait());
            $this->assertSame(0, $next->wait());
            $this->assertSame($original."FIRST=1\nSECOND=2\n", file_get_contents($envFile));
            $bytes = file_get_contents($envFile);
            try {
                InstanceEnvironment::update($envFile, static function () {
                    throw new RuntimeException('test');
                });
                $this->fail('A failed edit must not replace the environment.');
            } catch (RuntimeException $expected) {
                $this->assertSame('test', $expected->getMessage());
            }
            $this->assertSame($bytes, file_get_contents($envFile));
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0600, fileperms($envFile) & 0777);
            }
        } finally {
            $first->stop();
            $next->stop();
            File::deleteDirectory($directory);
        }
    }
}
