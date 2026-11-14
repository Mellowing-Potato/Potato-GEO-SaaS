<?php

namespace Tests\Unit;

use App\Support\ExecutionTimeLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

class ExecutionTimeLimitTest extends TestCase
{
    #[DataProvider('previousLimits')]
    public function test_return_and_exception_restore_the_callers_limit(int $previous): void
    {
        $original = (int) ini_get('max_execution_time');
        set_time_limit($previous);
        try {
            $value = new \stdClass;
            $this->assertSame($value, ExecutionTimeLimit::scoped(420, function () use ($value) {
                $this->assertSame(420, (int) ini_get('max_execution_time'));

                return $value;
            }));
            $this->assertSame($previous, (int) ini_get('max_execution_time'));

            $error = new RuntimeException('original exception');
            try {
                ExecutionTimeLimit::scoped(420, function () use ($error): never {
                    $this->assertSame(420, (int) ini_get('max_execution_time'));
                    throw $error;
                });
                $this->fail('The callback exception must propagate.');
            } catch (RuntimeException $caught) {
                $this->assertSame($error, $caught);
            }
            $this->assertSame($previous, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($original);
        }
    }

    public static function previousLimits(): array
    {
        return ['unlimited CLI' => [0], 'bounded caller' => [37]];
    }

    public function test_nested_scope_restores_each_callers_limit(): void
    {
        $original = (int) ini_get('max_execution_time');
        ExecutionTimeLimit::scoped(420, function (): void {
            ExecutionTimeLimit::scoped(30, function (): void {
                $this->assertSame(30, (int) ini_get('max_execution_time'));
            });
            $this->assertSame(420, (int) ini_get('max_execution_time'));
        });
        $this->assertSame($original, (int) ini_get('max_execution_time'));
    }

    #[DataProvider('survivingProbes')]
    public function test_finished_task_does_not_kill_later_work(string $mode, int $previous): void
    {
        $process = $this->probe($mode);
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('"event":"inside_task","limit":1', $output);
        $this->assertStringContainsString('"event":"task_finished","limit":'.$previous, $output);
        $this->assertStringContainsString('"event":"survived"', $output);
    }

    public static function survivingProbes(): array
    {
        return [
            'idle worker' => ['scoped-idle', 0],
            'next job' => ['scoped-next', 0],
            'exception then idle' => ['scoped-exception', 0],
            'bounded request' => ['scoped-request', 5],
        ];
    }

    #[DataProvider('boundedProbes')]
    public function test_active_and_restored_limits_still_stop_overlong_work(string $mode, bool $taskFinished): void
    {
        $process = $this->probe($mode);
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertNotSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('Maximum execution time of 1 second exceeded', $output);
        $this->assertSame($taskFinished, str_contains($output, '"event":"task_finished"'), $output);
        $this->assertStringNotContainsString('"event":"survived"', $output);
    }

    public static function boundedProbes(): array
    {
        return [
            'work inside the scope' => ['scoped-enforced', false],
            'bounded caller after the scope' => ['restored-enforced', true],
        ];
    }

    private function probe(string $mode): Process
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../fixtures/execution-time-limit-probe.php', $mode]);
        $process->setTimeout(10);
        $process->run();

        return $process;
    }
}
