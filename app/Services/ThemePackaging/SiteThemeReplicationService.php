<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted theme generation, packaging and storage for independent workspaces.
 * Upstream: app/Services/Admin/SiteThemeReplicationService.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\ThemePackaging;

use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationLog;
use App\Models\SiteThemeReplicationVersion;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Jobs\RunSiteThemeReplicationJob;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class SiteThemeReplicationService
{
    /**
     * 上游还注入了 writer / guard / storageGuard / storageLock 四个依赖，本类一次都没用到
     * （写盘在 ThemeScaffoldWriter、扫描在 ThemeReplicationPipelineService、
     *  打包与锁在 ThemeReplicationPackageService）。留着只会让人以为这里也会写盘。
     */
    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly ThemePackagePaths $paths,
    ) {}

    /**
     * @param  array{name:string,theme_id:string,base_theme_id:?string,home_url:string,category_url:string,article_url:string,style_preference:string,created_by_user_id:?int}  $payload
     */
    public function create(array $payload): SiteThemeReplication
    {
        if (! $this->isSchemaReady()) {
            throw new RuntimeException(__('theme_package.message.migration_required'));
        }

        $this->assertWithinQuota();

        $normalizedUrls = $this->normalizeReferenceUrls([
            'home_url' => (string) $payload['home_url'],
            'category_url' => (string) $payload['category_url'],
            'article_url' => (string) $payload['article_url'],
        ]);

        $themeId = $this->normalizeThemeId((string) $payload['theme_id']);
        // 显式查重：不这么做的话，撞 unique(tenant_id, theme_id) 抛的是 QueryException，
        // 而它继承自 RuntimeException → 被控制器的 catch 接住 → 把带库名和完整 SQL 的
        // 报错原文当成 422 吐给客户端。查询本身带租户作用域，与唯一索引口径一致。
        if ($this->themeIdExists($themeId)) {
            throw new InvalidArgumentException(__('theme_package.validation.theme_id_exists'));
        }

        $baseThemeId = trim((string) ($payload['base_theme_id'] ?? ''));

        $replication = SiteThemeReplication::query()->create([
            'name' => trim((string) $payload['name']),
            'theme_id' => $themeId,
            'base_theme_id' => $baseThemeId !== '' ? $baseThemeId : null,
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'home_url' => $normalizedUrls['home_url'],
            'category_url' => $normalizedUrls['category_url'],
            'article_url' => $normalizedUrls['article_url'],
            'style_preference' => $this->normalizeStylePreference((string) ($payload['style_preference'] ?? 'content_site')),
            'source_fingerprints' => $this->buildSourceFingerprints($normalizedUrls),
            'compliance_status' => 'pending',
            'created_by_user_id' => $payload['created_by_user_id'] ?? null,
        ]);

        $this->log($replication, 'info', 'created', __('theme_package.log.created'), [
            'home_url' => $replication->home_url,
            'category_url' => $replication->category_url,
            'article_url' => $replication->article_url,
        ]);

        $this->log($replication, 'info', 'queued', __('theme_package.log.queued'));

        RunSiteThemeReplicationJob::dispatch((int) $replication->id, TenantContext::id());

        return $replication->fresh(['logs']) ?? $replication;
    }

    public function retry(SiteThemeReplication $replication): SiteThemeReplication
    {
        $status = (string) $replication->status;

        // 产物被 theme-packages:prune 按保留期回收掉的记录（库行还在、文件没了）
        // 也要能重新生成，否则用户手里剩一条既不能预览也不能下载的死记录。
        // 只认终态，避免把还在队列里跑的任务重复派单。
        $reclaimed = in_array($status, [
            SiteThemeReplication::STATUS_READY,
            SiteThemeReplication::STATUS_PUBLISHED,
            SiteThemeReplication::STATUS_ARCHIVED,
        ], true) && empty($replication->generated_files_json);

        if ($status !== SiteThemeReplication::STATUS_FAILED && ! $reclaimed) {
            return $replication;
        }

        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_QUEUED,
            'error_message' => null,
        ])->save();

        $this->log($replication, 'info', 'retry', __('theme_package.log.retry'));

        RunSiteThemeReplicationJob::dispatch((int) $replication->id, TenantContext::id());

        return $replication->fresh(['logs']) ?? $replication;
    }

    /**
     * @return array{base:SiteThemeReplicationVersion|null,target:SiteThemeReplicationVersion|null,rows:array<int,array{path:string,status:string,old_size:int,new_size:int}>,counts:array{added:int,modified:int,removed:int,unchanged:int}}
     */
    public function versionDiff(SiteThemeReplication $replication): array
    {
        $versions = $replication->versions()->latest('version')->limit(2)->get();
        $target = $versions->first();
        $base = $versions->get(1);

        $oldFiles = $base instanceof SiteThemeReplicationVersion ? $this->fileMap((array) $base->files_json) : [];
        $newFiles = $target instanceof SiteThemeReplicationVersion ? $this->fileMap((array) $target->files_json) : [];
        $paths = array_values(array_unique(array_merge(array_keys($oldFiles), array_keys($newFiles))));
        sort($paths);

        $rows = [];
        $counts = ['added' => 0, 'modified' => 0, 'removed' => 0, 'unchanged' => 0];
        foreach ($paths as $path) {
            $old = $oldFiles[$path] ?? null;
            $new = $newFiles[$path] ?? null;
            $status = match (true) {
                $old === null => 'added',
                $new === null => 'removed',
                (string) ($old['checksum'] ?? '') !== (string) ($new['checksum'] ?? '') => 'modified',
                default => 'unchanged',
            };
            $counts[$status]++;
            $rows[] = [
                'path' => $path,
                'status' => $status,
                'old_size' => (int) ($old['bytes'] ?? 0),
                'new_size' => (int) ($new['bytes'] ?? 0),
            ];
        }

        return [
            'base' => $base instanceof SiteThemeReplicationVersion ? $base : null,
            'target' => $target instanceof SiteThemeReplicationVersion ? $target : null,
            'rows' => $rows,
            'counts' => $counts,
        ];
    }

    /**
     * @return array{type:string,title:string,description:string,actions:array<int,string>}|null
     */
    public function failureAdvice(SiteThemeReplication $replication): ?array
    {
        if ((string) $replication->status !== SiteThemeReplication::STATUS_FAILED) {
            return null;
        }

        $latestLog = $replication->logs()->where('level', 'error')->latest('id')->first();
        $message = mb_strtolower((string) ($replication->error_message ?: $latestLog?->message ?: ''));
        $context = (array) ($latestLog?->context_json ?? []);
        $hasViolations = isset($context['violations']) && is_array($context['violations']);

        // ⚠️ 分支顺序与关键词都是 2026-09-03 修过的，改之前先看这两条：
        //
        //   ① 原来有个 'ai' 分支，但 lang 文件里根本没有 ai_title / ai_desc / ai_actions
        //      （只有 blueprint_*，见 resources/lang/en/theme_package.php 的注释：
        //       「上游这里叫 ai_*，本单不接 AI，改成 blueprint_*」）。
        //      命中它时 __() 找不到 key，会把 **原始 key 字符串** 当文案返回，
        //      客户会在界面上看到 theme_package.failure.ai_title。
        //      在把 failureAdvice() 接进控制器响应之前这条没人看得见，接出来就露脸了。
        //
        //   ② 那个分支用的是 str_contains($message, 'ai') —— 两个字母的子串，
        //      failed（f-ai-l）和 unavailable（unav-ai-lable）都会命中，
        //      而它排在 fetch 前面。于是最常见的一句 'Failed to fetch https://…'
        //      会被判成 AI 类失败。所以抓取判断挪到前面，蓝图那条只认长词。
        //
        // 判据：$type 只能取 scan / fetch / file / blueprint / unknown 五个值，
        //       每个值在 lang 文件里都有 _title / _desc / _actions 三个 key
        //       （由 ThemePackageGenTest::test_v18_failure_advice_types_all_have_lang_keys 守着）。
        $type = match (true) {
            $hasViolations || str_contains($message, 'compliance') || str_contains($message, '安全') || str_contains($message, 'scan') => 'scan',
            str_contains($message, 'http') || str_contains($message, 'url') || str_contains($message, '抓取') || str_contains($message, 'fetch') => 'fetch',
            str_contains($message, '草稿') || str_contains($message, 'file') || str_contains($message, 'source') || str_contains($message, 'zip') => 'file',
            str_contains($message, 'blueprint') || str_contains($message, '蓝图') || str_contains($message, 'model') || str_contains($message, '模型') => 'blueprint',
            default => 'unknown',
        };

        return [
            'type' => $type,
            'title' => __('theme_package.failure.'.$type.'_title'),
            'description' => __('theme_package.failure.'.$type.'_desc'),
            'actions' => (array) __('theme_package.failure.'.$type.'_actions'),
        ];
    }

    /**
     * @return Collection<int, SiteThemeReplication>
     */
    public function recent(int $limit = 3): Collection
    {
        if (! Schema::hasTable('site_theme_replications')) {
            return new Collection;
        }

        return SiteThemeReplication::query()
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, string>  $urls
     * @return array<string, string>
     */
    public function normalizeReferenceUrls(array $urls): array
    {
        $normalized = [];
        foreach ($urls as $key => $url) {
            $normalized[$key] = $this->normalizeReferenceUrl($url);
        }

        return $normalized;
    }

    public function normalizeThemeId(string $value): string
    {
        $themeId = Str::slug(Str::lower(trim($value)), '-');
        if ($themeId === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]{1,78}[a-z0-9]$/', $themeId)) {
            throw new InvalidArgumentException(__('theme_package.validation.theme_id_invalid'));
        }

        return $themeId;
    }

    public function themeIdExists(string $themeId): bool
    {
        if (! Schema::hasTable('site_theme_replications')) {
            return false;
        }

        return SiteThemeReplication::query()->where('theme_id', $themeId)->exists();
    }

    public function isSchemaReady(): bool
    {
        return Schema::hasTable('site_theme_replications')
            && Schema::hasTable('site_theme_replication_logs')
            && Schema::hasTable('site_theme_replication_versions');
    }

    /**
     * @param  iterable<int, SiteThemeReplicationLog>|null  $logs
     * @return array<string, mixed>
     */
    public function progressSnapshot(SiteThemeReplication $replication, ?iterable $logs = null): array
    {
        $logItems = collect($logs ?? $replication->logs()->oldest('id')->limit(100)->get())->values();
        $loggedSteps = $logItems
            ->pluck('step')
            ->filter()
            ->map(static fn ($step): string => (string) $step)
            ->values()
            ->all();

        $isIteration = in_array((string) $replication->status, [SiteThemeReplication::STATUS_ITERATING], true)
            || in_array('iteration_queued', $loggedSteps, true)
            || in_array('iterating', $loggedSteps, true);
        $hasReferenceSteps = array_intersect(['fetching', 'extracting'], $loggedSteps) !== [];

        $stageKeys = match (true) {
            $isIteration && $hasReferenceSteps => ['created', 'queued', 'iterating', 'fetching', 'extracting', 'analyzing', 'generating', 'scanning', 'ready'],
            $isIteration => ['created', 'queued', 'iterating', 'analyzing', 'generating', 'scanning', 'ready'],
            default => ['created', 'queued', 'fetching', 'extracting', 'analyzing', 'generating', 'scanning', 'ready'],
        };

        $statusStep = $this->statusToProgressStep((string) $replication->status, $logItems);
        $currentIndex = array_search($statusStep, $stageKeys, true);
        if ($currentIndex === false) {
            $currentIndex = max(0, count($stageKeys) - 1);
        }

        $status = (string) $replication->status;
        $isFailed = $status === SiteThemeReplication::STATUS_FAILED;
        $isTerminal = in_array($status, [
            SiteThemeReplication::STATUS_READY,
            SiteThemeReplication::STATUS_PUBLISHED,
            SiteThemeReplication::STATUS_ARCHIVED,
            SiteThemeReplication::STATUS_FAILED,
        ], true);

        $stages = [];
        foreach ($stageKeys as $index => $stageKey) {
            $state = 'pending';
            if ($isFailed && $index === $currentIndex) {
                $state = 'failed';
            } elseif ($isTerminal && ! $isFailed) {
                $state = 'done';
            } elseif ($index < $currentIndex) {
                $state = 'done';
            } elseif ($index === $currentIndex) {
                $state = 'current';
            }

            $log = $this->firstLogForStep($logItems, $stageKey);
            $stages[] = [
                'key' => $stageKey,
                'label' => __('theme_package.progress.step.'.$stageKey),
                'description' => __('theme_package.progress.step_desc.'.$stageKey),
                'state' => $state,
                'time' => $log ? optional($log->created_at)->toIso8601String() : null,
                'message' => $log ? (string) $log->message : null,
            ];
        }

        if ($isTerminal && ! $isFailed) {
            $progressPercent = 100;
        } else {
            $progressPercent = (int) round(($currentIndex / max(1, count($stageKeys) - 1)) * 100);
            if (! $isFailed) {
                $progressPercent = min(96, max(5, $progressPercent));
            }
        }

        $latestLog = $logItems->sortByDesc('id')->first();
        $lastUpdatedAt = $latestLog instanceof SiteThemeReplicationLog ? $latestLog->created_at : null;
        if ($replication->updated_at && (! $lastUpdatedAt || $replication->updated_at->gt($lastUpdatedAt))) {
            $lastUpdatedAt = $replication->updated_at;
        }

        return [
            'status' => $status,
            'status_label' => __('theme_package.status.'.$status),
            'current_step' => $statusStep,
            'current_step_label' => __('theme_package.progress.step.'.$statusStep),
            'progress_percent' => $progressPercent,
            'terminal' => $isTerminal,
            'failed' => $isFailed,
            'last_updated' => optional($lastUpdatedAt)->toIso8601String(),
            'stages' => $stages,
            'logs' => $logItems
                ->sortByDesc('id')
                ->take(20)
                ->values()
                ->map(static fn (SiteThemeReplicationLog $log): array => [
                    'id' => (int) $log->id,
                    'level' => (string) $log->level,
                    'step' => (string) $log->step,
                    'message' => (string) $log->message,
                    'time' => optional($log->created_at)->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function log(SiteThemeReplication $replication, string $level, string $step, string $message, array $context = []): SiteThemeReplicationLog
    {
        return SiteThemeReplicationLog::query()->create([
            'replication_id' => (int) $replication->id,
            'level' => $level,
            'step' => $step,
            'message' => $message,
            'context_json' => $context !== [] ? $context : null,
        ]);
    }

    private function statusToProgressStep(string $status, \Illuminate\Support\Collection $logs): string
    {
        if ($status === SiteThemeReplication::STATUS_FAILED) {
            $latestAction = $logs
                ->reverse()
                ->first(static fn (SiteThemeReplicationLog $log): bool => ! in_array((string) $log->step, ['', 'failed'], true));

            return $latestAction instanceof SiteThemeReplicationLog
                ? $this->normalizeProgressStep((string) $latestAction->step)
                : 'queued';
        }

        return match ($status) {
            SiteThemeReplication::STATUS_FETCHING => 'fetching',
            SiteThemeReplication::STATUS_EXTRACTING => 'extracting',
            SiteThemeReplication::STATUS_ANALYZING => 'analyzing',
            SiteThemeReplication::STATUS_GENERATING => 'generating',
            SiteThemeReplication::STATUS_SCANNING => 'scanning',
            SiteThemeReplication::STATUS_ITERATING => 'iterating',
            SiteThemeReplication::STATUS_READY,
            SiteThemeReplication::STATUS_PUBLISHED,
            SiteThemeReplication::STATUS_ARCHIVED => 'ready',
            default => 'queued',
        };
    }

    private function normalizeProgressStep(string $step): string
    {
        return match ($step) {
            'iteration_queued', 'iterating' => 'iterating',
            'ready', 'published', 'package_created', 'copied' => 'ready',
            'fetching', 'extracting', 'analyzing', 'generating', 'scanning', 'created', 'queued' => $step,
            default => 'queued',
        };
    }

    private function firstLogForStep(\Illuminate\Support\Collection $logs, string $step): ?SiteThemeReplicationLog
    {
        $aliases = match ($step) {
            'iterating' => ['iteration_queued', 'iterating'],
            'ready' => ['ready', 'published', 'package_created'],
            default => [$step],
        };

        $log = $logs->first(static fn (SiteThemeReplicationLog $item): bool => in_array((string) $item->step, $aliases, true));

        return $log instanceof SiteThemeReplicationLog ? $log : null;
    }

    private function normalizeReferenceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException(__('theme_package.validation.url_required'));
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(__('theme_package.validation.url_invalid'));
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(__('theme_package.validation.url_scheme'));
        }

        try {
            $this->safeHttp->resolveTarget($url);
        } catch (OutboundRequestBlockedException|OutboundRequestFailedException) {
            throw new InvalidArgumentException(__('theme_package.validation.url_private'));
        }

        return $url;
    }

    private function normalizeStylePreference(string $value): string
    {
        return in_array($value, ['content_site', 'brand_site', 'news_site'], true)
            ? $value
            : 'content_site';
    }

    /**
     * @param  array<string, string>  $urls
     * @return array<string, mixed>
     */
    private function buildSourceFingerprints(array $urls): array
    {
        return [
            'urls' => $urls,
            'domains' => array_values(array_unique(array_map(
                static fn (string $url): string => strtolower((string) parse_url($url, PHP_URL_HOST)),
                array_values($urls)
            ))),
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filesJson
     * @return array<string, array<string, mixed>>
     */
    private function fileMap(array $filesJson): array
    {
        $map = [];
        foreach ((array) ($filesJson['files'] ?? []) as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path !== '') {
                $map[$path] = (array) $file;
            }
        }

        return $map;
    }

    // ─── 配额与生命周期（红线 1：三道闸）─────────────────────────────────────

    /**
     * 新建前校验单租户字节配额：超过直接拒绝，不删旧的。
     *
     * 注意：只校「字节」这一道。版本数上限在写新版本时（enforceVersionLimit）
     * 处理，保留天数由 theme-packages:prune 命令处理。三道缺一不可。
     *
     * @throws RuntimeException 超出配额
     */
    public function assertWithinQuota(): void
    {
        $tenantId = TenantContext::id();
        if ($tenantId === null) {
            return;
        }

        $limit = (int) config('geo.theme_package.max_bytes_per_tenant', 500 * 1024 * 1024);
        if ($limit <= 0) {
            return;
        }

        $used = $this->tenantBytesUsed($tenantId);
        if ($used >= $limit) {
            throw new RuntimeException(__('theme_package.error.quota_exceeded', [
                'used' => $this->humanBytes($used),
                'limit' => $this->humanBytes($limit),
            ]));
        }
    }

    /**
     * 单租户在 storage 下 theme-packages/{tenant_id} 的累计字节数。
     * 遍历真实文件（含草稿目录与 packages 下的 zip），不含数据库行。
     */
    public function tenantBytesUsed(int $tenantId): int
    {
        $root = Storage::disk('local')->path($this->paths->tenantRoot($tenantId));
        if (! is_dir($root)) {
            return 0;
        }

        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    /**
     * 写新版本前：单 replication 版本数超上限则删最老草稿版本（含其文件）。
     * 不删当前版本，保证迭代与下载可继续。
     *
     * ⚠️ 调用点在 ThemeReplicationPipelineService：写完新版本文件、还没插版本行时调。
     *    所以这里只能按「版本号」删对应目录，绝不能删整个 draft/ —— 那会连刚写完的
     *    这一版一起抹掉（表现为生成成功但预览/下载全 404）。
     *    循环是为了兜住历史遗留超量（比如 config 从 20 调到 10）。
     */
    public function enforceVersionLimit(SiteThemeReplication $replication): void
    {
        $limit = (int) config('geo.theme_package.max_versions', 10);
        if ($limit <= 0) {
            return;
        }

        $currentVersion = (int) $replication->current_version;

        while ($replication->versions()->count() >= $limit) {
            $oldest = $replication->versions()->oldest('version')->first();
            if (! $oldest) {
                return;
            }

            // 当前版本是用户正在预览 / 下载的那一版，任何情况下都不删。
            if ((int) $oldest->version === $currentVersion) {
                return;
            }

            $this->deleteVersionFiles($replication, $oldest);
            $oldest->delete();
        }
    }

    /**
     * 删掉「某一个版本」的产物：draft/{version} 目录 + 该版本的 zip。
     * 只碰这一版，不碰兄弟版本。
     */
    private function deleteVersionFiles(SiteThemeReplication $replication, SiteThemeReplicationVersion $version): void
    {
        $versionNumber = (int) $version->version;
        if ($versionNumber <= 0) {
            return;
        }

        $disk = Storage::disk('local');

        $draft = $this->paths->draftFor($replication, $versionNumber);
        if ($disk->exists($draft)) {
            $disk->deleteDirectory($draft);
        }

        // zip 文件名里带 theme_id，落盘前先过一遍与 PathGuard 同一条正则，
        // 避免历史脏数据把奇怪的字符拼进删除路径。
        $themeId = (string) $replication->theme_id;
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{1,78}[A-Za-z0-9]\z/D', $themeId) !== 1) {
            return;
        }

        $zip = $this->paths->packagesFor($replication).'/'.$themeId.'-v'.$versionNumber.'.zip';
        if ($disk->exists($zip)) {
            $disk->delete($zip);
        }
    }

    // ─── 下载 / 删除（每个端点调用前已由 Controller 校验归属）─────────────────

    /**
     * 打包完成后把 zip 的相对路径与体积记到当前版本行上。
     *
     * 为什么要记：versions.package_path / versions.bytes 是清理任务与配额统计的依据，
     * 不写就永远是 null/0，「回收了多少」算不出来。
     *
     * @param  array{name:string,relative_path:string,absolute_path:string,bytes:int}  $package
     */
    public function recordPackage(SiteThemeReplication $replication, array $package): void
    {
        $version = $replication->versions()->latest('version')->first();
        if (! $version instanceof SiteThemeReplicationVersion) {
            return;
        }

        $version->forceFill([
            'package_path' => (string) $package['relative_path'],
        ])->save();
    }

    /**
     * 删除一条 replication：先删文件（draft + packages），再删库行。
     * 顺序不能反（执行书红线 1：反了留孤儿文件）。调用方已校验归属。
     */
    public function deleteReplication(SiteThemeReplication $replication): void
    {
        $disk = Storage::disk('local');
        $replicationRoot = $this->paths->replicationRootFor($replication);
        if ($disk->exists($replicationRoot)) {
            $disk->deleteDirectory($replicationRoot);
        }

        $replication->logs()->delete();
        $replication->versions()->delete();
        $replication->delete();
    }

    /**
     * 预览所需数据：这一版真实生成的 theme.css 原文 + 版本号。
     *
     * ⚠️ 故意不返回 draft 里的 .blade.php 原文。那些模板是给客户自己的 Laravel 站用的
     *    （@extends('theme.{id}.layout')、route('site.home')、$articles / $article…），
     *    本仓既没有那些路由也没有那些变量，渲染不了；把源码原样当 HTML 返回，
     *    客户在预览窗口里看到的是一屏 Blade 源码。
     *    （顺带：原实现里替换的 '{{ theme_css }}' 占位符，ThemeScaffoldWriter 从没写过。）
     *
     *    预览的正确形态 = 固定占位内容的示例页（resources/views/theme-package/preview.blade.php）
     *    套上这一版真实生成的配色与字体 —— 这正是本功能的真实产出（执行书 §〇-3：
     *    换的只有配色和字体，版式是同一套骨架）。
     *
     * @return array{page:string, css:string, version:int}|null
     */
    public function previewPage(SiteThemeReplication $replication, string $page): ?array
    {
        $allowed = ['home', 'category', 'article'];
        if (! in_array($page, $allowed, true)) {
            return null;
        }

        if (! $replication->isPreviewReady()) {
            return null;
        }

        $version = (int) $replication->current_version;
        if ($version <= 0) {
            return null;
        }

        $disk = Storage::disk('local');
        $css = $this->paths->draftFor($replication, $version).'/assets/theme.css';
        if (! $disk->exists($css)) {
            return null;
        }

        // CSS 会被内联进 <style>。ThemeReplicationAgent 生成的内容里不含尖括号
        // （颜色过 hex 正则、字体名过字符白名单），去掉尖括号不影响任何合法样式，
        // 但能堵死「磁盘上的 theme.css 被改坏 → </style><script>」这条出口。
        $css = str_replace(['<', '>'], '', (string) $disk->get($css));

        return ['page' => $page, 'css' => $css, 'version' => $version];
    }

    /**
     * 保留期回收：删掉这条任务的全部生成文件与 zip，但**保留库行**。
     *
     * 用于 ready / published / archived 这类用户还想留着记录的任务：磁盘要收，
     * 记录不能没（红线 1 要求「删掉 N 天前的生成物与 zip」，但没要求连任务一起删）。
     * 回收后 isPreviewReady() 与 canPackage() 都变 false，预览与下载给出明确文案，
     * 用户点「重新生成」即可重建 —— retry() 已放行这种「产物被回收」的记录。
     *
     * 顺序同红线 1：先删文件，再动库行。
     */
    public function reclaimArtifacts(SiteThemeReplication $replication): void
    {
        $disk = Storage::disk('local');
        $root = $this->paths->replicationRootFor($replication);
        if ($disk->exists($root)) {
            $disk->deleteDirectory($root);
        }

        $replication->versions()->update(['package_path' => null, 'bytes' => 0]);

        $replication->forceFill([
            'preview_snapshot_json' => null,
            'generated_files_json' => null,
        ])->save();

        $this->log($replication, 'info', 'pruned', __('theme_package.log.pruned'));
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
