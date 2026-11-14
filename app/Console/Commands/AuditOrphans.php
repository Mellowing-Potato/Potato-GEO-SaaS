<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 只读盘点 GEO-SaaS 的测试孤儿数据（放量前收尾 T2）。
 *
 * 本命令只做 SELECT，绝不写入/删除任何数据。它输出：
 *   1) 候选测试渠道（默认 1,18）及其在各关联表的行数；
 *   2) 候选待删文章（默认 1,6）及其关联行数；
 *   3) 保留候选文章（默认 2,3,4,5）是否经 article_publications 牵连到测试渠道——
 *      仅为信息提示：article_publications 是中间 join 表，其三条外键
 *      (article_id / publishing_channel_id / tenant_id) 均为 ON DELETE CASCADE，
 *      但方向都是「删父表 → 删本 join 行」，不会从 join 表反向级联删 articles。
 *      因此删测试渠道只会连带删掉该渠道上的 publication 行，保留文章结构上不会被误删。
 *      真正的铁证是删后校验 keep_still_there=4（见 cleanup-orphans.sql 第 3 段）。
 *   4) 远端 WordPress 历史 E2E 帖（按 distribution_logs / article_publications 的 remote_id 反查）。
 *
 * 输出供经理逐条拍板；删除由 ops 在服务器侧执行（AI 只读）。
 */
class AuditOrphans extends Command
{
    protected $signature = 'geo:audit-orphans
        {--channels=1,18 : 逗号分隔的测试渠道 ID}
        {--articles=1,6 : 逗号分隔的待删文章 ID}
        {--keep=2,3,4,5 : 逗号分隔的保留文章 ID}
        {--json : 输出机器可读 JSON}';

    protected $description = '只读盘点测试孤儿数据（channels/articles 及其关联行），不删除任何数据';

    public function handle(): int
    {
        $channels = $this->parseList($this->option('channels'));
        $articles = $this->parseList($this->option('articles'));
        $keep = $this->parseList($this->option('keep'));

        $report = [
            'generated_at' => now()->toDateTimeString(),
            'channels' => [],
            'articles' => [],
            'keep_articles' => [],
            'cascade_risk' => [],
            'wp_remote_posts' => [],
        ];

        // ---- 1) 候选测试渠道 ----
        foreach ($channels as $id) {
            $row = DB::table('publishing_channels')->find($id);
            if (! $row) {
                $report['channels'][] = ['id' => $id, 'exists' => false];
                $this->warn("渠道 id={$id} 不存在（跳过）");
                continue;
            }
            $report['channels'][] = [
                'id' => $id,
                'exists' => true,
                'name' => $row->name,
                'type' => $row->type,
                'tenant_id' => $row->tenant_id,
                'distribution_channel_secrets' => DB::table('distribution_channel_secrets')->where('publishing_channel_id', $id)->count(),
                'article_publications' => DB::table('article_publications')->where('publishing_channel_id', $id)->count(),
                'project_publishing_channels' => DB::table('project_publishing_channels')->where('publishing_channel_id', $id)->count(),
                'distribution_logs' => DB::table('distribution_logs')->where('publishing_channel_id', $id)->count(),
            ];
        }

        // ---- 2) 候选待删文章 ----
        foreach ($articles as $id) {
            $row = DB::table('articles')->find($id);
            if (! $row) {
                $report['articles'][] = ['id' => $id, 'exists' => false];
                $this->warn("文章 id={$id} 不存在（跳过）");
                continue;
            }
            $report['articles'][] = [
                'id' => $id,
                'exists' => true,
                'title' => $row->title,
                'status' => $row->status,
                'tenant_id' => $row->tenant_id,
                'article_publications' => DB::table('article_publications')->where('article_id', $id)->count(),
                'distribution_logs' => DB::table('distribution_logs')->where('article_id', $id)->count(),
            ];
        }

        // ---- 3) 保留文章是否牵连测试渠道（高危） ----
        // 找出测试渠道下 article_publications 引用的所有 article_id
        $entangled = DB::table('article_publications')
            ->whereIn('publishing_channel_id', $channels)
            ->distinct()
            ->pluck('article_id')
            ->all();
        $entangledKeep = array_values(array_intersect($entangled, $keep));
        $report['cascade_risk'] = [
            'test_channel_article_ids' => $entangled,
            'keep_articles_entangled' => $entangledKeep,
            'has_risk' => count($entangledKeep) > 0,
        ];
        foreach ($keep as $id) {
            $row = DB::table('articles')->find($id);
            $publishedToTest = DB::table('article_publications')
                ->where('article_id', $id)
                ->whereIn('publishing_channel_id', $channels)
                ->count();
            $report['keep_articles'][] = [
                'id' => $id,
                'exists' => (bool) $row,
                'title' => $row?->title,
                'status' => $row?->status,
                'publications_to_test_channels' => $publishedToTest,
            ];
        }

        // ---- 4) 远端 WordPress 历史 E2E 帖 ----
        $wpRemote = [];
        $pubRemote = DB::table('article_publications')
            ->whereIn('publishing_channel_id', $channels)
            ->whereNotNull('remote_id')
            ->distinct()
            ->pluck('remote_id')
            ->all();
        foreach ($pubRemote as $r) {
            if ($r) {
                $wpRemote[] = $r;
            }
        }
        // distribution_logs.context 里可能含 remote_id（JSON）
        $logs = DB::table('distribution_logs')
            ->whereIn('publishing_channel_id', $channels)
            ->whereNotNull('context')
            ->get(['id', 'context']);
        foreach ($logs as $log) {
            $ctx = json_decode($log->context, true);
            if (is_array($ctx)) {
                foreach (['remote_id', 'post_id', 'wp_post_id'] as $key) {
                    if (! empty($ctx[$key]) && is_scalar($ctx[$key])) {
                        $wpRemote[] = (string) $ctx[$key];
                    }
                }
            }
        }
        $report['wp_remote_posts'] = array_values(array_unique($wpRemote));

        // ---- 输出 ----
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHuman($report, $channels, $articles, $keep);

        return self::SUCCESS;
    }

    private function renderHuman(array $r, array $channels, array $articles, array $keep): void
    {
        $this->info('========== GEO 测试孤儿数据盘点（只读） ==========');

        $this->line("\n[候选测试渠道] id ∈ {".implode(',', $channels).'}');
        $this->table(
            ['id', 'name', 'type', 'secrets', 'publications', 'proj_ch', 'dist_logs'],
            collect($r['channels'])->map(fn ($c) => [
                $c['id'],
                $c['exists'] ? ($c['name'] ?? '-') : 'NOT FOUND',
                $c['exists'] ? ($c['type'] ?? '-') : '-',
                $c['distribution_channel_secrets'] ?? '-',
                $c['article_publications'] ?? '-',
                $c['project_publishing_channels'] ?? '-',
                $c['distribution_logs'] ?? '-',
            ])->all()
        );

        $this->line("\n[候选待删文章] id ∈ {".implode(',', $articles).'}');
        $this->table(
            ['id', 'title', 'status', 'publications', 'dist_logs'],
            collect($r['articles'])->map(fn ($a) => [
                $a['id'],
                $a['exists'] ? ($a['title'] ?? '-') : 'NOT FOUND',
                $a['exists'] ? ($a['status'] ?? '-') : '-',
                $a['article_publications'] ?? '-',
                $a['distribution_logs'] ?? '-',
            ])->all()
        );

        $this->line("\n[保留候选文章] id ∈ {".implode(',', $keep).'}（默认保留，除非经理指示可清）');
        $this->table(
            ['id', 'title', 'status', '牵连测试渠道的 publications'],
            collect($r['keep_articles'])->map(fn ($a) => [
                $a['id'],
                $a['exists'] ? ($a['title'] ?? '-') : 'NOT FOUND',
                $a['exists'] ? ($a['status'] ?? '-') : '-',
                $a['publications_to_test_channels'],
            ])->all()
        );

        if ($r['cascade_risk']['has_risk']) {
            $this->line('ℹ️ 信息提示：以下保留文章曾发布到测试渠道，删渠道时其对应的 publication 行会被一并清除：');
            $this->line('   '.implode(',', $r['cascade_risk']['keep_articles_entangled']));
            $this->line('   这是 join 表的正常级联（删父渠道→删本行），不会反向删到 articles 表，保留文章本体安全。');
            $this->line('   铁证：删后校验保留文章行数应仍为 '.count($keep).'（keep_still_there）。');
        } else {
            $this->info('✓ 保留文章未牵连测试渠道，删渠道不影响其 publication 记录。');
        }

        $this->line("\n[远端 WordPress 历史 E2E 帖 remote_id]（ops 在 WP 后台删/回收站）");
        if ($r['wp_remote_posts']) {
            $this->line('   '.implode(', ', $r['wp_remote_posts']));
        } else {
            $this->line('   （无）');
        }

        $this->line("\n=== 本命令未删除任何数据。确认清单后由 ops 执行删除（先备份）。 ===");
    }

    private function parseList($value): array
    {
        if (is_array($value)) {
            return array_filter(array_map('intval', $value));
        }

        return array_filter(array_map('intval', explode(',', (string) $value)));
    }
}
