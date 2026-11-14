<?php

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Support\TenantScope;
use Illuminate\Console\Command;

/**
 * 卡死的知识文档回收（计划 §7 第 4 条：命令实现搬进 app/Console/Commands）。
 *
 * 两个阈值不能合并，理由见原注释（也在 routes/console.php 里保留）：
 *   processing 15 分钟 → 解析真的中断了；
 *   pending 120 分钟   → 排队很久但可能还在等，提前判死会让用户重新上传、
 *                       多一份重复文档并**多付一次向量化的钱**，所以给足 2 小时。
 * 搬迁只搬位置，"请重新上传"这类**语义**文案一字未改（它决定用户下一步做什么）。
 * 回归证据：tests/Feature/KnowledgeIngestReviewFixesTest.php。
 */
class KnowledgeReapStuckCommand extends Command
{
    protected $signature = 'knowledge:reap-stuck {--minutes=15} {--pending-minutes=120}';

    protected $description = '把卡死的知识文档标成 failed（processing 15 分钟 / pending 120 分钟，两个阈值不能合并）';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $pendingMinutes = (int) $this->option('pending-minutes');

        $reap = function (string $status, int $threshold, string $reason): int {
            $stuck = KnowledgeDocument::withoutGlobalScope(TenantScope::class)
                ->where('status', $status)
                ->where('updated_at', '<', now()->subMinutes($threshold))
                ->get();

            foreach ($stuck as $doc) {
                $doc->markFailed($reason);
            }

            return $stuck->count();
        };

        $processing = $reap(
            'processing',
            $minutes,
            '解析中断未完成（超过 '.$minutes.' 分钟），请重新上传；如反复出现请联系我们'
        );

        $pending = $reap(
            'pending',
            $pendingMinutes,
            '排队超过 '.$pendingMinutes.' 分钟仍未开始处理，已终止；请稍后重新上传，如反复出现请联系我们'
        );

        $this->info("Reaped {$processing} processing (>{$minutes}m) and {$pending} pending (>{$pendingMinutes}m) knowledge document(s).");

        return Command::SUCCESS;
    }
}
