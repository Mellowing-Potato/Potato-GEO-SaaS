<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\IterateSiteThemeReplicationJob;
use App\Models\SiteThemeReplication;
use App\Services\ThemePackaging\SiteThemeReplicationService;
use App\Services\ThemePackaging\ThemeReplicationPackageService;
use App\Support\TenantContext;
use App\Support\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 主题包生成 API（单 10）。
 *
 * 路由组已挂 auth:sanctum + tenant + can:theme_package:manage（见 routes/api.php），
 * 且整段被 config('geo.theme_package_enabled') 包住（关 → 路由不注册 → 404）。
 *
 * ⚠️ 不用隐式模型绑定（{replication}）：SubstituteBindings 先于 IdentifyTenant 执行，
 *    那时 TenantScope 无上下文（fail-open）→ 跨租户 200 泄漏。这里全部用显式 {id}
 *    + 控制器内 resolveOwned()：先绕过作用域确认记录存在，再校验归属当前租户
 *    （执行书红线 3）。不存在 → 404（不暴露「有这条 ID 但没权限」）；
 *    存在但属于别的租户 → 403（执行书 V1 验收）。
 */
class ThemePackageController extends Controller
{
    public function __construct(private readonly SiteThemeReplicationService $service) {}

    /**
     * 显式归属校验（执行书红线 3）：先绕过租户作用域查出记录是否存在，
     * 再校验它属于当前登录用户所在的租户。
     *   - 不存在 → 404（不暴露「有这条 ID 但没权限」）；
     *   - 存在但属于别的租户 → 403（执行书 V1 验收）。
     * 绝不拿「查不到即 null」当权限判断，否则 fail-open 下会误读别人的记录。
     */
    private function resolveOwned(int $id, Request $request): SiteThemeReplication
    {
        $replication = SiteThemeReplication::withoutGlobalScope(TenantScope::class)->find($id);
        if (! $replication) {
            abort(404);
        }

        if ((int) $replication->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403, __('theme_package.error.not_your_tenant'));
        }

        return $replication;
    }

    public function index(): JsonResponse
    {
        // 列表要显示创建人姓名（原来显示的是裸 user_id 整数）。
        // ⚠️ 只 select id,name，不要 with('creator') 整行：users 表里有手机号、
        //    密码 hash、历史身份标识 等字段，整行带出去等于把它们发给前端。
        $items = SiteThemeReplication::query()
            ->with('creator:id,name')
            ->latest('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'theme_id' => ['required', 'string', 'max:64'],
            'base_theme_id' => ['nullable', 'string', 'max:64'],
            'home_url' => ['required', 'url', 'max:2048'],
            'category_url' => ['required', 'url', 'max:2048'],
            'article_url' => ['required', 'url', 'max:2048'],
            // ⚠️ 必须与 SiteThemeReplicationService::normalizeStylePreference() 的白名单逐字一致。
            //    对不上的后果是静默的：这里放行的值，Service 认不出就默默回落成 content_site，
            //    用户选了「品牌站」拿到的是内容站，界面上什么都不说。
            'style_preference' => ['nullable', 'string', 'in:content_site,brand_site,news_site'],
        ]);

        $payload = [
            'name' => $data['name'],
            'theme_id' => $data['theme_id'],
            'base_theme_id' => $data['base_theme_id'] ?? null,
            'home_url' => $data['home_url'],
            'category_url' => $data['category_url'],
            'article_url' => $data['article_url'],
            'style_preference' => $data['style_preference'] ?? 'content_site',
            'created_by_user_id' => $request->user()->id,
        ];

        try {
            $replication = $this->service->create($payload);
        } catch (QueryException $e) {
            // ⚠️ 这个 catch 必须排在下面那条前面：QueryException 继承自 PDOException，
            //    而 PDOException 继承自 RuntimeException —— 不单独接住的话，数据库报错原文
            //    （含 Connection / Database 名与完整 SQL 和用户填的全部值）会被下面那条
            //    当成「客户端错误」原样塞进 422 响应体吐给客户端。
            report($e);

            return response()->json(['message' => __('theme_package.error.create_failed')], 500);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // 参考站 URL 不合法 / 私网地址被出站安全链拦下 / theme_id 非法或重复 /
            // 超单租户配额等，都归为客户端错误，返回 422 而不是 500（执行书 V4 / V9 判据）。
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $replication], 201);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $replication = $this->resolveOwned($id, $request);

        return response()->json([
            // creator:id,name 同 index()（只两列，不带手机号 / 密码 hash）。
            // versions 只取四列：迭代抽屉要按「版本号 / 反馈内容 / 时间」列出往次反馈，
            // 而 versions 行里还有 blueprint_json / files_json / compliance_report_json
            // 三个大字段，整行带出去会让详情响应体涨几百 KB，界面一个字段都用不到。
            // ⚠️ hasMany 选列必须带上外键 replication_id，否则关联对不上、结果恒为空。
            'data' => $replication->load([
                'creator:id,name',
                'versions:id,replication_id,version,feedback,created_at',
            ]),
            'progress' => $this->service->progressSnapshot($replication),
            // 失败建议（5 类失败 × 3 条处置办法）服务端一直算得出来，但在这一行加上之前
            // 没有任何控制器调用过 failureAdvice()，所以客户一次也没见过。
            // 非 failed 状态它返回 null，前端判空即可。
            'failure_advice' => $this->service->failureAdvice($replication),
        ]);
    }

    public function status(int $id, Request $request): JsonResponse
    {
        $replication = $this->resolveOwned($id, $request);

        return response()->json([
            'data' => $replication->only(['id', 'status', 'current_version', 'error_message', 'compliance_status']),
            'progress' => $this->service->progressSnapshot($replication),
            // 轮询里也带上：一个包是在轮询过程中变成 failed 的，那一刻左栏就该
            // 拿得到分类说明，而不是等用户再点一次详情。
            'failure_advice' => $this->service->failureAdvice($replication),
        ]);
    }

    public function retry(int $id, Request $request): JsonResponse
    {
        $replication = $this->resolveOwned($id, $request);

        $replication = $this->service->retry($replication);

        return response()->json(['data' => $replication]);
    }

    public function iterate(Request $request, int $id): JsonResponse
    {
        $replication = $this->resolveOwned($id, $request);

        if (! in_array((string) $replication->status, [SiteThemeReplication::STATUS_READY, SiteThemeReplication::STATUS_PUBLISHED], true)) {
            abort(409, __('theme_package.error.iterate_only_ready'));
        }

        $data = $request->validate([
            'feedback' => ['required', 'string', 'max:2000'],
        ]);

        $replication->forceFill(['status' => SiteThemeReplication::STATUS_ITERATING])->save();
        $this->service->log($replication, 'info', 'iterating', __('theme_package.log.iterating'));

        IterateSiteThemeReplicationJob::dispatch($replication->id, $data['feedback'], TenantContext::id());

        return response()->json(['data' => $replication->fresh()]);
    }

    /**
     * 下载 zip。
     *
     * ⚠️ zip 是**这里现打**的，不是生成流程里预先打好的（上游 GEOFlow 也是在 download
     *    端点里调 createPackage，见 SiteThemeReplicationController::downloadPackage）。
     *    原实现只去磁盘上找一个「谁也没创建过」的 zip，找不到就 404 —— 下载功能等于没有。
     *    createPackage 内部有文件锁 + 逐文件校验 checksum/大小/软链，重复点下载是安全的。
     */
    public function download(int $id, Request $request, ThemeReplicationPackageService $packages): SymfonyResponse
    {
        $replication = $this->resolveOwned($id, $request);

        if (! $replication->canPackage()) {
            // 产物被保留期回收掉的记录（库行在、文件没了）走这条，给的是「重新生成」的指引。
            $reclaimed = empty($replication->generated_files_json) && (int) $replication->current_version > 0;

            abort(409, $reclaimed
                ? __('theme_package.error.artifacts_reclaimed')
                : __('theme_package.error.package_not_ready'));
        }

        try {
            $package = $packages->createPackage($replication);
        } catch (RuntimeException $e) {
            report($e);
            abort(409, __('theme_package.error.package_create_failed'));
        }

        $this->service->recordPackage($replication, $package);

        return response()->download((string) $package['absolute_path'], (string) $package['name']);
    }

    public function destroy(int $id, Request $request): JsonResponse
    {
        $replication = $this->resolveOwned($id, $request);

        $this->service->deleteReplication($replication);

        return response()->json(['message' => 'deleted'], 200);
    }
}
