<?php

use App\Http\Controllers\Api\V1\AiVisibilityController;
use App\Http\Controllers\Api\V1\VisibilityRunClaimController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\GeoAnalysisController;
use App\Http\Controllers\Api\V1\KnowledgeBaseController;
use App\Http\Controllers\Api\V1\ManualPublicationController;
use App\Http\Controllers\Api\V1\ManualPublicationSettingsController;
use App\Http\Controllers\Api\V1\MaterialController;
use App\Http\Controllers\Api\V1\TitleGenerationClaimController;
use App\Http\Controllers\Api\V1\PlatformAdminController;
use App\Http\Controllers\Api\V1\PlatformQueueHealthController;
use App\Http\Controllers\Api\V1\PromptController;
use App\Http\Controllers\Api\V1\UrlImportController;
use App\Http\Controllers\Api\V1\PlatformOperationsController;
use App\Http\Controllers\Api\V1\PlatformSensitiveWordController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\PublishingController;
use App\Http\Controllers\Api\V1\SkillExecutionController;
use App\Http\Controllers\Api\V1\SkillExecutionClaimController;
use App\Http\Controllers\Api\V1\SensitiveWordController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\EnterpriseKnowledgeController;
use App\Http\Controllers\Api\V1\GeoFlowBeaconController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\MagpieModelController;
use App\Http\Controllers\Api\V1\TenantAiKeyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GEO SaaS API Routes
|--------------------------------------------------------------------------
*/

// ─── 公开路由 ───

Route::post('v1/account/login', [AuthController::class, 'productLogin'])->middleware('throttle:10,1');
Route::post('v1/account/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('v1/auth/session-login', [AuthController::class, 'sessionLogin'])->middleware('throttle:60,1');
Route::post('v1/auth/local-start', [AuthController::class, 'localStart'])->middleware('throttle:10,1');
Route::post('v1/account/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('v1/account/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
Route::get('v1/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

// ─── 采集二期：外站分发内容回传信标（公开，无 session/Sanctum，密钥反查租户）───
Route::post('geoflow-beacon/v1/visits', [GeoFlowBeaconController::class, 'visits']);

// ─── 平台运营只读路由（不进入租户上下文）───
Route::prefix('v1/platform')
    ->middleware(['auth:sanctum', 'can:platform:operations', 'log-admin-activity'])
    ->group(function () {
        Route::get('operations', [PlatformOperationsController::class, 'index']);

        // ─── 阶段 3.4：审计日志 + 安全设置 ───
        Route::get('audit', [PlatformAdminController::class, 'audit'])->name('platform.audit');
        Route::get('security-settings', [PlatformAdminController::class, 'securitySettings'])->name('platform.security-settings.show');
        Route::put('security-settings', [PlatformAdminController::class, 'updateSecuritySettings'])->name('platform.security-settings.update');
        Route::match(['GET', 'PUT'], 'registration', [PlatformAdminController::class, 'registrationSettings']);


        // ─── §9.6 对账待办与人工复核入口 ───
        // 复核只落结论（谁/何时/依据/决定），资金冲正仍需在网关侧执行 —— 与命令口径一致。



    });

// ─── 租户隔离路由 ───
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'tenant', 'idempotency'])
    ->group(function () {

        // 当前用户
        Route::get('me', function (Request $request) {
            return $request->user()->load('tenant');
        });

        Route::get('me/permissions', function (Request $request) {
            return [
                'role' => $request->user()->role,
                'permissions' => $request->user()->getPermissions(),
            ];
        });

        // 清禾土豆条模型服务绑定（租户管理员）
        Route::patch('workspace', [\App\Http\Controllers\Api\V1\WorkspaceController::class, 'update'])
            ->middleware('can:team:manage');








        // 账户中心账户概览（只读聚合：余额/本月消耗/最近流水/订阅状态）


        // 浏览器退出同时撤销统一账户会话，避免刷新后又自动登录。
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('account/profile', [AuthController::class, 'profile']);
        Route::patch('account/profile', [AuthController::class, 'profile']);
        Route::patch('account/password', [AuthController::class, 'password'])->middleware('throttle:10,1');
        Route::get('account/sessions', [AuthController::class, 'sessions']);
        Route::post('account/sessions/revoke', [AuthController::class, 'sessions']);
        // 账户注销（计划 §9.5，用户口径 2026-09-21：申请 + 15 天冷静期 + 期内登录即恢复）。
        // 撤销注销不需要单独端点 —— 申请时全部登录都被撤销，客户重新登录那一次就是撤销。
        Route::get('account/deletion', [AuthController::class, 'deletionStatus']);
        Route::post('account/deletion', [AuthController::class, 'requestDeletion'])->middleware('throttle:5,1');

        // ─── 项目 ───
        Route::get('projects', [ProjectController::class, 'index'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::post('projects', [ProjectController::class, 'store'])
            ->middleware('can:project:create', 'scope:projects:write');

        // ⚠️ 垃圾桶路由必须排在 projects/{project} 之前，否则 "trash" 会被当成项目 id。
        // 删除权在 project:delete（admin）；垃圾桶里是"已删除"的内容，看和恢复都归同一权限。
        Route::get('projects/trash', [ProjectController::class, 'trash'])
            ->middleware('can:project:delete', 'scope:projects:write');

        // 全局回收站聚合入口。当前只接入项目，后续对象类型沿用相同返回契约。
        Route::get('trash', [ProjectController::class, 'trash'])
            ->middleware('can:project:delete', 'scope:projects:write');

        Route::get('projects/{project}', [ProjectController::class, 'show'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::patch('projects/{project}', [ProjectController::class, 'update'])
            ->middleware('can:project:create', 'scope:projects:write');

        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])
            ->middleware('can:project:delete', 'scope:projects:write');

        // withTrashed()：项目在垃圾桶里（软删）也必须能被路由绑定到，否则恢复端点永远 404。
        Route::post('projects/{project}/restore', [ProjectController::class, 'restore'])
            ->middleware('can:project:delete', 'scope:projects:write')
            ->withTrashed();

        // 外站撤稿重试（计划 §9.5：删项目先撤稿，失败的留待办并可重试）。
        // 同样 withTrashed()：多数情况下客户是在桶里那条待办上点"重试撤稿"。
        Route::post('projects/{project}/takedown', [ProjectController::class, 'takedown'])
            ->middleware('can:project:delete', 'scope:projects:write')
            ->withTrashed();

        Route::get('projects/{project}/stages', [ProjectController::class, 'stages'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::post('projects/{project}/stages/{stage}/advance', [ProjectController::class, 'advanceStage'])
            ->middleware('can:project:create', 'scope:projects:write');

        Route::post('projects/{project}/stages/rollback', [ProjectController::class, 'rollbackStage'])
            ->middleware('can:project:create', 'scope:projects:write');

        Route::get('projects/{project}/timeline', [ProjectController::class, 'timeline'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::get('projects/{project}/analyses', [GeoAnalysisController::class, 'index'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::post('projects/{project}/analyses', [GeoAnalysisController::class, 'store'])
            ->middleware('throttle:geo-analysis-create', 'can:project:create', 'scope:projects:write');

        Route::get('projects/{project}/analyses/{analysis}', [GeoAnalysisController::class, 'show'])
            ->middleware('can:project:view', 'scope:projects:read');

        // 取消分析是写操作，与发起分析同一道门（project:create），不用 view。
        Route::post('projects/{project}/analyses/{analysis}/cancel', [GeoAnalysisController::class, 'cancel'])
            ->middleware('can:project:create', 'scope:projects:write');

        Route::get('projects/{project}/analyses/{analysis}/download/{format?}', [GeoAnalysisController::class, 'download'])
            ->where('format', 'markdown|html|docx|pdf')
            ->middleware('can:project:view', 'scope:projects:read');
        Route::get('projects/{project}/analyses/{analysis}/download/{format}', [GeoAnalysisController::class, 'download'])
            ->middleware('can:project:view', 'scope:projects:read');

        // ─── 项目下的文章 ───
        Route::get('projects/{project}/articles', [ArticleController::class, 'projectArticles'])
            ->middleware('can:article:view', 'scope:articles:read');

        // ─── 文章（全局） ───
        Route::get('articles', [ArticleController::class, 'index'])
            ->middleware('can:article:view', 'scope:articles:read');

        Route::post('articles', [ArticleController::class, 'store'])
            ->middleware('can:article:create', 'scope:articles:write');

        Route::get('articles/{article}', [ArticleController::class, 'show'])
            ->middleware('can:article:view', 'scope:articles:read');

        Route::get('articles/{article}/preview', [ArticleController::class, 'preview'])
            ->middleware('can:article:view', 'scope:articles:read');

        Route::get('articles/{article}/download', [ArticleController::class, 'download'])
            ->middleware('can:article:view', 'scope:articles:read');

        // 微信富文本 HTML 导出（阶段 4 缺口补齐：复刻 GEOFlow WeChatArticleHtmlExporter）
        Route::get('articles/{article}/export/wechat', [ArticleController::class, 'exportWechat'])
            ->middleware('can:article:view', 'scope:articles:read');

        Route::get('articles/{article}/export/docx', [ArticleController::class, 'exportDocx'])
            ->middleware('can:article:view', 'scope:articles:read');

        Route::post('articles/{article}/submit-review', [ArticleController::class, 'submitForReview'])
            ->middleware('can:article:edit', 'scope:articles:write');

        Route::post('articles/{article}/approve', [ArticleController::class, 'approve'])
            ->middleware('can:article:publish', 'scope:articles:publish');

        Route::post('articles/{article}/request-changes', [ArticleController::class, 'requestChanges'])
            ->middleware('can:article:publish', 'scope:articles:publish');

        Route::get('articles/{article}/publications', [PublishingController::class, 'articlePublications'])
            ->middleware('can:article:view', 'scope:articles:read');

        Route::post('articles/{article}/publish', [PublishingController::class, 'publish'])
            ->middleware('can:article:publish', 'scope:articles:publish');

        Route::post('articles/{article}/publish-async', [PublishingController::class, 'publishAsync'])
            ->middleware('can:article:publish', 'scope:articles:publish');

        Route::post('articles/{article}/publish-batch', [PublishingController::class, 'publishBatch'])
            ->middleware('can:article:publish', 'scope:articles:publish');

        Route::post('articles/{article}/publications/{publication}/retry', [PublishingController::class, 'retry'])
            ->middleware('can:article:publish', 'scope:articles:publish');

        Route::patch('articles/{article}', [ArticleController::class, 'update'])
            ->middleware('can:article:edit', 'scope:articles:write');

        Route::delete('articles/{article}', [ArticleController::class, 'destroy'])
            ->middleware('can:article:delete', 'scope:articles:write');

        // ─── 文章审核历史 / 版本 / 已废弃状态 / 编辑器素材（阶段 2.1 / 2.2）───
        Route::get('articles/{article}/reviews', [ArticleController::class, 'reviews'])
            ->middleware('can:article:view', 'scope:articles:read');
        Route::get('articles/{article}/versions', [ArticleController::class, 'versions'])
            ->middleware('can:article:view', 'scope:articles:read');
        Route::post('articles/{article}/versions/{versionId}/restore', [ArticleController::class, 'restoreVersion'])
            ->middleware('can:article:edit', 'scope:articles:write');
        Route::post('articles/{article}/recycle', [ArticleController::class, 'recycle'])
            ->middleware('can:article:delete', 'scope:articles:write');
        Route::post('articles/{article}/restore', [ArticleController::class, 'restore'])
            ->middleware('can:article:edit', 'scope:articles:write');
        Route::post('articles/{article}/undelete', [ArticleController::class, 'undelete'])
            ->middleware('can:article:delete', 'scope:articles:write')
            ->withTrashed();
        Route::post('articles/{article}/takedown', [ArticleController::class, 'takedown'])
            ->middleware('can:article:delete', 'scope:articles:write')
            ->withTrashed();
        Route::get('articles/{article}/images', [ArticleController::class, 'images'])
            ->middleware('can:article:view', 'scope:articles:read');
        Route::post('articles/{article}/images', [ArticleController::class, 'attachImage'])
            ->middleware('can:article:edit', 'scope:articles:write');
        Route::delete('articles/{article}/images/{imageId}', [ArticleController::class, 'detachImage'])
            ->middleware('can:article:edit', 'scope:articles:write');

        // ─── Skill 注册表 + 执行 ───
        Route::get('skills', [SkillExecutionController::class, 'skills'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::post('skills/suggest-keywords', [SkillExecutionController::class, 'suggestKeywords'])
            ->middleware('throttle:geo-skill-suggest', 'can:project:create', 'scope:projects:write');

        Route::post('skills/suggest-competitors', [SkillExecutionController::class, 'suggestCompetitors'])
            ->middleware('throttle:geo-skill-suggest', 'can:project:create', 'scope:projects:write');

        Route::post('skills/execute', [SkillExecutionController::class, 'execute'])
            ->middleware('throttle:geo-skill-execute', 'can:project:create', 'scope:projects:write');

        Route::post('skills/execute-async', [SkillExecutionController::class, 'executeAsync'])
            ->middleware('throttle:geo-skill-execute', 'can:project:create', 'scope:projects:write');
        Route::post('skills/execute-async/claim', [SkillExecutionClaimController::class, 'claim'])
            ->middleware('can:project:create', 'scope:projects:write');
        Route::post('skills/execute-async/seen', [SkillExecutionClaimController::class, 'seen'])
            ->middleware('can:project:create', 'scope:projects:write');

        Route::get('executions', [SkillExecutionController::class, 'history'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::get('executions/{execution}', [SkillExecutionController::class, 'show'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::get('executions/{execution}/status', [SkillExecutionController::class, 'status'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::get('executions/{execution}/progress', [SkillExecutionController::class, 'progress'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::post('executions/{execution}/cancel', [SkillExecutionController::class, 'cancel'])
            ->middleware('can:project:create', 'scope:projects:write');

        Route::post('executions/{execution}/rerender', [SkillExecutionController::class, 'rerender'])
            ->middleware('can:project:create', 'scope:projects:write');

        Route::get('executions/{execution}/download/{format}', [SkillExecutionController::class, 'download'])
            ->where('format', 'markdown|html|docx|pdf')
            ->middleware('can:project:view', 'scope:projects:read');

        Route::get('usage', [\App\Http\Controllers\Api\V1\UsageController::class, 'usage'])
            ->middleware('can:team:manage', 'scope:analytics:read');

        // ─── 数据分析 ───
        Route::get('analytics/summary', [AnalyticsController::class, 'summary'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        Route::get('analytics/trends', [AnalyticsController::class, 'trends'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        Route::get('analytics/distribution', [AnalyticsController::class, 'distribution'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        Route::get('analytics/model-usage', [AnalyticsController::class, 'modelUsage'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        Route::get('analytics/by-project', [AnalyticsController::class, 'byProject'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        // ─── 阶段 3.1：访问日志 / 爬虫命中 / 趋势 ───
        Route::get('analytics/visit-logs', [AnalyticsController::class, 'visitLogs'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        Route::get('analytics/crawler-breakdown', [AnalyticsController::class, 'crawlerBreakdown'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        Route::get('analytics/traffic-trend', [AnalyticsController::class, 'trafficTrend'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        Route::get('analytics/top-articles', [AnalyticsController::class, 'topArticlesByVisits'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        Route::get('analytics/channel-beacon-health', [AnalyticsController::class, 'channelBeaconHealth'])
            ->middleware('can:analytics:view', 'scope:analytics:read');

        // ─── AI 可见性监测（批次二 · 单 3–单 6）───
        // POST 花真钱（豆包直连）：can:project:create 与 skills/execute 同口径。
        // 全部端点在控制器内受 geo.visibility_panel_enabled 门控（默认 false，404）。
        Route::post('visibility/runs', [AiVisibilityController::class, 'store'])
            ->middleware('can:project:create', 'scope:projects:write');

        Route::post('visibility/runs/claim', [VisibilityRunClaimController::class, 'claim'])
            ->middleware('can:project:create', 'scope:projects:write');
        Route::post('visibility/runs/seen', [VisibilityRunClaimController::class, 'seen'])
            ->middleware('can:project:create', 'scope:projects:write');

        Route::get('visibility/runs', [AiVisibilityController::class, 'index'])
            ->middleware('can:project:view', 'scope:projects:read');

        Route::get('visibility/question-suggestions', [AiVisibilityController::class, 'questionSuggestions'])
            ->middleware('can:project:view', 'scope:projects:read');

        // ⚠️ 不用隐式绑定：SubstituteBindings（api 组）先于 IdentifyTenant（路由级）
        // 执行，绑定时 TenantScope 尚无上下文（fail-open）→ 跨租户 200 泄漏（V7 测试实测）。
        // 显式 {runId} + 控制器内 TenantScope 查询（此时租户上下文已就位）。
        Route::get('visibility/runs/{runId}', [AiVisibilityController::class, 'show'])
            ->whereNumber('runId')
            ->middleware('can:project:view', 'scope:projects:read');

        Route::get('visibility/runs/{runId}/download/{format?}', [AiVisibilityController::class, 'download'])
            ->whereNumber('runId')
            ->where('format', 'markdown|html')
            ->middleware('can:project:view', 'scope:projects:read');

        // ─── 团队管理 ───
        Route::prefix('team')->group(function () {
            Route::get('/', [TeamController::class, 'index'])
                ->middleware('can:team:manage');

            Route::post('invite', [TeamController::class, 'disabled'])
                ->middleware('can:team:invite');

            Route::delete('{user}', [TeamController::class, 'disabled'])
                ->middleware('can:team:remove');

            Route::patch('{user}/role', [TeamController::class, 'disabled'])
                ->middleware('can:team:change_role');
        });

        // ─── 作用域 API Token 管理（阶段 4 缺口补齐：复刻 GEOFlow ApiTokenController）───
        // 自建自管：当前登录成员签发/查看/吊销自己的作用域 Token；列表与吊销按 tokenable 隔离。
        Route::prefix('api-tokens')->group(function () {
            Route::get('/', [ApiTokenController::class, 'index'])
                ->middleware('can:team:manage');
            Route::post('/', [ApiTokenController::class, 'store'])
                ->middleware('can:team:manage');
            Route::delete('{id}', [ApiTokenController::class, 'revoke'])
                ->middleware('can:team:manage');
        });

        // ─── 知识库（RAG） ───
        Route::get('knowledge-legacy', [\App\Http\Controllers\Api\V1\LegacyKnowledgeController::class, 'index'])->middleware('can:project:view', 'scope:knowledge:read');
        Route::post('knowledge-legacy/assign', [\App\Http\Controllers\Api\V1\LegacyKnowledgeController::class, 'assign'])->middleware('can:project:create', 'scope:knowledge:write');
        Route::get('knowledge-bases/stats', [KnowledgeBaseController::class, 'stats'])
            ->middleware('can:project:view', 'scope:knowledge:read');

        Route::get('knowledge-bases', [KnowledgeBaseController::class, 'index'])
            ->middleware('can:project:view', 'scope:knowledge:read');

        Route::post('knowledge-bases', [KnowledgeBaseController::class, 'store'])
            ->middleware('can:project:create', 'scope:knowledge:write');

        Route::get('knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'show'])
            ->middleware('can:project:view', 'scope:knowledge:read');

        Route::delete('knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'destroy'])
            ->middleware('can:project:delete', 'scope:knowledge:write');

        Route::post('knowledge-bases/{knowledgeBase}/documents', [KnowledgeBaseController::class, 'uploadDocument'])
            ->middleware('throttle:geo-knowledge-ingest', 'can:project:create', 'scope:knowledge:write');

        Route::get('knowledge-bases/{knowledgeBase}/documents', [KnowledgeBaseController::class, 'listDocuments'])
            ->middleware('can:project:view', 'scope:knowledge:read');

        Route::post('knowledge-bases/{knowledgeBase}/documents/{document}/recrawl', [KnowledgeBaseController::class, 'recrawlDocument'])
            ->middleware('throttle:geo-knowledge-ingest', 'can:project:create', 'scope:knowledge:write');
        Route::post('knowledge-bases/{knowledgeBase}/documents/{document}/archive', [KnowledgeBaseController::class, 'archiveDocument'])
            ->middleware('can:project:update', 'scope:knowledge:write');

        Route::delete('knowledge-bases/{knowledgeBase}/documents/{document}', [KnowledgeBaseController::class, 'destroyDocument'])
            ->middleware('can:project:delete', 'scope:knowledge:write');

        // A3：查看文档入库后的切片正文（解析结果可见）。租户隔离由 BelongsToTenant
        // 全局作用域保证，控制器内再校验 document 属于这个 kb 否则 404。
        Route::get('knowledge-bases/{knowledgeBase}/documents/{document}/chunks', [KnowledgeBaseController::class, 'showDocumentChunks'])
            ->middleware('can:project:view', 'scope:knowledge:read');

        // B6-1：图文对照 —— 取某页的原始渲染图（多模态文档专用，私有 disk，
        // 租户校验同上）。文件不存在 404，不给占位图。
        Route::get('knowledge-bases/{knowledgeBase}/documents/{document}/pages/{page}/image', [KnowledgeBaseController::class, 'showDocumentPageImage'])
            ->middleware('can:project:view')
            ->whereNumber('page')->middleware('scope:knowledge:read');

        Route::post('knowledge-bases/{knowledgeBase}/ingest-url', [KnowledgeBaseController::class, 'ingestUrl'])
            ->middleware('throttle:geo-knowledge-ingest', 'can:project:create', 'scope:knowledge:write');

        // 第三层（检索测试框）：可视化现有 RAG 检索链路，只读、不调模型回答。
        // 只调用 KnowledgeRetrievalServiceImpl::retrieve()，端点不新写检索逻辑。
        Route::post('knowledge-bases/{knowledgeBase}/search-test', [KnowledgeBaseController::class, 'searchTest'])
            ->middleware('can:project:view', 'scope:knowledge:read');

        // ─── 租户 AI Key 管理 ───
        Route::get('ai-keys', [TenantAiKeyController::class, 'index'])
            ->middleware('can:team:manage');

        Route::post('ai-keys', [TenantAiKeyController::class, 'store'])
            ->middleware('can:team:manage');

        Route::post('ai-keys/test', [TenantAiKeyController::class, 'testConnection'])
            ->middleware('can:team:manage', 'throttle:10,1');

        Route::prefix('ai-keys/magpie')->middleware('can:team:manage')->group(function () {
            Route::get('/', [MagpieModelController::class, 'index']);
            Route::post('local-connect', [MagpieModelController::class, 'localConnect'])->middleware('throttle:10,1');
            Route::post('profiles', [MagpieModelController::class, 'store']);
            Route::patch('profiles/{profile}', [MagpieModelController::class, 'update'])->whereNumber('profile');
            Route::delete('profiles/{profile}', [MagpieModelController::class, 'destroy'])->whereNumber('profile');
            Route::post('profiles/{profile}/directory', [MagpieModelController::class, 'directory'])
                ->whereNumber('profile')->middleware('throttle:30,1');
            Route::post('profiles/{profile}/test', [MagpieModelController::class, 'test'])
                ->whereNumber('profile')->middleware('throttle:10,1');
            Route::put('bindings/{slot}', [MagpieModelController::class, 'bind'])
                ->whereIn('slot', ['text', 'vision'])->middleware('throttle:30,1');
        });

        Route::delete('ai-keys/{key}', [TenantAiKeyController::class, 'destroy'])
            ->middleware('can:team:manage');

        Route::patch('ai-keys/{key}/toggle', [TenantAiKeyController::class, 'toggle'])
            ->middleware('can:team:manage');

        // ─── 素材库（标题 / 关键词 / 图片 / 作者，阶段 1.1）───
        Route::prefix('materials')->group(function () {
            // 标题库
            Route::get('titles', [MaterialController::class, 'indexTitles'])
                ->middleware('can:project:view', 'scope:materials:read');
            Route::post('titles', [MaterialController::class, 'storeTitle'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::get('titles/{title}', [MaterialController::class, 'showTitle'])
                ->middleware('can:project:view', 'scope:materials:read');
            Route::patch('titles/{title}', [MaterialController::class, 'updateTitle'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::delete('titles/{title}', [MaterialController::class, 'destroyTitle'])
                ->middleware('can:material:manage', 'scope:materials:write');

            // 标题 AI 批量生成（阶段 4 缺口补齐：复刻 GEOFlow TitleAiGenerationService）
            Route::post('titles/generate', [MaterialController::class, 'generateTitles'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::post('titles/generate/claim', [TitleGenerationClaimController::class, 'claim'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::post('titles/generate/seen', [TitleGenerationClaimController::class, 'seen'])
                ->middleware('can:material:manage', 'scope:materials:write');

            // 关键词库
            Route::get('keywords', [MaterialController::class, 'indexKeywords'])
                ->middleware('can:project:view', 'scope:materials:read');
            Route::post('keywords', [MaterialController::class, 'storeKeyword'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::get('keywords/{keyword}', [MaterialController::class, 'showKeyword'])
                ->middleware('can:project:view', 'scope:materials:read');
            Route::patch('keywords/{keyword}', [MaterialController::class, 'updateKeyword'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::delete('keywords/{keyword}', [MaterialController::class, 'destroyKeyword'])
                ->middleware('can:material:manage', 'scope:materials:write');

            // 图片库
            Route::get('images', [MaterialController::class, 'indexImages'])
                ->middleware('can:project:view', 'scope:materials:read');
            Route::post('images', [MaterialController::class, 'storeImage'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::get('images/{image}', [MaterialController::class, 'showImage'])
                ->middleware('can:project:view', 'scope:materials:read');
            Route::patch('images/{image}', [MaterialController::class, 'updateImage'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::delete('images/{image}', [MaterialController::class, 'destroyImage'])
                ->middleware('can:material:manage', 'scope:materials:write');

            // 作者库
            Route::get('authors', [MaterialController::class, 'indexAuthors'])
                ->middleware('can:project:view', 'scope:materials:read');
            Route::post('authors', [MaterialController::class, 'storeAuthor'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::get('authors/{author}', [MaterialController::class, 'showAuthor'])
                ->middleware('can:project:view', 'scope:materials:read');
            Route::patch('authors/{author}', [MaterialController::class, 'updateAuthor'])
                ->middleware('can:material:manage', 'scope:materials:write');
            Route::delete('authors/{author}', [MaterialController::class, 'destroyAuthor'])
                ->middleware('can:material:manage', 'scope:materials:write');

            // 技能 brief 抽取 + 使用记录
            Route::post('pick', [MaterialController::class, 'pick'])
                ->middleware('can:project:view', 'scope:materials:read');
            Route::post('record-usage', [MaterialController::class, 'recordUsage'])
                ->middleware('can:material:manage', 'scope:materials:write');
        });

        // ─── 文章分类（阶段 2.1）───
        Route::get('categories', [CategoryController::class, 'index'])
            ->middleware('can:project:view', 'scope:categories:read');
        Route::post('categories', [CategoryController::class, 'store'])
            ->middleware('can:category:manage', 'scope:categories:write');
        Route::get('categories/{category}', [CategoryController::class, 'show'])
            ->middleware('can:project:view', 'scope:categories:read');
        Route::patch('categories/{category}', [CategoryController::class, 'update'])
            ->middleware('can:category:manage', 'scope:categories:write');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])
            ->middleware('can:category:manage', 'scope:categories:write');

        // ─── 敏感词管理（阶段 4 缺口补齐：复刻 GEOFlow SensitiveWord 实体 + 管理台）───
        Route::prefix('sensitive-words')->group(function () {
            Route::get('/', [SensitiveWordController::class, 'index'])
                ->middleware('can:article:edit');
            Route::post('/', [SensitiveWordController::class, 'store'])
                ->middleware('can:article:edit');
            Route::delete('{id}', [SensitiveWordController::class, 'destroy'])
                ->middleware('can:article:edit');
        });

        // ─── 提示词管理台（每技能租户提示词覆盖，阶段 1.2）───
        Route::any('skill-prompts/{skill?}', fn () => response()->json(['message' => '此入口已停用，请在当前项目的生成偏好中设置。'], 410));
        Route::prefix('generation-preferences')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\GenerationPreferenceController::class, 'index'])
                ->middleware('can:project:view');
            Route::get('{skill}', [\App\Http\Controllers\Api\V1\GenerationPreferenceController::class, 'show'])
                ->middleware('can:project:view');
            Route::put('{skill}', [\App\Http\Controllers\Api\V1\GenerationPreferenceController::class, 'update'])
                ->middleware('can:prompt:manage');
            Route::delete('{skill}', [\App\Http\Controllers\Api\V1\GenerationPreferenceController::class, 'reset'])
                ->middleware('can:prompt:manage');
        });

        // ─── URL 文章导入加工（阶段 1.3）───
        Route::prefix('url-imports')->group(function () {
            Route::post('/', [UrlImportController::class, 'store'])
                ->middleware('throttle:geo-url-import', 'can:article:create', 'scope:articles:write');
            Route::get('/', [UrlImportController::class, 'index'])
                ->middleware('can:article:view', 'scope:articles:read');
            Route::get('{urlImport}', [UrlImportController::class, 'show'])
                ->middleware('can:article:view', 'scope:articles:read');
        });

        // ─── 最小分发渠道（WordPress REST / HTTP Webhook）───
        Route::get('publishing/channels', [PublishingController::class, 'channels'])
            ->middleware('can:article:publish');

        Route::post('publishing/channels', [PublishingController::class, 'storeChannel'])
            ->middleware('can:channel:manage');

        Route::patch('publishing/channels/{channel}', [PublishingController::class, 'updateChannel'])
            ->middleware('can:channel:manage');

        Route::delete('publishing/channels/{channel}', [PublishingController::class, 'deleteChannel'])
            ->middleware('can:channel:manage');

        // ─── 分发引擎管理 ───
        Route::get('publishing/channels/{channel}/health', [PublishingController::class, 'channelHealth'])
            ->middleware('can:article:publish');

        Route::get('publishing/channels/{channel}/logs', [PublishingController::class, 'distributionLogs'])
            ->middleware('can:article:publish');

        Route::post('publishing/channels/{channel}/secrets', [PublishingController::class, 'generateSecret'])
            ->middleware('can:channel:manage');

        Route::delete('publishing/channels/{channel}/secrets/{secret}', [PublishingController::class, 'revokeSecret'])
            ->middleware('can:channel:manage');

        // ─── B6.4 项目绑定分发渠道 ───
        Route::get('publishing/project-channels', [PublishingController::class, 'projectChannels'])
            ->middleware('can:article:publish');

        Route::post('publishing/project-channels/bind', [PublishingController::class, 'bindProjectChannels'])
            ->middleware('can:channel:manage');

        Route::post('publishing/project-channels/unbind', [PublishingController::class, 'unbindProjectChannels'])
            ->middleware('can:channel:manage');

        // ─── W2 WordPress 信标插件生成 ───
        Route::get('publishing/channels/{channel}/beacon-plugin', [PublishingController::class, 'generateBeaconPlugin'])
            ->middleware('can:channel:manage');

        // ─── B4.3 伪静态规则生成 ───
        Route::get('publishing/channels/{channel}/rewrite-rules', [PublishingController::class, 'generateRewriteRules'])
            ->middleware('can:article:publish');

        // ─── 阶段 4 缺口补齐：WP 分类/标签批量预同步 ───
        Route::post('publishing/channels/{channel}/sync-taxonomy', [PublishingController::class, 'syncTaxonomy'])
            ->middleware('can:channel:manage');

        // ─── Dashboard 活动 ───
        Route::get('dashboard/activity', [AnalyticsController::class, 'activity'])
            ->middleware('can:project:view', 'scope:analytics:read');

        Route::get('dashboard/usage', [AnalyticsController::class, 'usage'])
            ->middleware('can:project:view', 'scope:analytics:read');

        // ─── 订阅管理 ───











        // ─── 自建财务面（B 批 · 一本账 GEO 独立操作面）───









        // ─── 任务自动化编排（阶段 1.4）───
        Route::prefix('tasks')->group(function () {
            Route::get('/', [TaskController::class, 'index'])
                ->middleware('can:task:manage', 'scope:tasks:read');
            Route::post('/', [TaskController::class, 'store'])
                ->middleware('can:task:manage', 'scope:tasks:write');
            Route::get('runs', [TaskController::class, 'runsCollection'])
                ->middleware('can:task:manage', 'scope:tasks:read');
            Route::get('{task}', [TaskController::class, 'show'])
                ->middleware('can:task:manage', 'scope:tasks:read');
            Route::patch('{task}', [TaskController::class, 'update'])
                ->middleware('can:task:manage', 'scope:tasks:write');
            Route::delete('{task}', [TaskController::class, 'destroy'])
                ->middleware('can:task:manage', 'scope:tasks:write');
            Route::post('{task}/run', [TaskController::class, 'runNow'])
                ->middleware('can:task:manage', 'scope:tasks:write');
            Route::post('{task}/pause', [TaskController::class, 'pause'])
                ->middleware('can:task:manage', 'scope:tasks:write');
            Route::post('{task}/resume', [TaskController::class, 'resume'])
                ->middleware('can:task:manage', 'scope:tasks:write');
            Route::get('{task}/runs', [TaskController::class, 'runs'])
                ->middleware('can:task:manage', 'scope:tasks:read');
        });

        // ─── 企业知识库 revision 流（阶段 1.5）───
        Route::prefix('enterprise-knowledge')->group(function () {
            Route::get('/', [EnterpriseKnowledgeController::class, 'index'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:read');
            Route::post('/', [EnterpriseKnowledgeController::class, 'store'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:write');
            Route::get('{projectId}', [EnterpriseKnowledgeController::class, 'show'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:read');
            Route::patch('{projectId}', [EnterpriseKnowledgeController::class, 'update'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:write');
            Route::delete('{projectId}', [EnterpriseKnowledgeController::class, 'destroy'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:write');
            Route::post('{projectId}/sources', [EnterpriseKnowledgeController::class, 'addSource'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:write');
            Route::post('{projectId}/generate', [EnterpriseKnowledgeController::class, 'generate'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:write');
            Route::post('{projectId}/autosave', [EnterpriseKnowledgeController::class, 'autosave'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:write');
            Route::post('{projectId}/validate', [EnterpriseKnowledgeController::class, 'validateDraft'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:write');
            Route::post('{projectId}/restore/{revisionId}', [EnterpriseKnowledgeController::class, 'restoreRevision'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:write');
            Route::post('{projectId}/publish', [EnterpriseKnowledgeController::class, 'publish'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:write');
            Route::get('{projectId}/revisions', [EnterpriseKnowledgeController::class, 'revisions'])
                ->middleware('can:knowledge:manage', 'scope:knowledge:read');
        });

        // ─── 手动发布模块（单 7）───────────────────────────────────────────
        // 总开关：与 web.php 的 /manual-publish 同闸（评审收尾，2026-08-28）。
        // 关掉时不注册 → 请求 404（路由不存在），不用 403——403 等于告诉外面
        // 「这儿有个模块，只是没开」。测试环境经 phpunit.xml 固定 GEO_MANUAL_PUBLISH_ENABLED=true。
        if (config('geo.manual_publish_enabled', false)) {
            Route::get('publishing/browser-extension', [ManualPublicationController::class, 'browserExtension'])
                ->middleware('can:manual_publish:manage');
            Route::post('articles/{articleId}/manual-publications', [ManualPublicationController::class, 'fromArticle'])
                ->whereNumber('articleId')->middleware('can:manual_publish:manage');
            // 全部端点受 can:manual_publish:manage 门控（admin + editor）；assign 区分在
            // 服务层（非 admin 不能指派给别人）。记录级 view/update/transition/reopen 在服务层显式校验。
            // 人设 / 账号的「写」再收一道 can:manual_publish:settings（仅 admin），见下方注释。
            Route::prefix('manual-publications')->middleware('can:manual_publish:manage')->group(function () {
                Route::get('/', [ManualPublicationController::class, 'index']);
                Route::post('/', [ManualPublicationController::class, 'store']);
                Route::get('{id}', [ManualPublicationController::class, 'show']);
                Route::put('{id}', [ManualPublicationController::class, 'update']);
                Route::post('{id}/transition', [ManualPublicationController::class, 'transition']);
                Route::post('{id}/draft-preparation', [ManualPublicationController::class, 'preparePlatformDraft']);
                Route::post('{id}/platform-draft', [ManualPublicationController::class, 'confirmPlatformDraft']);
                Route::get('{id}/wechat-draft', [\App\Http\Controllers\Api\V1\WeChatDraftController::class, 'status'])->whereNumber('id');
                Route::post('{id}/wechat-draft', [\App\Http\Controllers\Api\V1\WeChatDraftController::class, 'send'])->whereNumber('id')->middleware('throttle:6,1');
                Route::post('{id}/wechat-draft/retry', [\App\Http\Controllers\Api\V1\WeChatDraftController::class, 'retry'])->whereNumber('id')->middleware('throttle:6,1');
                Route::post('{id}/wechat-draft/check', [\App\Http\Controllers\Api\V1\WeChatDraftController::class, 'reconcile'])->whereNumber('id')->middleware('throttle:6,1');
                if (config('geo.zhihu_draft_sync_enabled', true)) {
                    Route::post('{id}/draft-payload', [ManualPublicationController::class, 'draftPayload']);
                    Route::post('{id}/zhihu-draft', [ManualPublicationController::class, 'recordZhihuDraft']);
                }
                Route::get('{id}/duplicates', [ManualPublicationController::class, 'duplicates']);

                // 人设 / 账号维护（设置）
                // 注：评审发现 destroyPersona/destroyAccount（DELETE）是孤儿端点——前端启停已改 PUT
                // 切 is_active，全仓零调用、测试零引用、上游无此路由，随路由一并移除（评审项 5）。
                //
                // 读 / 写分开（2026-08-28 拍板）：
                //   读留在 manual_publish:manage —— 建单表单要靠这两个列表填人设 / 账号下拉框，
                //   收窄成 admin 会让 editor 建不了单。
                //   写收到 manual_publish:settings（仅 admin）—— 人设 / 账号是全租户共用的主数据，
                //   一个人改错影响所有单，与上游把设置页挂在超管下同义。
                Route::get('{id}/weibo-draft', [\App\Http\Controllers\Api\V1\WeiboDraftController::class, 'show'])->whereNumber('id');
                Route::post('{id}/weibo-draft/start', [\App\Http\Controllers\Api\V1\WeiboDraftController::class, 'start'])->whereNumber('id')->middleware('throttle:10,1');
                Route::post('{id}/weibo-draft/progress', [\App\Http\Controllers\Api\V1\WeiboDraftController::class, 'progress'])->whereNumber('id')->middleware('throttle:60,1');
                Route::get('settings/personas', [ManualPublicationSettingsController::class, 'listPersonas']);
                Route::get('settings/accounts', [ManualPublicationSettingsController::class, 'listAccounts']);

                Route::middleware('can:manual_publish:settings')->group(function () {
                    Route::post('settings/personas', [ManualPublicationSettingsController::class, 'storePersona']);
                    Route::put('settings/personas/{id}', [ManualPublicationSettingsController::class, 'updatePersona']);

                    Route::post('settings/accounts', [ManualPublicationSettingsController::class, 'storeAccount']);
                    Route::put('settings/accounts/{id}', [ManualPublicationSettingsController::class, 'updateAccount']);
                    Route::get('settings/accounts/{id}/wechat-connection', [\App\Http\Controllers\Api\V1\WeChatDraftController::class, 'connection'])->whereNumber('id');
                    Route::post('settings/accounts/{id}/wechat-connection', [\App\Http\Controllers\Api\V1\WeChatDraftController::class, 'connect'])->whereNumber('id')->middleware('throttle:6,1');
                });
            });
        }

        // ─── 线索表单模块（单 8）───────────────────────────────────────────
        // 总开关：与 web.php 的 /forms/{tenantSlug}/{slug} 同闸（评审收尾，2026-08-30）。
        // 关掉时不注册 → 请求 404（路由不存在），不用 403——403 等于告诉外面
        // 「这儿有个模块，只是没开」。口径与单 7 手动发布一致（见本文件上方同款 if）。
        //
        // 为什么 API 也必须同闸：开关是本模块的第一道回滚（执行书 §七 红线 8）。
        // 只闸公开页、不闸 API，回滚后 admin/editor 拿 token 仍能建表单，
        // 拿到的公开链接却是 404 —— 那不是回滚，是把模块留在一个更坏的半开状态。
        //
        // 测试环境经 phpunit.xml 固定 GEO_LEAD_FORM_ENABLED=true（管理台与 API 用例需要路由在）。
        if (config('geo.lead_form_enabled', false)) {
            // 全部端点挂 can:lead:manage（admin + editor）。tenant 中间件已设上下文，
            // BelongsToTenant 自动填 tenant_id；查询走 TenantScope（执行书 §五-4）。
            // idempotency 沿用 v1 组（访客不带幂等键的是公开页，不在这里）。
            Route::prefix('lead-forms')->middleware('can:lead:manage')->group(function () {
                Route::get('/', [App\Http\Controllers\Api\V1\LeadFormController::class, 'index']);
                Route::post('/', [App\Http\Controllers\Api\V1\LeadFormController::class, 'store']);
                Route::get('{id}', [App\Http\Controllers\Api\V1\LeadFormController::class, 'show'])->whereNumber('id');
                Route::put('{id}', [App\Http\Controllers\Api\V1\LeadFormController::class, 'update'])->whereNumber('id');
                Route::delete('{id}', [App\Http\Controllers\Api\V1\LeadFormController::class, 'destroy'])->whereNumber('id');
                Route::post('{id}/toggle-status', [App\Http\Controllers\Api\V1\LeadFormController::class, 'toggleStatus'])->whereNumber('id');
            });

            Route::prefix('leads')->middleware('can:lead:manage')->group(function () {
                // export 必须排在 {id} 之前，否则被 {id} 路由捕获。
                Route::get('export', [App\Http\Controllers\Api\V1\LeadController::class, 'export']);
                Route::get('/', [App\Http\Controllers\Api\V1\LeadController::class, 'index']);
                Route::get('{id}', [App\Http\Controllers\Api\V1\LeadController::class, 'show'])->whereNumber('id');
                Route::put('{id}', [App\Http\Controllers\Api\V1\LeadController::class, 'update'])->whereNumber('id');
            });

            Route::get('lead-analytics', [App\Http\Controllers\Api\V1\LeadAnalyticsController::class, '__invoke'])
                ->middleware('can:lead:manage');
        }
    });

// ─── 平台超管运营面板（P1-4，跨租户）───
// 协调点 A 修复：原仅 auth:sanctum，任何登录用户可读全平台商业数据。
// 现并入 can:platform:operations 权限门 + log-admin-activity 审计中间件（与上方 :45 分组一致）。
Route::middleware(['auth:sanctum', 'can:platform:operations', 'log-admin-activity'])->prefix('v1/platform')->group(function () {
    Route::get('overview', [PlatformAdminController::class, 'overview']);
    Route::get('tenants', [PlatformAdminController::class, 'tenants']);

    Route::get('health', [PlatformAdminController::class, 'health']);
    // 单 9 · 队列心跳看板（权限/审计沿用本组；面板开关 queue_health_panel_enabled 默认 false → 关闭时 404）
    Route::get('queue-health', [PlatformQueueHealthController::class, 'index']);

    // 协调点 B — 跨租户订单明细 + 风险收入聚合（读）


    // 协调点 C — 付款异常处理（写，自动审计）


    // 协调点 D — 暂停 / 恢复租户（写，自动审计）
    Route::post('tenants/{tenant}/pause', [PlatformAdminController::class, 'pauseTenant'])
        ->name('platform.tenants.pause');
    Route::post('tenants/{tenant}/recover', [PlatformAdminController::class, 'recoverTenant'])
        ->name('platform.tenants.recover');

    // 协调点 E — 发票队列 + 标记开票（读 + 写）

});

// ─── 平台敏感词基线库运营面（执行书 T8）───
// 挂进这个组是有意的：组上已有 can:platform:operations（租户侧碰不到平台词）
// 与 log-admin-activity（谁改了哪条词由审计中间件免费提供，控制器不再自己写一份，
// 写两份只会让两份对不上）。
//
// ⚠️ 路由顺序：dry-run 两条**必须**排在 {id} 之前。
// 反过来 'dry-run' 会被 {id} 当成字符串 id 捕获。whereNumber('id') 是第二道保险，两道都留。
Route::middleware(['auth:sanctum', 'can:platform:operations', 'log-admin-activity'])->prefix('v1/platform')->group(function () {
    Route::post('sensitive-words/dry-run', [PlatformSensitiveWordController::class, 'dryRun'])
        ->name('platform.sensitive-words.dry-run');
    Route::get('sensitive-words/dry-run/{id}', [PlatformSensitiveWordController::class, 'dryRunShow'])
        ->whereNumber('id')
        ->name('platform.sensitive-words.dry-run.show');

    Route::get('sensitive-words', [PlatformSensitiveWordController::class, 'index'])
        ->name('platform.sensitive-words.index');
    Route::post('sensitive-words', [PlatformSensitiveWordController::class, 'store'])
        ->name('platform.sensitive-words.store');
    Route::patch('sensitive-words/{id}', [PlatformSensitiveWordController::class, 'update'])
        ->whereNumber('id')
        ->name('platform.sensitive-words.update');
    // 语义是「停用」，不是物理删除——理由见控制器 destroy() 的注释。
    Route::delete('sensitive-words/{id}', [PlatformSensitiveWordController::class, 'destroy'])
        ->whereNumber('id')
        ->name('platform.sensitive-words.disable');

    // 命中日志（T7 那张表）：summary 必须排在裸路径之前，否则被前缀规则吞掉。
    Route::get('sensitive-word-hits/summary', [PlatformSensitiveWordController::class, 'hitsSummary'])
        ->name('platform.sensitive-word-hits.summary');
    Route::get('sensitive-word-hits', [PlatformSensitiveWordController::class, 'hits'])
        ->name('platform.sensitive-word-hits.index');
});
