<?php

namespace App\Providers;

use App\Listeners\RecordWorkerHeartbeat;
use App\Listeners\StampRequestId;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (!$this->app->runningInConsole()) \App\Support\InstanceConfiguration::validate();
        Event::listen(JobProcessing::class, static function (): void {
            \App\Support\InstanceConfiguration::validate();
        });
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(static function ($user, string $token): string {
            \App\Support\InstanceConfiguration::validate();
            return rtrim(config('app.url'), '/').'/login?'.http_build_query([
                'mode' => 'reset', 'token' => $token, 'email' => $user->getEmailForPasswordReset(),
            ]);
        });

        // 显式登记，不依赖事件自动发现——少一层「为什么没生效」的排查。
        Event::listen(RequestHandled::class, StampRequestId::class);

        // 单 9 · 队列心跳看板（执行书 §三-2）
        // Looping 每轮循环都发（空闲时也发），正是「我还活着」语义，挂它而非 JobProcessed/WorkerStarting。
        // 🔴 红线 1：监听器 : void 无 return，禁止返回 false（否则整台 worker 永久停摆）。
        Event::listen(Looping::class, [RecordWorkerHeartbeat::class, 'handleLooping']);
        if ($generation = getenv('YANJI_WORKER_GENERATION')) {
            $run = getenv('YANJI_ENV_PATH').'/run';
            require_once base_path('portable/src/Instance.php');
            require_once base_path('portable/src/JobClock.php');
            Event::listen(JobProcessing::class, static function (JobProcessing $event) use ($run, $generation): void {
                $job = $event->job;
                if ($job instanceof \Illuminate\Queue\Jobs\DatabaseJob) {
                    $seconds = $job->timeout() ?? (int) config('worker.timeout');
                    \Yanji\Portable\JobClock::record($run, $seconds > 0 ? [
                        'pid'=>getmypid(), 'generation'=>$generation, 'deadline'=>hrtime(true) + $seconds * 1_000_000_000,
                        'id'=>$job->getJobId(), 'uuid'=>$job->uuid(), 'attempts'=>$job->attempts(),
                        'reserved_at'=>(int) $job->getJobRecord()->reserved_at,
                    ] : null);
                }
            });
            foreach ([JobProcessed::class, JobExceptionOccurred::class, JobFailed::class] as $done) {
                Event::listen($done, static fn () => \Yanji\Portable\JobClock::record($run, null));
            }
        }
        Event::listen(WorkerStopping::class, [RecordWorkerHeartbeat::class, 'handleStopping']);

        // Database queue workers reuse the same PHP process. A job that exits through an
        // unexpected path must not leave its tenant on the next job's model queries.
        // During each job, missing context scopes to impossible tenant ID -1.
        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            if ($event->connectionName !== 'sync') {
                TenantContext::beginQueueJob();
            }
        });
        Event::listen(JobProcessed::class, static function (JobProcessed $event): void {
            if ($event->connectionName !== 'sync') {
                TenantContext::endQueueJob();
            }
        });
        foreach ([JobExceptionOccurred::class, JobFailed::class] as $event) {
            Event::listen($event, static function ($event): void {
                if ($event->connectionName !== 'sync') {
                    TenantContext::forget();
                }
            });
        }
        Event::listen(Looping::class, static function (): void {
            TenantContext::endQueueJob();
        });
        if ($hold = getenv('YANJI_UPGRADE_HOLD')) {
            // Record the real loop heartbeat, then pause before reserving any new job.
            Event::listen(Looping::class, static function () use ($hold): ?bool {
                clearstatcache(true, $hold);
                return is_file($hold) ? false : null;
            });
        }
        if ($stopFile = getenv('YANJI_STOP_FILE')) {
            // Portable Windows workers drain in-flight work and exit cleanly via this stop signal file.
            Event::listen(Looping::class, static function () use ($stopFile): void {
                clearstatcache(true, $stopFile);
                if (is_file($stopFile)) {
                    exit(0);
                }
            });
        }
        Event::listen(WorkerStopping::class, static function (): void {
            TenantContext::endQueueJob();
        });

        // 单 8 · 线索表单公开提交端点限流（执行书 §二-3）。
        // 本仓 cache 默认驱动是 database（见 config/cache.php：CACHE_STORE 默认 database，
        // 多容器共享同一张 cache 表）——限流计数跨容器生效，不是 array/file 那种形同虚设。
        // 双维度：同一 IP 限速 + 同一表单限速，两条都要：
        // 只限 IP，攻击者换 IP 就能刷爆某一个表单；只限表单，一个 IP 就能把某租户的表单额度占满。
        RateLimiter::for('lead-form-submit', function (Request $request) {
            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                // 评审修复 A2：form 维度 key 必须带租户段。L9 已确立同 slug 跨租户合法，
                // 不带租户段则「form:contact」是全平台共桶 —— A 租户打满后 B 租户同名表单
                // 第一次提交就 429。两侧都 strtolower 归一（resolve 查租户本就忽略大小写，
                // 不归一则大小写变体能 fork 出多个桶绕过限额）。
                Limit::perMinute(60)->by('form:'
                    .strtolower((string) $request->route('tenantSlug'))
                    .':'
                    .strtolower((string) $request->route('slug'))),
            ];
        });

        // 评审修复 H2：公开 GET 页此前零限流且每次访问都落 visit 行（含 404），
        // 匿名刷 GET 就是无限写库。挂限流封顶写放大；404 也记 visit 是从 /read 照抄的
        // X4 设计（死链信号有价值），保留，由限流封顶。
        //
        // 三轮评审：TrustProxies 已在 bootstrap/app.php 配好（$request->ip() 现在是真实客户端
        // 地址，不再是边缘 Caddy 的内网地址），所以 IP 维度加回来了——它才是挡单机狂刷的主力。
        // 双维度：
        //  - per IP 60/min：单个访客的写放大上限；
        //  - per 租户+表单 600/min：分布式刷（换 IP）时每张表单的 visit 写入总闸。
        //    600 = 10 次/秒，远高于任何真实的表单页流量，不会误伤正常访客；
        //    真要长期超过它，那是该报警的事，不该静默写库。
        RateLimiter::for('lead-form-view', function (Request $request) {
            return [
                Limit::perMinute(60)->by('view-ip:'.$request->ip()),
                Limit::perMinute(600)->by('view-form:'
                    .strtolower((string) $request->route('tenantSlug'))
                    .':'
                    .strtolower((string) $request->route('slug'))),
            ];
        });

        // 计划 §9.4 · 高成本接口限流（模型直连 / 文档摄入 / URL 抓取）。
        //
        // 设计取舍（为什么长这样）：
        //   1. **默认不限流**。口径是产品决策，代码只提供"填上就生效"的旋钮；没有配置时
        //      这里返回 Limit::none()，与改动前逐字一致 —— 不会因为这次改动把谁挡在门外。
        //   2. 计数维度是**租户 + 用户**：钱是按租户花的，一个人的狂刷不该拖垮同租户的同事，
        //      但同一个人换 IP 也不该绕开（所以不按 IP 计）；取不到身份（理论上不会，
        //      这些路由都要求登录）时退回 IP 维度，宁可少拦也别把所有人算成一个桶。
        //   3. 解析放在一个闭包里：'max,decay分钟' 两个数写在 config/env，改了不用发版。
        foreach (self::MONEY_ENDPOINT_LIMITS as $name => $configKey) {
            RateLimiter::for($name, function (Request $request) use ($configKey) {
                $limit = self::parseRateLimit(config('geo.rate_limits.'.$configKey));
                if ($limit === null) {
                    return Limit::none(); // 未配置 = 不限流（现状）
                }

                [$max, $decayMinutes] = $limit;

                return Limit::perMinutes($decayMinutes, $max)->by(self::moneyLimitKey($request));
            });
        }
    }

    /** 高成本接口的限流名 → config 键（路由里挂的就是这些名）。 */
    private const MONEY_ENDPOINT_LIMITS = [
        'geo-skill-execute' => 'skill_execute',
        'geo-skill-suggest' => 'skill_suggest',
        'geo-analysis-create' => 'analysis_create',
        'geo-knowledge-ingest' => 'knowledge_ingest',
        'geo-url-import' => 'url_import',
    ];

    /**
     * 解析 `'max,decay分钟'`。
     *
     * @return array{0:int,1:int}|null null = 没配（不限流）
     */
    public static function parseRateLimit(mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $parts = array_map('trim', explode(',', (string) $value));
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            // 写错了就当"没配"：限流配置写错把所有人挡在门外，比不限流更糟。
            return null;
        }
        [$max, $decay] = [(int) $parts[0], (int) $parts[1]];
        if ($max <= 0 || $decay <= 0) {
            return null;
        }

        return [$max, $decay];
    }

    /** 计数键：租户+用户优先，取不到身份退回 IP。 */
    private static function moneyLimitKey(Request $request): string
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id ?? $request->attributes->get('tenant')?->id;
        if ($user && ($user->id || $tenantId)) {
            return 'tenant:'.($tenantId ?? '-').':user:'.$user->id;
        }

        return 'ip:'.$request->ip();
    }
}
