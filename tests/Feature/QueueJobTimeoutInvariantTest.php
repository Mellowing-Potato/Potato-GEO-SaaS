<?php

namespace Tests\Feature;

use ReflectionClass;
use Tests\TestCase;

/**
 * 队列任务超时不变式：每个 Job 的 $timeout 必须小于队列的 retry_after。
 *
 * 为什么要有这条测试：
 * 数据库队列靠 retry_after 判断「这个任务是不是卡死了、该不该让别的 worker 接手」。
 * 如果某个 Job 的 $timeout 等于或大于 retry_after，任务跑到边界时，
 * 第一个 worker 还在跑，第二个 worker 已经可以重新领取同一个任务。
 *
 * 后果分两种，取决于该 Job 的 $tries：
 *  - $tries = 1：第二个 worker 在执行任务体之前就判失败并删除任务
 *    （Illuminate\Queue\Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts 在 fire() 之前调用），
 *    于是 failed() 把业务状态写成失败——哪怕第一个 worker 正在成功地跑完。
 *  - $tries > 1：任务体会被真正执行第二遍，副作用（扣费、写库、发外部请求）翻倍。
 *
 * 2026-08-20 发现 GenerateEnterpriseKnowledgeDraftJob 的 600 正好等于 retry_after 的 600，
 * 属于「边界上会被第二个 worker 重新领取」的高风险（草稿生成是调大模型的，跑两遍花两份钱）。
 * 同日评审拍板连带修掉，已改成 540 并从 DEFERRED_TIMEOUT_JOBS 移除 —— 现在所有 Job 一视同仁。
 * 名单留着是给下一次「明知有问题但这批不改」用的：登记进去只守「不许比 retry_after 更大」。
 */
class QueueJobTimeoutInvariantTest extends TestCase
{
    /**
     * 已知「本批故意不改」的任务。只允许 $timeout == retry_after（不许更大），
     * 真正修复时把它调到 retry_after 以下，并从这份名单移除。
     *
     * @var array<int, class-string>
     */
    private const DEFERRED_TIMEOUT_JOBS = [];

    public function test_every_job_timeout_is_below_queue_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan(0, $retryAfter, 'database 队列没有配置 retry_after，测试本身失去意义');

        $jobs = $this->jobClassesWithTimeout();

        $this->assertNotEmpty($jobs, 'app/Jobs 下没有扫描到任何带 $timeout 的任务类，测试可能已失效');

        foreach ($jobs as $class => $timeout) {
            if (in_array($class, self::DEFERRED_TIMEOUT_JOBS, true)) {
                // 故意不改的任务：只允许 $timeout <= retry_after（600），不允许更大（那样更糟）。
                $this->assertLessThanOrEqual(
                    $retryAfter,
                    $timeout,
                    sprintf(
                        '%s 在 DEFERRED_TIMEOUT_JOBS 名单里，只允许 $timeout <= retry_after（当前 %d），'
                        .'不允许更大；要真正修复请把它调到 %d 以下并从名单移除。',
                        class_basename($class),
                        $timeout,
                        $retryAfter
                    )
                );
                continue;
            }

            $this->assertLessThan(
                $retryAfter,
                $timeout,
                sprintf(
                    '%s 的 $timeout=%d 不小于队列 retry_after=%d：'
                    .'任务跑到边界会被第二个 worker 重新领取。请把 $timeout 调到 %d 以下。',
                    class_basename($class),
                    $timeout,
                    $retryAfter,
                    $retryAfter
                )
            );
        }
    }

    /**
     * 扫描 app/Jobs 下所有声明了 $timeout 默认值的任务类。
     *
     * 用 getDefaultProperties() 而不是实例化：任务类的构造函数大多有必填参数。
     *
     * @return array<class-string, int>
     */
    private function jobClassesWithTimeout(): array
    {
        $found = [];

        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $timeout = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

            if (is_int($timeout)) {
                $found[$class] = $timeout;
            }
        }

        return $found;
    }
}
