<?php

namespace App\Support;

/** Keep a task's PHP timer from affecting the next job in a long-lived worker. */
final class ExecutionTimeLimit
{
    public static function scoped(int $seconds, callable $callback): mixed
    {
        $previous = (int) ini_get('max_execution_time');
        set_time_limit($seconds);

        try {
            return $callback();
        } finally {
            // Restoring 0 disarms the CLI timer. Preserve a caller's nonzero
            // setting too; do not make a bounded HTTP request unlimited.
            set_time_limit($previous);
        }
    }
}
