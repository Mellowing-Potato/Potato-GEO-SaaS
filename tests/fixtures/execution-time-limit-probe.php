<?php

// Standalone probe: no Laravel bootstrap, database, model calls or network.
require dirname(__DIR__, 2).'/app/Support/ExecutionTimeLimit.php';

use App\Support\ExecutionTimeLimit;

$mode = $argv[1] ?? '';
$modes = [
    'unscoped-idle', 'unscoped-next', 'scoped-idle', 'scoped-next',
    'scoped-exception', 'scoped-request', 'scoped-enforced', 'restored-enforced',
];
if (! in_array($mode, $modes, true)) {
    fwrite(STDERR, 'Usage: php execution-time-limit-probe.php '.implode('|', $modes).PHP_EOL);
    exit(2);
}

function event(string $name, array $extra = []): void
{
    echo json_encode(['event' => $name, 'limit' => (int) ini_get('max_execution_time')] + $extra).PHP_EOL;
}

function busyFor(float $seconds): void
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        hash('sha256', 'local-time-limit-probe');
    }
}

$previous = match ($mode) {
    'scoped-request' => 5,
    'restored-enforced' => 1,
    default => 0,
};
set_time_limit($previous);
event('start', ['php' => PHP_VERSION, 'zts' => PHP_ZTS, 'mode' => $mode]);
$task = static function () use ($mode): void {
    event('inside_task');
    if ($mode === 'scoped-exception') {
        throw new RuntimeException('expected probe exception');
    }
    if ($mode === 'scoped-enforced') {
        busyFor(3);
    }
};

try {
    if (str_starts_with($mode, 'unscoped-')) {
        (static function () use ($task): void {
            set_time_limit(1);
            $task();
        })();
    } else {
        ExecutionTimeLimit::scoped($mode === 'restored-enforced' ? 3 : 1, $task);
    }
} catch (RuntimeException $e) {
    event('caught_exception');
}
event('task_finished');

if (in_array($mode, ['unscoped-idle', 'scoped-idle', 'scoped-exception'], true)) {
    event('worker_idle');
    usleep(2200000);
} else {
    event('next_task');
    busyFor(2.2);
}
event('survived');
