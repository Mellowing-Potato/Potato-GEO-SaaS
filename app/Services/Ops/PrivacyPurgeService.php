<?php

namespace App\Services\Ops;

use App\Services\Admin\ActivityLogService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 保留期限执行器（计划 §9.5；口径由用户 2026-09-21 拍板：「可以，做吧」）。
 *
 * 盘点工具（PrivacyLifecycleAuditService）回答"还剩什么"，这个服务回答"到点了该清什么"。
 * 三段，各自独立、各自可 dry-run：
 *
 *   1. **磁盘产物**：报表目录（`reports/executions/<id>`）与知识库文档文件。
 *      判定必须同时满足"行已经不在"和"目录本身超过内容保留期" —— 只按行判会把
 *      "行被删、文件还没删"和"行刚删、正当窗口内"混在一起，前者才是残留。
 *
 *   2. **漏网的内容行**：项目被真删（垃圾桶期满）之后仍然留在库里的内容行。
 *      计时**以删除事件为准**，不是以行自己的 created_at 为准 ——
 *      一个三年前建的项目昨天刚删，按 created_at 判会立刻被清掉（那等于删除当天就丢数据）。
 *      删除事件从审计表取：`project.purged`（ProjectTrashService 清理时写入，本条配套加）。
 *
 *   3. **钱相关**（model_call_logs / point_debit_reconciliations）：保留 ≥24 个月；
 *      默认只统计，`--include-money` 才清。理由：对账、退款争议、发票追溯都要用它们，
 *      而且**删了不可再生** —— 这类动作必须是一次显式决定，不能藏在定时任务里。
 *
 * 明确不动的（写下来免得被当成漏做）：
 *   - 与已删项目"脱钩"的知识库（knowledge_bases.project_id = null）：它已回到租户自己的知识列表，
 *     是客户的内容，不是残留；清它等于替客户删资料。
 *   - 审计表 admin_activity_logs：谁删了什么本身就是审计证据，保留期限另议（当前不主动清）。
 */
final class PrivacyPurgeService
{
    /**
     * 内容类表登记：owner 列 → 用于判定"所属项目已被真删"。
     *
     * 只登记**真有残留可能**的表：绝大多数子表在项目真删时已由外键级联带走，
     * 这里是"当年没级联 / 级联被拆掉过"的兜底，不是常规路径。
     *
     * @var list<string>
     */
    private const CONTENT_TABLES_WITH_PROJECT = [
        'articles',
        'skill_executions',
        'project_stages',
        'tasks',
        'url_imports',
        'ai_visibility_runs',
        'analytics_visit_logs',
        'analytics_crawler_hits',
        'knowledge_documents',
        'project_publishing_channels',
        'project_generation_preferences',
    ];

    /** 钱相关表：只统计，除非显式 --include-money。 */
    private const MONEY_TABLES = [
        // 行龄列：model_call_logs 与 point_debit_reconciliations 都用 created_at
        // （前者另有 metadata.project_deleted_at 快照，那是"项目什么时候被删"，
        //   与"这笔钱发生多久了"是两件事 —— 保留期按后者算才对）。
        'model_call_logs' => 'created_at',
    ];

    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function contentPurgeDays(): int
    {
        return max(1, (int) config('geo.lifecycle.content_purge_days', 90));
    }

    public function moneyRetentionMonths(): int
    {
        return max(1, (int) config('geo.lifecycle.money_retention_months', 24));
    }

    /**
     * @return array{object:string,dry_run:bool,include_money:bool,content_purge_days:int,money_retention_months:int,sections:array<string,array{count:int,items:list<array<string,mixed>>}>}
     */
    public function run(bool $apply = false, bool $includeMoney = false): array
    {
        $report = [
            'object' => 'geo_privacy_purge',
            'dry_run' => ! $apply,
            'include_money' => $includeMoney,
            'content_purge_days' => $this->contentPurgeDays(),
            'money_retention_months' => $this->moneyRetentionMonths(),
            'sections' => [
                'disk_artifacts' => $this->diskArtifacts($apply),
                'content_rows' => $this->contentRows($apply),
                'money_rows' => $this->moneyRows($apply && $includeMoney),
            ],
        ];

        if ($apply) {
            $this->activityLog->record('privacy.purge_run', [
                'actor_type' => 'system',
                'actor_name' => 'geo:privacy-purge',
                'details' => [
                    'include_money' => $includeMoney,
                    'disk_artifacts' => $report['sections']['disk_artifacts']['count'],
                    'content_rows' => $report['sections']['content_rows']['count'],
                    'money_rows' => $report['sections']['money_rows']['count'],
                ],
            ]);
        }

        return $report;
    }

    /**
     * 磁盘产物：孤儿报表目录 + 孤儿知识库文档文件。
     *
     * @return array{count:int,items:list<array<string,mixed>>,bytes:int}
     */
    private function diskArtifacts(bool $apply): array
    {
        $items = [];
        $bytes = 0;
        $cutoff = CarbonImmutable::now()->subDays($this->contentPurgeDays());

        try {
            $disk = Storage::disk('reports');
        } catch (Throwable $e) {
            return ['count' => 0, 'items' => [['error' => 'reports 磁盘不可用：'.$e->getMessage()]], 'bytes' => 0];
        }

        foreach ($disk->directories('executions') as $directory) {
            $id = basename($directory);
            if (! ctype_digit($id)) {
                continue;
            }
            if (DB::table('skill_executions')->where('id', (int) $id)->exists()) {
                continue;
            }
            if (CarbonImmutable::createFromTimestamp($disk->lastModified($directory))->greaterThan($cutoff)) {
                continue;
            }

            $size = $this->directoryBytes($disk, $directory);
            $items[] = ['path' => $directory, 'bytes' => $size, 'reason' => '执行记录已不存在且超过保留期'];
            $bytes += $size;

            if ($apply) {
                $disk->deleteDirectory($directory);
            }
        }

        return ['count' => count($items), 'items' => $items, 'bytes' => $bytes];
    }

    /**
     * 内容类残留行：按"项目被真删的时间"计时（删除事件取自审计表）。
     *
     * @return array{count:int,items:list<array<string,mixed>>,purged_projects:int}
     */
    private function contentRows(bool $apply): array
    {
        $cutoff = CarbonImmutable::now()->subDays($this->contentPurgeDays());

        $purgedProjectIds = DB::table('admin_activity_logs')
            ->where('action', 'project.purged')
            ->whereNotNull('target_id')
            ->where('created_at', '<=', $cutoff)
            ->pluck('target_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $items = [];
        if ($purgedProjectIds !== []) {
            foreach (self::CONTENT_TABLES_WITH_PROJECT as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'project_id')) {
                    continue;
                }

                $query = DB::table($table)->whereIn('project_id', $purgedProjectIds);
                $count = (int) $query->count();
                if ($count === 0) {
                    continue;
                }

                $items[] = ['table' => $table, 'rows' => $count, 'reason' => '所属项目已真删且超过内容保留期'];
                if ($apply) {
                    DB::table($table)->whereIn('project_id', $purgedProjectIds)->delete();
                }
            }
        }

        return [
            'count' => count($items),
            'items' => $items,
            'purged_projects' => count($purgedProjectIds),
        ];
    }

    /**
     * 钱相关表：只按行龄统计（≥24 个月），默认不清。
     *
     * @return array{count:int,items:list<array<string,mixed>>,note:string}
     */
    private function moneyRows(bool $apply): array
    {
        $cutoff = CarbonImmutable::now()->subMonths($this->moneyRetentionMonths());
        $items = [];

        foreach (self::MONEY_TABLES as $table => $ageColumn) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $ageColumn)) {
                continue;
            }

            $count = (int) DB::table($table)->where($ageColumn, '<', $cutoff)->count();
            if ($count === 0) {
                continue;
            }

            $items[] = ['table' => $table, 'rows' => $count, 'before' => $cutoff->toISOString()];
            if ($apply) {
                DB::table($table)->where($ageColumn, '<', $cutoff)->delete();
            }
        }

        return [
            'count' => count($items),
            'items' => $items,
            'note' => $apply
                ? '钱相关表已按保留月执行清理（本次带了 --include-money）。'
                : '钱相关表只统计不动手：要给 --include-money --apply 才会清（对账/审计要用，删了不可再生）。',
        ];
    }

    private function directoryBytes(\Illuminate\Filesystem\FilesystemAdapter $disk, string $directory): int
    {
        $bytes = 0;
        foreach ($disk->allFiles($directory) as $file) {
            $bytes += (int) $disk->size($file);
        }

        return $bytes;
    }
}
