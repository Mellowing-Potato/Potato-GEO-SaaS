<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * 由 scripts/migrate-console-command.mjs 从 routes/console.php 迁出（计划 §7 第 4 条：
 * 路由文件只留调度声明，命令实现进 app/Console/Commands）。
 *
 * 搬迁口径：只搬位置。签名字符串与 purpose 逐字复制，闭包体原样进 handle()，
 * 输出格式、退出码与用户可见文案没有任何改动。
 */
class GeoDistributionSmokeCommand extends Command
{
    protected $signature = 'geo:distribution-smoke
        {--endpoint= : WP 站点根 URL，如 http://geo-wp-smoke（内部别名，勿用公网 :8088）}
        {--wp-url= : WP 站点根 URL（别名，等同 --endpoint）}
        {--username= : WP 用户名}
        {--wp-user= : WP 用户名（别名，等同 --username）}
        {--secret= : WP 应用密码（24 位）}
        {--wp-app-password= : WP 应用密码（别名，等同 --secret）}
        {--tenant= : 一次性租户 id}
        {--status=publish : 发布状态，默认 publish；draft 更保守（只验建稿+鉴权不发 live）}
        {--with-image= : 可选，一个公网图片 URL，塞进正文以覆盖媒体同步路径}
        {--keep : 不清理临时文章/渠道/WP 帖子}
        {--check-health : 先跑 checkHealth 预探（有副作用：失败会累加 consecutive_failures 直至禁用渠道）}
        {--json : 机读输出}';

    protected $description = '把一篇临时文章经分发引擎同步发到真实 WordPress，断言 published + remote_url（首跑探针，绕开 worker 与全量扇出）';

    public function handle(): int
    {
        $endpoint = $this->option('endpoint') ?: $this->option('wp-url');
        $username = $this->option('username') ?: $this->option('wp-user');
        $secret = $this->option('secret') ?: $this->option('wp-app-password');
        $tenantId = $this->option('tenant');
        $status = $this->option('status') ?: 'publish';
        $withImage = $this->option('with-image');
        $keep = $this->option('keep');
        $checkHealth = $this->option('check-health');

        $missing = [];
        if (! $endpoint) $missing[] = '--endpoint (或 --wp-url)';
        if (! $username) $missing[] = '--username (或 --wp-user)';
        if (! $secret) $missing[] = '--secret (或 --wp-app-password)';
        if (! $tenantId) $missing[] = '--tenant';
        if ($missing) {
            foreach ($missing as $m) $this->error("缺少必填参数: {$m}");
            return Command::FAILURE;
        }

        // 1. 关自动扇出，否则 Article::create(status=published) 会经 booted 自动全量扇出到真渠道
        \App\Models\Article::$autoDistributionEnabled = false;
        $tenant = \App\Models\Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("未找到租户 id={$tenantId}");
            return Command::FAILURE;
        }
        // 2. 进租户上下文，否则 BelongsToTenant 作用域会过滤掉渠道/文章
        \App\Support\TenantContext::set($tenant);

        // 取租户下一个 project 填 articles.project_id（该列 NOT NULL）。
        // 关键：autoDistributionEnabled 已置 false，即便有 project_id 也不会触发 booted 自动扇出。
        $project = \App\Models\Project::where('tenant_id', $tenant->id)->first();
        if (! $project) {
            // 此处尚未建任何渠道/文章/publication，直接返回即可（勿引用尚未定义的 $channel）
            $this->error("租户 id={$tenantId} 下没有 Project，无法创建文章（articles.project_id 非空约束）");
            return Command::FAILURE;
        }

        // 3. 建真实 WP 渠道（经 model 直建，绕过 SafeChannelEndpoint SSRF 护栏；endpoint 用容器内部别名）
        $channel = \App\Models\PublishingChannel::create([
            'tenant_id'        => $tenant->id,
            'name'             => 'smoke-' . now()->timestamp,
            'type'             => \App\Models\PublishingChannel::TYPE_WORDPRESS_REST,
            'endpoint_url'     => rtrim($endpoint, '/'),
            'auth_type'        => 'wordpress_application_password',
            'username'         => $username,
            'encrypted_secret' => $secret, // cast=encrypted 自动加密，绝不落明文
            'channel_config'   => ['post_status' => $status],
            'settings'         => ['timeout' => 30],
            'is_active'        => true,
        ]);

        if ($checkHealth) {
            $health = app(\App\Services\Distribution\DistributionOrchestrator::class)->checkHealth($channel);
            if (! ($health['ok'] ?? false)) {
                $this->error('checkHealth 失败: ' . ($health['message'] ?? 'unknown'));
                if (! $keep) {
                    $channel->delete();
                }
                return Command::FAILURE;
            }
            $this->info('checkHealth OK: can_publish=' . var_export($health['can_publish'] ?? null, true)
                . ' can_upload=' . var_export($health['can_upload'] ?? null, true));
        }

        // 5. 建临时已发布文章（无 project_id，绕开 enqueueForArticle 的扇出选择器）
        $body = $withImage
            ? "Smoke 正文\n\n![img]({$withImage})"
            : 'Smoke 正文 ' . now();
        $article = \App\Models\Article::create([
            'tenant_id'     => $tenant->id,
            'project_id'    => $project->id,
            'title'         => 'GEO 分发 Smoke ' . now(),
            'body_markdown' => $body,
            'status'        => \App\Models\Article::STATUS_PUBLISHED,
        ]);

        // 6. 精确只给这一个渠道建 publication（不走 enqueueForArticle，避免全量扇出）
        $pub = \App\Models\ArticlePublication::create([
            'tenant_id'             => $tenant->id,
            'article_id'            => $article->id,
            'publishing_channel_id' => $channel->id,
            'status'                => \App\Models\ArticlePublication::STATUS_QUEUED,
            'attempt_count'         => 0,
        ]);

        // 7. 同步发布（不经 worker）
        $result = app(\App\Services\Distribution\DistributionOrchestrator::class)->process($pub);
        $pub->refresh();

        $pass = $pub->status === \App\Models\ArticlePublication::STATUS_PUBLISHED && ! empty($pub->remote_url);

        $payload = [
            'object'        => 'geo_distribution_smoke',
            'pass'          => $pass,
            'status'        => $pub->status,
            'remote_id'     => $pub->remote_id,
            'remote_url'    => $pub->remote_url,
            'last_error'    => $pub->last_error,
            'http_status'   => $result['http_status'] ?? null,
            'channel_id'    => $channel->id,
            'article_id'    => $article->id,
            'publication_id'=> $pub->id,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            if ($pass) {
                $this->info('PASS: 发布成功');
                $this->line("remote_url: {$pub->remote_url}");
                $this->line("remote_id: {$pub->remote_id}");
            } else {
                $this->error('FAIL: 发布未成功');
                $this->line("status: {$pub->status}");
                if ($pub->last_error) {
                    $this->line("last_error: {$pub->last_error}");
                }
                if (! empty($result['http_status'])) {
                    $this->line("http_status: {$result['http_status']}");
                }
            }
        }

        // 8. 清理（除非 --keep）
        if (! $keep) {
            try {
                app(\App\Services\Distribution\DistributionPublisherManager::class)->publisherFor($channel)->delete($pub);
            } catch (\Throwable $e) {
                // 删 WP 帖子失败不阻断本地清理；--keep 时留给人工查
            }
            $pub->delete();
            // This command creates a disposable probe record, not user content.
            // Soft deletion would leave the probe in the new article trash.
            $article->forceDelete();
            $channel->delete();
            $this->info('已清理临时 channel/article/publication' . ($withImage ? '（WP 媒体库可能残留图片）' : ''));
        } else {
            $this->info("已保留临时记录（--keep）。channel={$channel->id} article={$article->id} publication={$pub->id}");
        }

        return $pass ? Command::SUCCESS : Command::FAILURE;
    }
}
