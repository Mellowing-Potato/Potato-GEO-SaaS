<?php

namespace Tests\Concerns;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * 真·日志断言（`Log::spy()` 那套是假的）。
 *
 * 背景（2026-09-21 实测跑出来的）：仓库里有两处用例写的是
 *
 * ```php
 * Log::spy();
 * Log::assertLogged(fn ($level, $message, $context) => ...);
 * ```
 *
 * 但 `Log` 外观根本没有 `assertLogged` 这个方法 —— `Log::spy()` 只是把外观根换成 Mockery 的
 * spy，而 spy 对**未声明的方法**一律静默吞掉（返回 null，不报错）。实测：
 * 传一个恒假的闭进去，既不抛异常也不失败。也就是说那两处"断言"从来没执行过闭包：
 * 日志里有没有那行，它们都绿。
 *
 * 这类恒真断言比没有断言更糟 —— 它让后来的人以为这条纪律已经被守住了
 * （`OutputTokenCeilingTest` 里甚至专门 `addToAssertionCount(2)` 去补计数器，
 * 说明当时是真以为它在断言）。
 *
 * 这里换成真监听：注册监听器把每条日志收进数组（走 `MessageLogged` 事件，
 * 与真实 handler 同一条路径），断言时逐条喂给判据闭包，命不中就 `fail()` 并
 * **把捕获到的日志全打出来**（否则失败信息只有一句"没找到"，无从下手）。
 *
 * 用法：
 *
 * ```php
 * use CapturesLogs;
 *
 * $this->captureLogs();          // 先开捕获
 * ... 触发被测代码 ...
 * $this->assertLogged(fn ($level, $message, $context) => $message === 'xxx');
 * $this->assertNotLogged(fn ($level, $message) => $level === 'error');
 * ```
 */
trait CapturesLogs
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $capturedLogs = [];

    /** 开始捕获（每个用例调一次；调用点之后的日志才在捕获范围内）。 */
    protected function captureLogs(): void
    {
        $this->capturedLogs = [];

        Log::listen(function (MessageLogged $event): void {
            $this->capturedLogs[] = [
                'level' => (string) $event->level,
                'message' => (string) $event->message,
                'context' => is_array($event->context) ? $event->context : [],
            ];
        });
    }

    /**
     * 至少有一条日志满足判据。
     *
     * @param  callable(string, string, array<string, mixed>): bool  $predicate
     */
    protected function assertLogged(callable $predicate, string $message = '没有任何一条日志满足这个判据。'): void
    {
        foreach ($this->capturedLogs as $entry) {
            if ($predicate($entry['level'], $entry['message'], $entry['context'])) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail($message."\n捕获到的日志：\n".$this->capturedLogsForHumans());
    }

    /**
     * 一条都不许满足。
     *
     * @param  callable(string, string, array<string, mixed>): bool  $predicate
     */
    protected function assertNotLogged(callable $predicate, string $message = '不该出现的日志出现了。'): void
    {
        foreach ($this->capturedLogs as $entry) {
            if ($predicate($entry['level'], $entry['message'], $entry['context'])) {
                $this->fail($message."\n命中：\n".$this->capturedLogsForHumans([$entry]));
            }
        }

        $this->addToAssertionCount(1);
    }

    /** @param  list<array{level: string, message: string, context: array<string, mixed>}>|null  $entries */
    private function capturedLogsForHumans(?array $entries = null): string
    {
        $entries ??= $this->capturedLogs;
        if ($entries === []) {
            return '（一条都没有）';
        }

        return implode("\n", array_map(
            fn (array $entry): string => "[{$entry['level']}] {$entry['message']} ".
                json_encode($entry['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $entries,
        ));
    }
}
