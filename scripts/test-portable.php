<?php

// Keep the default test command on the same Linux runtime as production and CI.
// Host PHP/Python/Poppler installations differ across Windows and macOS.
require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$python = getenv('GEO_TEST_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
$process = new Process([$python, __DIR__.'/test-linux.py'], dirname(__DIR__));
$process->setTimeout(null);

try {
    $process->run(static function (string $type, string $output): void {
        fwrite($type === Process::ERR ? STDERR : STDOUT, $output);
    });
} catch (\Throwable $e) {
    fwrite(STDERR, "Portable tests require Python 3 and local Docker: {$e->getMessage()}\n");
    exit(1);
}

exit($process->getExitCode() ?? 1);
