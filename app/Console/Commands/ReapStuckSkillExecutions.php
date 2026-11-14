<?php

namespace App\Console\Commands;

use App\Models\ModelCallLog;
use App\Models\SkillExecution;
use App\Models\TaskRun;
use App\Services\Enums\ExecutionStatus;
use App\Support\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 清道夫：把真正卡死的技能执行 / 综合诊断标成 failed。
 *
 * 为什么必须有：写终态的人只有两个 —— ExecuteSkillJob / RunGeoAnalysisJob 的 handle()
 * 正常收尾，或者队列的 failed() 回调。这两个都不在场的死法，执行记录就永远停在 running：
 *   ① 同步执行路（POST skills/execute，前端已不走但后端还在）在 420 秒 PHP fatal 时
 *      没有任何人写那一行 —— 生产实证 skill_executions id=33（yao-geo-intent-miner）
 *      从 2026-08-25 05:46 起 running 了 11 天，updated_at 与 created_at 一模一样；
 *   ② 容器被 OOM kill / 被强杀时 job 行直接从表里消失，failed() 也不会跑。
 * 界面上这两种都是永远转圈，客户既看不到结果也等不到失败。
 *
 * 判死要三个条件同时成立，少一个都可能把还活着的执行判死：
 *
 *   ① running 且 updated_at 超过 --minutes（默认 15 分钟）没动过。
 *      15 分钟的来历 —— 这条路上的超时是一架从里到外递增的梯子，每一层都必须比外面
 *      一层先失败，否则拿到的只会是一句笼统的「超时」，看不出卡在哪：
 *
 *          280s  一次网关调用      GEO_GATEWAY_HTTP_TIMEOUT
 *          420s  一个 PHP 进程     SkillAgentRunnerService::execute() 的 set_time_limit
 *          480s  一个队列任务      ExecuteSkillJob / RunGeoAnalysisJob 的 $timeout
 *          480s  worker 进程       geo-skills-worker --timeout=480
 *          600s  队列重新派发      queue.connections.database.retry_after（生产实测 600）
 *
 *      15 分钟（900 秒）取在梯子顶上那根之外，留 5 分钟余量。
 *      🔴 --minutes 绝不能调到 10 分钟以内：600 秒是队列自己重新派发那条任务的时刻，
 *         到点它会把 job 重新领走、按 tries=1 立刻判失败 —— 那是队列在写终态，正常流程。
 *         窗口比 600 秒短，本命令就会抢在队列前面对同一条执行下手。
 *         （2026-09-06 id=44 实证：420 秒 PHP fatal 打死进程后，那一行正是在第 602 秒
 *         被队列自己判成 failed 的，不是被谁救回来的。）
 *      熬过这整架梯子还停在 running，只剩「没人写终态」一种解释。
 *
 *   ② 队列里没有对应的 job 行。job 行在任务跑完前一直在（reserved_at 落值而已），
 *      所以「表里还有它」＝还有人在跑或还排着队，一律不碰。
 *      ⚠️ 只有 database 驱动查得到；换了驱动本命令什么都不做（见 queuedExecutionIds()）。
 *
 *   ③ 这条执行在窗口内没有新的 model_call_logs。这一条是专门给同步执行路留的：
 *      它本来就没有 job 行，光靠 ②「没 job」会把还在跑的同步执行判死。
 *      分段生成每段实时记一条日志，所以「最近 15 分钟一条新日志都没有」才是真不动了。
 *      ⚠️ --minutes 是可调的。一旦调到 7 分钟以下（比如 --minutes=5），一个还在正常
 *      跑的同步执行就会同时满足 ① 和 ②，此时全靠这一条拦着。别为了「清得干净点」
 *      把 --minutes 调小、又顺手把这一条当冗余删掉。
 *
 * ⚠️ 关于 set_time_limit(420) 到底兜不兜得住（2026-09-06 更正，这里原先写反了）：
 *    原文写的是「PHP 的 max_execution_time 在 Unix 上不计等待外部 IO 的时间，所以墙钟
 *    可以远超 420 秒」。那是老式 NTS 构建的行为，对本镜像不成立 ——
 *    本镜像 PHP 8.4.24 是 ZTS 构建且 Zend Max Execution Timers 开着
 *    （自查：`php -i | grep "Max Execution Timers"`），此时按**墙钟**计时，
 *    等网络的时间照算，信号会把阻塞中的 syscall 打断。
 *    生产实证：2026-09-06 skill_executions id=44 正是在等模型回包时被砍的，
 *    fatal 落在 guzzlehttp/guzzle/src/Handler/CurlFactory.php:1267。
 *    （那次走的是队列路，但两条路进的是同一个 SkillAgentRunnerService::execute()、
 *    同一行 set_time_limit、同一个 PHP 构建，计时语义没有分别。）
 *
 *    🔴 这是构建选项给的，不是 PHP 的语言保证。换基础镜像后必须重查那一行；
 *       查出来是 disabled 就退回「只计 CPU 时间」，420 立刻又兜不住了。
 *    ⚠️ 而且「420 兜得住」丝毫不减轻本命令的责任：420 砍掉的是整个进程，
 *       没有任何人写终态，那一行照样永远停在 running —— id=33 就是这么来的。
 *       兜住进程 ≠ 兜住那一行。
 *
 * 误判的代价是有限的：同步路收尾时会无条件覆写自己那一行，所以万一判早了，
 * 它跑完照样把 completed 写回去。但更新时仍用 status=running 做 CAS，
 * 避免把刚刚自己落终态的执行倒回 failed。
 *
 * 调度器里没有 TenantContext，所以查询一律 withoutGlobalScope(TenantScope::class) 跨租户扫。
 */
class ReapStuckSkillExecutions extends Command
{
    protected $signature = 'geo:reap-stuck-executions
        {--minutes=15 : running 超过多少分钟没动静才判死}
        {--dry-run : 只列出会被判死的执行，不写库}';

    protected $description = '把卡死的技能执行 / 综合诊断标成 failed（running 超时 + 队列里没有对应 job + 没有新的模型调用）';

    /**
     * 队列 payload 里 executionId 的形态。
     *
     * jobs.payload 是 JSON，真正带参数的是 data.command，里面是 PHP serialize() 的结果：
     *   O:24:"App\Jobs\ExecuteSkillJob":4:{s:11:"executionId";i:33;...}
     * 所以必须先 json_decode 还原出 command 字符串再匹配。
     * ⚠️ 别学 SkillAgentRunnerService::cancel() 直接对 payload 列写 LIKE '%"executionId":33,%'
     *    —— 那是 JSON 键的形态，序列化后的 payload 里根本没有这种子串，那条 LIKE 一次也没命中过
     *    （它想删的是 pending 的 job 行；没删掉也没出事，因为 job 开跑时会自查 cancelled 后跳过）。
     *    退一步说，就算改成按 payload 原文匹配也不能直接写 \" ：Postgres 的 LIKE 默认拿反斜杠当
     *    转义符，'\"' 会被当成「一个引号」去匹配，反而配不上 payload 里真实的 \" 两个字符。
     */
    private const EXECUTION_ID_PATTERN = '/s:11:"executionId";i:(\d+);/';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($minutes);

        $candidates = SkillExecution::withoutGlobalScope(TenantScope::class)
            ->where('status', ExecutionStatus::Running->value)
            ->where('updated_at', '<', $cutoff)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("No running skill execution older than {$minutes}m.");

            return self::SUCCESS;
        }

        $queuedIds = $this->queuedExecutionIds();

        if ($queuedIds === null) {
            // 查不到队列就没法判断「还有人在跑」，此时宁可什么都不做。
            // 不静默：这行会落进 scheduler 日志，换驱动的人看得见。
            $this->error('Queue driver is not "database"; cannot tell live jobs from dead ones. Reaped nothing.');

            return self::SUCCESS;
        }

        $liveIds = ModelCallLog::withoutGlobalScope(TenantScope::class)
            ->whereIn('skill_execution_id', $candidates->pluck('id')->all())
            ->where('created_at', '>=', $cutoff)
            ->distinct()
            ->pluck('skill_execution_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $reaped = 0;
        $skippedQueued = 0;
        $skippedLive = 0;

        foreach ($candidates as $execution) {
            if (in_array($execution->id, $queuedIds, true)) {
                $skippedQueued++;

                continue;
            }

            if (in_array($execution->id, $liveIds, true)) {
                $skippedLive++;

                continue;
            }

            $ageMinutes = (int) $execution->updated_at->diffInMinutes(now());

            if ($dryRun) {
                $this->line("[dry-run] #{$execution->id} {$execution->skill_id} (tenant {$execution->tenant_id}, mode {$execution->execution_mode}, stuck {$ageMinutes}m)");
                $reaped++;

                continue;
            }

            // A pre-send checkpoint means the original gateway attempt may have
            // consumed funds. Keep it sealed for owner-bound reconciliation.
            $kind = data_get($execution->billing_pending_result, 'kind');
            $prepared = in_array($kind,
                ['model_attempt_prepared', 'embedding_attempt_prepared', 'embedding_result_recorded'], true);
            // CAS：只在仍是 running 且时间戳仍过期时改，别抢掉刚刚续跑的执行。
            $rows = SkillExecution::withoutGlobalScope(TenantScope::class)
                ->where('id', $execution->id)
                ->where('status', ExecutionStatus::Running->value)
                ->where('updated_at', '<', $cutoff)
                ->update([
                    'status' => $prepared ? ExecutionStatus::BillingPending->value : ExecutionStatus::Failed->value,
                    'error_message' => $prepared
                    ? (in_array($kind, ['embedding_attempt_prepared', 'embedding_result_recorded'], true)
                        ? '原向量请求待核对，执行已暂缓；请勿重新提交'
                        : '原模型请求待核对，执行已暂缓；请勿重新提交')
                        : $this->failureMessage($minutes),
                ]);

            if ($rows === 0) {
                continue;
            }

            if ($prepared && $execution->execution_mode === 'task') {
                TaskRun::withoutGlobalScopes()
                    ->where('tenant_id', $execution->tenant_id)
                    ->where('metadata->execution_id', $execution->id)
                    ->where('status', TaskRun::STATUS_RUNNING)
                    ->update(['status' => TaskRun::STATUS_BILLING_PENDING,
                        'error_message' => '原付费请求或结果待核对，执行已暂缓；请勿重新提交']);
            }

            $reaped++;

            Log::warning('Reaped stuck skill execution', [
                'execution_id' => $execution->id,
                'tenant_id' => $execution->tenant_id,
                'project_id' => $execution->project_id,
                'skill_id' => $execution->skill_id,
                'execution_mode' => $execution->execution_mode,
                'stuck_minutes' => $ageMinutes,
                'last_updated_at' => $execution->updated_at->toDateTimeString(),
            ]);
        }

        $verb = $dryRun ? 'Would reap' : 'Reaped';
        // 两行分开印：一行一件事，也让测试能分别断言（同一行里的多个断言 Mockery 只认头一个）。
        $this->info("{$verb} {$reaped} stuck execution(s) (>{$minutes}m).");
        $this->info("Skipped {$skippedQueued} still queued, {$skippedLive} still calling the model.");

        return self::SUCCESS;
    }

    /**
     * 队列里当前所有带 executionId 的 job（含已被 worker 领走的，reserved_at 落值那些）。
     *
     * 不按 job 类名过滤：宁可多护住几条，也不要哪天新增一个驱动 skill_executions 的 Job
     * 时忘了往名单里加，把还在跑的执行判死。
     *
     * @return list<int>|null null＝当前驱动查不到队列，调用方据此放弃本轮
     */
    private function queuedExecutionIds(): ?array
    {
        $connection = (string) config('queue.default', 'database');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return null;
        }

        $table = (string) config("queue.connections.{$connection}.table", 'jobs');
        $database = DB::connection(config("queue.connections.{$connection}.connection"));

        $ids = [];

        $database->table($table)
            ->select('id', 'payload')
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$ids): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) ($row->payload ?? ''), true);
                    $command = is_array($payload) ? ($payload['data']['command'] ?? null) : null;

                    if (! is_string($command)) {
                        continue;
                    }

                    if (preg_match(self::EXECUTION_ID_PATTERN, $command, $matches) === 1) {
                        $ids[(int) $matches[1]] = true;
                    }
                }
            });

        return array_keys($ids);
    }

    /**
     * 给客户看的失败原因。直接落库，不经过 friendlySkillError()。
     *
     * ⚠️ 仍然按那条规矩写（不带斜杠 / 反斜杠 / 换行）：万一哪天这句被人接进那条路，
     *    带上这几个字符就会被整句换成英文通用错误，客户看到的就成了一句没头没尾的英文。
     */
    private function failureMessage(int $minutes): string
    {
        return "执行中断未完成（超过 {$minutes} 分钟没有进展），已终止；请重新发起，如反复出现请联系我们";
    }
}
