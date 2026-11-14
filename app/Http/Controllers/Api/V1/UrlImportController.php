<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessUrlImportJob;
use App\Models\Project;
use App\Models\UrlImport;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * URL 文章导入控制器（阶段 1.3）
 *
 * 路由前缀 /api/v1/url-imports。创建需 article:create（admin / editor），查看需 article:view。
 *
 * 注：{urlImport} 为路由模型绑定，但绑定发生在 tenant 中间件之前（与项目 ensureCurrentTenant* 一致），
 * 故 show 内再手动 ensureOwns 校验租户归属，防止跨租户读取。
 */
class UrlImportController extends Controller
{
    // ─── 提交 URL 导入（异步）───
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url|max:2000',
            'project_id' => 'required|integer|exists:projects,id',
        ]);

        // project 必须属于当前租户（exists 规则不校验租户）
        $project = Project::query()->where('id', $validated['project_id'])->first();
        if (! $project || (int) $project->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }

        $import = UrlImport::create([
            'tenant_id' => TenantContext::id(),
            'project_id' => $validated['project_id'],
            'url' => $validated['url'],
            'status' => 'pending',
        ]);

        ProcessUrlImportJob::dispatch($import->tenant_id, $import->id);

        return response()->json([
            'object' => 'url_import',
            'data' => $import->toArray(),
        ], 201);
    }

    // ─── 列出当前租户导入任务 ───
    public function index(): JsonResponse
    {
        $items = UrlImport::query()->orderByDesc('created_at')->get();

        return response()->json([
            'object' => 'url_import_list',
            'data' => $items->map(fn (UrlImport $import): array => $import->toArray())->values(),
        ]);
    }

    // ─── 查看单条导入任务 ───
    public function show(UrlImport $urlImport): JsonResponse
    {
        $this->ensureOwns($urlImport);

        return response()->json([
            'object' => 'url_import',
            'data' => $urlImport->toArray(),
        ]);
    }

    private function ensureOwns(UrlImport $model): void
    {
        if ((int) $model->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
    }
}
