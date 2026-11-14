<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\ModelCallLog;
use App\Models\AdminActivityLog;
use App\Models\Project;
use App\Models\SecuritySetting;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Support\OperationalTextRedactor;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 平台超管运营面板（P1-4 商业运营后台）
 *
 * 所有方法绕过租户作用域，提供跨租户的全局运营视图。
 * 路由组需配合 admin:platform 中间件保护。
 */
class PlatformAdminController extends Controller
{
    /**
     * GET /api/v1/platform/overview
     * 平台级 KPI 概览
     */
    public function overview(Request $request): JsonResponse
    {
        $days = max(1, min((int) ($request->query('days', 30)), 366));
        $since = now()->subDays($days);

        return TenantContext::bypassScope(function () use ($since, $days) {
            $tenantCount = Tenant::count();
            $activeTenants = Tenant::where('status', 'active')->count();

            $userCount = User::where('created_at', '>=', $since)->count();
            $totalUsers = User::count();

            $projectCount = Project::whereNull('deleted_at')->count();

            $articleCount = Article::where('created_at', '>=', $since)->count();
            $publishedArticles = Article::where('status', 'published')->count();

            $executionCount = SkillExecution::where('created_at', '>=', $since)->count();
            $successfulExecutions = SkillExecution::where('created_at', '>=', $since)
                ->where('status', 'completed')
                ->count();
            $totalTokens = SkillExecution::where('created_at', '>=', $since)->sum('token_usage');

            $modelCallCount = ModelCallLog::where('created_at', '>=', $since)->count();
            $modelTokens = ModelCallLog::where('created_at', '>=', $since)->sum('total_tokens');

            $publicationCount = ArticlePublication::where('created_at', '>=', $since)->count();
            $publishedPublications = ArticlePublication::where('created_at', '>=', $since)
                ->where('status', ArticlePublication::STATUS_PUBLISHED)
                ->count();

            return response()->json([
                'period' => ['from' => $since->toDateString(), 'to' => now()->toDateString(), 'days' => $days],
                'platform' => [
                    'tenants_total' => $tenantCount,
                    'tenants_active' => $activeTenants,
                    'users_period' => $userCount,
                    'users_total' => $totalUsers,
                    'projects_total' => $projectCount,
                ],
                'content' => [
                    'articles_period' => $articleCount,
                    'articles_published_total' => $publishedArticles,
                    'publications_period' => $publicationCount,
                    'publications_published' => $publishedPublications,
                ],
                'execution' => [
                    'executions_period' => $executionCount,
                    'executions_success' => $successfulExecutions,
                    'tokens_period' => (int) $totalTokens,
                    'model_calls_period' => $modelCallCount,
                    'model_tokens_period' => (int) $modelTokens,
                ],
            ]);
        });
    }

    /**
     * GET /api/v1/platform/audit
     * 平台操作审计日志（阶段 3.4）。平台级全局视图，绕过租户作用域。
     */
    public function audit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'integer'],
            'target_type' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return TenantContext::bypassScope(function () use ($validated) {
            $query = AdminActivityLog::query();

            if (! empty($validated['action'])) {
                $query->where('action', 'like', '%'.$validated['action'].'%');
            }
            if (! empty($validated['actor_id'])) {
                $query->where('actor_id', $validated['actor_id']);
            }
            if (! empty($validated['target_type'])) {
                $query->where('target_type', $validated['target_type']);
            }
            if (! empty($validated['from'])) {
                $query->where('created_at', '>=', $validated['from']);
            }
            if (! empty($validated['to'])) {
                $query->where('created_at', '<=', $validated['to'].' 23:59:59');
            }

            $perPage = (int) ($validated['per_page'] ?? 25);
            $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);

            return response()->json([
                'data' => $logs->items(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'last_page' => $logs->lastPage(),
                ],
            ]);
        });
    }

    /**
     * GET /api/v1/platform/security-settings
     * 读取平台级安全设置（阶段 3.4）。tenant_id 为 null 表示平台默认。
     */
    public function registrationSettings(Request $request): JsonResponse
    {
        if ($request->isMethod('PUT')) {
            $data = $request->validate(['registration_enabled' => ['required', 'boolean']]);
            \Illuminate\Support\Facades\DB::table('instance_settings')->where('id', 1)->update($data);
        }
        return response()->json(['registration_enabled' => \App\Services\Auth\LocalAccounts::registrationEnabled()]);
    }

    public function securitySettings(): JsonResponse
    {
        return TenantContext::bypassScope(function () {
            $row = SecuritySetting::query()->whereNull('tenant_id')->first();
            $settings = $row?->settings ?? SecuritySetting::defaults();

            return response()->json(['settings' => $settings]);
        });
    }

    /**
     * PUT /api/v1/platform/security-settings
     * 更新平台级安全设置（阶段 3.4）。写入即落审计。
     */
    public function updateSecuritySettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password_min_length' => ['nullable', 'integer', 'min:6', 'max:64'],
            'require_special_char' => ['nullable', 'boolean'],
            'api_rate_limit_per_min' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'sensitive_words' => ['nullable', 'array'],
            'sensitive_words.*' => ['string', 'max:60'],
        ]);

        return TenantContext::bypassScope(function () use ($request, $validated) {
            $row = SecuritySetting::query()->whereNull('tenant_id')->first()
                ?? new SecuritySetting(['tenant_id' => null]);

            $merged = array_merge(SecuritySetting::defaults(), $row->settings ?? [], $validated);
            $row->tenant_id = null;
            $row->settings = $merged;
            $row->updated_by_user_id = $request->user()?->id;
            $row->save();

            return response()->json(['settings' => $row->settings]);
        });
    }

    /**
     * GET /api/v1/platform/tenants
     * 租户列表（含用量摘要）
     */
    public function tenants(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min((int) $request->query('per_page', 20), 50));

        return TenantContext::bypassScope(function () use ($page, $perPage) {
            $tenants = Tenant::query()
                ->withCount([
                    'projects',
                    'users',
                ])
                ->latest('id')
                ->paginate($perPage, ['*'], 'page', $page);

            $items = $tenants->getCollection()->map(function (Tenant $tenant) {
                $periodStart = now()->subDays(30);

                $execCount = SkillExecution::where('tenant_id', $tenant->id)
                    ->where('created_at', '>=', $periodStart)
                    ->count();
                $tokenUsage = SkillExecution::where('tenant_id', $tenant->id)
                    ->where('created_at', '>=', $periodStart)
                    ->sum('token_usage');
                $articleCount = Article::where('tenant_id', $tenant->id)->count();
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'subdomain' => $tenant->subdomain,
                    'status' => $tenant->status,
                    'plan' => $tenant->plan,
                    'projects_count' => $tenant->projects_count,
                    'users_count' => $tenant->users_count,
                    'last_30d' => [
                        'executions' => $execCount,
                        'tokens' => (int) $tokenUsage,
                    ],
                    'articles_total' => $articleCount,
                    'created_at' => $tenant->created_at?->toISOString(),
                ];
            });

            return response()->json([
                'object' => 'platform_tenant_list',
                'data' => $items,
                'pagination' => [
                    'current_page' => $tenants->currentPage(),
                    'last_page' => $tenants->lastPage(),
                    'per_page' => $tenants->perPage(),
                    'total' => $tenants->total(),
                ],
            ]);
        });
    }

    /**
     * GET /api/v1/platform/health
     * 系统健康检查（扣费链、模型调用成功率）
     */
    public function health(Request $request): JsonResponse
    {
        $since = now()->subHours(24);

        return TenantContext::bypassScope(function () use ($since) {
            $modelCalls = ModelCallLog::where('created_at', '>=', $since)->get();
            $total = $modelCalls->count();
            $completed = $modelCalls->where('status', 'completed')->count();

            // 执行失败率
            $executions = SkillExecution::where('created_at', '>=', $since)->get();
            $execTotal = $executions->count();
            $execFailed = $executions->where('status', 'failed')->count();

            // 发布失败率
            $publications = ArticlePublication::where('created_at', '>=', $since)->get();
            $pubTotal = $publications->count();
            $pubFailed = $publications->where('status', ArticlePublication::STATUS_FAILED)->count();

            return response()->json([
                'period' => 'last_24h',
                'model_calls' => [
                    'total' => $total,
                    'success_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : null,
                ],
                'executions' => [
                    'total' => $execTotal,
                    'failed' => $execFailed,
                    'failure_rate' => $execTotal > 0 ? round(($execFailed / $execTotal) * 100, 1) : null,
                ],
                'publications' => [
                    'total' => $pubTotal,
                    'failed' => $pubFailed,
                    'failure_rate' => $pubTotal > 0 ? round(($pubFailed / $pubTotal) * 100, 1) : null,
                ],
            ]);
        });
    }

    public function pauseTenant(Request $request, string $tenant): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return TenantContext::bypassScope(function () use ($tenant, $validated): JsonResponse {
            $target = Tenant::query()->where('subdomain', $tenant)
                ->when(ctype_digit($tenant), fn ($query) => $query->orWhere('id', (int) $tenant))->first();
            if (! $target) {
                abort(404, '租户不存在。');
            }

            // T7 防运营自锁：目标租户若含 platform_admin 用户，暂停会使其本人被永久锁在运营台门外且无 UI 自救途径，拒绝暂停。
            $hasPlatformAdmin = \App\Models\User::query()
                ->where('tenant_id', $target->id)
                ->where('role', 'platform_admin')
                ->exists();
            if ($hasPlatformAdmin) {
                return response()->json([
                    'message' => '该租户包含平台运营账号，暂停将使自己被锁在运营台外，已拒绝暂停。',
                ], 409);
            }

            $account = is_array($target->settings) ? ($target->settings['account_status'] ?? []) : [];
            $alreadyPaused = $target->status === 'paused';
            if ($alreadyPaused) {
                return response()->json([
                    'message' => '该租户已处于暂停状态。',
                ], 409);
            }

            $settings = $target->settings ?? [];
            $settings['account_status'] = ['previous_status' => $target->status,
                'reason' => $validated['reason'], 'note' => OperationalTextRedactor::redact($validated['note'] ?? null),
                'paused_at' => now()->toISOString()];
            $target->update(['status' => 'paused', 'settings' => $settings]);
            $result = ['status' => 'paused', 'tenant' => $target->fresh()];

            return response()->json($result);
        });
    }

    /**
     * B4.2 恢复 / 催续费租户（写，自动审计）
     */
    public function recoverTenant(Request $request, string $tenant): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:80'],
        ]);

        return TenantContext::bypassScope(function () use ($tenant, $validated): JsonResponse {
            $target = Tenant::query()->where('subdomain', $tenant)
                ->when(ctype_digit($tenant), fn ($query) => $query->orWhere('id', (int) $tenant))->first();
            if (! $target) {
                abort(404, '租户不存在。');
            }

            $account = is_array($target->settings) ? ($target->settings['account_status'] ?? []) : [];
            $isPaused = $target->status === 'paused';
            if (! $isPaused) {
                return response()->json([
                    'message' => '该租户未处于暂停状态，无需恢复。',
                ], 409);
            }

            $previous = $account['previous_status'] ?? 'active';
            $settings = $target->settings ?? [];
            $settings['account_status'] = array_merge($account, ['reason' => $validated['reason'],
                'recovered_at' => now()->toISOString()]);
            $target->update(['status' => in_array($previous, ['active', 'trialing', 'suspended'], true) ? $previous : 'active',
                'settings' => $settings]);
            $result = ['status' => 'recovered', 'tenant' => $target->fresh()];

            return response()->json($result);
        });
    }

}
