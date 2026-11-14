<?php

namespace App\Console\Commands;

use App\Models\SiteThemeReplication;
use App\Models\Tenant;
use App\Services\ThemePackaging\SiteThemeReplicationService;
use App\Services\ThemePackaging\ThemePackagePaths;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * 主题包生成 · 磁盘防御清理命令（执行书 T8，红线 1）
 *
 * 为什么要有这条命令：
 *   单租户字节配额（assertWithinQuota，500MB）只是「拒绝新建」的闸，不回收已有。
 *   真正防磁盘写满（生产 15GB 红线，Postgres 共享同一块盘）靠三道：
 *     1) 写新版本时 enforceVersionLimit（超版本数删最老草稿）—— 在 Service 里；
 *     2) 本命令的「保留期」清理 —— 删 failed / archived 超期产物 + 孤儿目录；
 *     3) 单租户字节配额（新建前拒绝）。
 *
 * 顺序铁律（红线 1）：先删文件，再删库行。复用 Service::deleteReplication()，
 *   它内部就是 deleteDirectory → logs/versions/replication，不会留孤儿文件。
 *
 * 处理对象：
 *   A. 全量删除（文件 + 库行）：status ∈ {failed, archived} 且 updated_at < 保留期。
 *      - failed：垃圾，用户可重试重建，删了无损失；
 *      - archived：用户显式丢弃，超期回收磁盘。
 *   B. 孤儿目录扫描（仅文件，无对应库行）：防 deleteReplication 中途失败 / 库行回滚
 *      但文件写盘导致的磁盘泄漏。只删 theme-packages/{正整数}/{正整数} 形状的目录，
 *      任何非数字散落物一律不碰，避免误删。
 *
 *   C. 产物回收（只删文件，保留库行）：status ∈ {ready, published, archived} 且
 *      updated_at < 保留期。红线 1 第 1 条要求「删掉 N 天前的生成物与 zip」，但没要求
 *      连任务记录一起删 —— 那是用户的交付记录。所以这里删 draft + zip、库行留着，
 *      并把 preview_snapshot_json / generated_files_json 清空：
 *        · isPreviewReady() → false → 预览 404；
 *        · canPackage()     → false → 下载 409，文案是「点重新生成」；
 *        · retry() 已放行这种记录 → 用户一键重建。
 *      不这么做的话，占磁盘最大头的 ready 草稿永远不回收，红线 1 第 1 条形同虚设。
 */
class ThemePackagesPruneCommand extends Command
{
    protected $signature = 'theme-packages:prune
        {--days= : 保留天数覆盖（默认读 config geo.theme_package.retention_days）}
        {--tenant= : 仅处理该租户 ID（调试 / 单租户收口用）}
        {--dry-run : 只报告将要删除的内容，不实际删除}';

    protected $description = '清理过期的主题包生成产物（红线 1：先删文件，再删库行）';

    /** 全量删除（文件 + 库行）的状态集合：垃圾任务，删了无损失。 */
    private const PRUNABLE_STATUSES = [
        SiteThemeReplication::STATUS_FAILED,
    ];

    /** 只回收文件、保留库行的状态集合：用户的交付记录，不能替他删。 */
    private const RECLAIMABLE_STATUSES = [
        SiteThemeReplication::STATUS_READY,
        SiteThemeReplication::STATUS_PUBLISHED,
        SiteThemeReplication::STATUS_ARCHIVED,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('geo.theme_package.retention_days', 14);
        $tenantFilter = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        if ($days <= 0) {
            $this->error('保留天数必须 > 0');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $this->info(
            '主题包清理：保留期 '.$days.' 天，截止 '.$cutoff->toDateTimeString()
            .($dryRun ? '（DRY-RUN，不删除）' : '')
        );

        $schemaReady = Schema::hasTable('site_theme_replications')
            && Schema::hasTable('site_theme_replication_versions');
        if (! $schemaReady) {
            // ⚠️ 表不存在时**什么都不能删**。原实现在这种情况下继续跑孤儿扫描，而扫描里
            //    「查不到库行 = 孤儿」，表没了就是每个租户目录都查不到 → 一次迁移回滚，
            //    次日 03:35 全租户产物清零。表不在 = 状态未知 = 不动。
            $this->warn('site_theme_replications 表不存在（迁移未跑或已回滚）；本次不做任何删除。');

            return self::SUCCESS;
        }

        $reclaimedBytes = 0;
        $deletedReplications = 0;
        $deletedOrphans = 0;
        $reclaimedArtifacts = 0;

        // ---- A) 全量删除：failed 超期（垃圾任务，用户可重试重建）----
        $query = SiteThemeReplication::query()
            ->whereIn('status', self::PRUNABLE_STATUSES)
            ->where('updated_at', '<', $cutoff);
        if ($tenantFilter !== null) {
            $query->where('tenant_id', $tenantFilter);
        }
        $candidates = $query->get();

        $service = app(SiteThemeReplicationService::class);
        $paths = app(ThemePackagePaths::class);

        foreach ($candidates as $replication) {
            $rel = $paths->replicationRoot($replication->tenant_id, (int) $replication->id);
            $bytes = $this->dirBytes(Storage::disk('local')->path($rel));

            if ($dryRun) {
                $this->line(
                    '  [dry-run] 将删除 replication id='.$replication->id
                    .' tenant='.$replication->tenant_id.' status='.$replication->status
                    .' (~'.$this->humanBytes($bytes).')'
                );
            } else {
                // 复用 Service：内部先删文件（draft + packages）再删库行。
                $service->deleteReplication($replication);
                $this->line(
                    '  ✓ 删除 replication id='.$replication->id
                    .' tenant='.$replication->tenant_id.' status='.$replication->status
                );
            }

            $reclaimedBytes += $bytes;
            $deletedReplications++;
        }

        // ---- C) 产物回收：ready / published / archived 超期，只删文件保留库行 ----
        $reclaimQuery = SiteThemeReplication::query()
            ->whereIn('status', self::RECLAIMABLE_STATUSES)
            ->where('updated_at', '<', $cutoff)
            // 已经回收过的不用再回收一次（回收会写 updated_at，否则每天重复报数）。
            ->whereNotNull('generated_files_json');
        if ($tenantFilter !== null) {
            $reclaimQuery->where('tenant_id', $tenantFilter);
        }

        foreach ($reclaimQuery->get() as $replication) {
            $rel = $paths->replicationRoot($replication->tenant_id, (int) $replication->id);
            $bytes = $this->dirBytes(Storage::disk('local')->path($rel));

            if ($dryRun) {
                $this->line(
                    '  [dry-run] 将回收产物（保留库行） id='.$replication->id
                    .' tenant='.$replication->tenant_id.' status='.$replication->status
                    .' (~'.$this->humanBytes($bytes).')'
                );
            } else {
                // ⚠️ reclaimArtifacts 会写一条日志行，而 BelongsToTenant 的 creating hook
                //    是从 TenantContext 取 tenant_id 的。cron 没有 HTTP 生命周期、天然无上下文，
                //    不先 set 就会插出 tenant_id=null → NOT NULL 违约（红线 2 的同一个坑）。
                //    用完必须 forget，否则后面的孤儿扫描会被这个租户的作用域框住。
                $tenant = $replication->tenant;
                if (! $tenant) {
                    $this->warn('  ! 跳过 id='.$replication->id.'：租户 '.$replication->tenant_id.' 不存在');

                    continue;
                }

                TenantContext::set($tenant);
                try {
                    $service->reclaimArtifacts($replication);
                } finally {
                    TenantContext::forget();
                }

                $this->line(
                    '  ✓ 回收产物（保留库行） id='.$replication->id
                    .' tenant='.$replication->tenant_id.' status='.$replication->status
                );
            }

            $reclaimedBytes += $bytes;
            $reclaimedArtifacts++;
        }
        // ---- B) 孤儿目录扫描（磁盘兜底）----
        $orphan = $this->sweepOrphans($schemaReady, $tenantFilter, $dryRun);
        $reclaimedBytes += $orphan['bytes'];
        $deletedOrphans += $orphan['dirs'];

        $this->info(
            '完成：删除 replication '.$deletedReplications.' 条，回收产物 '.$reclaimedArtifacts
            .' 条，孤儿目录 '.$deletedOrphans.' 个，回收约 '.$this->humanBytes($reclaimedBytes)
        );

        return self::SUCCESS;
    }

    /**
     * 扫描 theme-packages/ 下 {tenant}/{replication} 目录，删除无对应库行的孤儿。
     *
     * @return array{bytes:int,dirs:int}
     */
    private function sweepOrphans(bool $schemaReady, ?int $tenantFilter, bool $dryRun): array
    {
        $root = Storage::disk('local')->path(ThemePackagePaths::ROOT);
        if (! is_dir($root)) {
            return ['bytes' => 0, 'dirs' => 0];
        }

        $bytes = 0;
        $dirs = 0;

        foreach ($this->numericEntries($root) as $tenantDir) {
            $tenantId = (int) $tenantDir;
            if ($tenantFilter !== null && $tenantId !== $tenantFilter) {
                continue;
            }
            $tenantPath = $root.'/'.$tenantDir;

            $tenantExists = $schemaReady && Tenant::query()->where('id', $tenantId)->exists();
            if (! $tenantExists) {
                // 整租户孤儿（租户已删）：删整个 tenant 目录（仅文件，无库行）。
                // $schemaReady 到这里恒为 true —— 表不存在时 handle() 已经提前 return。
                $dirBytes = $this->dirBytes($tenantPath);
                if ($dryRun) {
                    $this->line('  [dry-run] 孤儿租户目录 tenant='.$tenantId.' (~'.$this->humanBytes($dirBytes).')');
                } else {
                    Storage::disk('local')->deleteDirectory(ThemePackagePaths::ROOT.'/'.$tenantDir);
                    $this->line('  ✓ 删除孤儿租户目录 tenant='.$tenantId);
                }
                $bytes += $dirBytes;
                $dirs++;
                continue;
            }

            foreach ($this->numericEntries($tenantPath) as $repDir) {
                $repId = (int) $repDir;
                $exists = $schemaReady
                    && SiteThemeReplication::query()
                        ->where('id', $repId)
                        ->where('tenant_id', $tenantId)
                        ->exists();
                if ($exists) {
                    continue; // 有库行，跳过。
                }
                $repPath = $tenantPath.'/'.$repDir;
                $dirBytes = $this->dirBytes($repPath);
                if ($dryRun) {
                    $this->line(
                        '  [dry-run] 孤儿 replication 目录 tenant='.$tenantId
                        .' rep='.$repId.' (~'.$this->humanBytes($dirBytes).')'
                    );
                } else {
                    Storage::disk('local')->deleteDirectory(ThemePackagePaths::ROOT.'/'.$tenantDir.'/'.$repDir);
                    $this->line('  ✓ 删除孤儿 replication 目录 tenant='.$tenantId.' rep='.$repId);
                }
                $bytes += $dirBytes;
                $dirs++;
            }
        }

        return ['bytes' => $bytes, 'dirs' => $dirs];
    }

    /**
     * 只返回 theme-packages 下「纯数字」的子目录名（租户 / replication id 都是正整数）。
     * 非数字散落物（文件、未知目录）一律忽略，绝不误删。
     *
     * @return array<int, string>
     */
    private function numericEntries(string $dir): array
    {
        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (is_dir($dir.'/'.$name) && ctype_digit($name)) {
                $entries[] = $name;
            }
        }

        return $entries;
    }

    private function dirBytes(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $total = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 1).' '.$units[$unit];
    }
}
