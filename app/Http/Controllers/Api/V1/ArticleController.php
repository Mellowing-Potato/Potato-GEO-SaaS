<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\ArticleReview;
use App\Models\ArticleVersion;
use App\Models\Author;
use App\Models\Project;
use App\Services\Content\ArticleMarkdownRenderer;
use App\Services\Content\ArticleWordExporter;
use App\Services\Content\ArticleTrashService;
use App\Services\Content\EditorAssetService;
use App\Services\Content\WeChatArticleExporter;
use App\Services\Distribution\ExternalTakedownService;
use App\Support\OperationalTextRedactor;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use App\Support\Concerns\RecordsSensitiveAction;
class ArticleController extends Controller
{
    use RecordsSensitiveAction;

    public function __construct(
        private readonly EditorAssetService $editorAssets,
        private readonly WeChatArticleExporter $weChatExporter,
        private readonly ArticleWordExporter $wordExporter,
        private readonly ArticleTrashService $articleTrash,
        private readonly ExternalTakedownService $takedown,
        private readonly ArticleMarkdownRenderer $markdownRenderer,
    ) {}

    /**
     * GET /api/v1/articles
     * List all articles for the current tenant, with optional filters.
     * Query params: project_id, status, content_type, search
     * Returns paginated list (20/page), newest first.
     * Include project name and author name via eager loading.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Article::with(['project:id,name', 'author:id,name', 'reviewer:id,name', 'category:id,name', 'bylineAuthor:id,name', 'skillExecution:id,skill_id,skill_name,status'])
            ->whereHas('project')
            ->orderByDesc('created_at');

        // 回收站要能打开：显式请求 status=recycled 时不能再套这条排除，
        // 否则最终查询等价于 status != 'recycled' AND status = 'recycled'，恒为空。
        // ⚠️ 这不是「顺手放宽」——「移入回收站」按钮一直开着，而 restore 端点
        //    因为拿不到列表所以从来调不到，等于客户点一下文章就永久消失。
        if ($request->query('status') !== Article::STATUS_RECYCLED) {
            $query->where('status', '!=', Article::STATUS_RECYCLED);
        }

        if ($projectId = $request->query('project_id')) {
            $project = $this->projectForCurrentTenant((int) $projectId);
            $query->forProject($project->id);
        }

        if ($status = $request->query('status')) {
            $query->ofStatus($status);
        }

        if ($type = $request->query('content_type')) {
            $query->ofType($type);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', (int) $categoryId);
        }

        if ($sourceType = $request->query('source_type')) {
            if ($sourceType !== 'all') {
                $query->where('source_type', $sourceType);
            }
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('summary', 'like', "%{$search}%")
                    ->orWhere('body_markdown', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        $articles = $query->paginate($perPage);
        $articles->getCollection()->transform(fn (Article $article) => $this->articlePayload($article));

        return response()->json($articles);
    }

    /**
     * GET /api/v1/articles/{article}
     * Show a single article with full body content.
     */
    public function show(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);
        $article->load(['project:id,name', 'author:id,name', 'reviewer:id,name', 'skillExecution:id,skill_id,skill_name,status']);

        return response()->json($this->articlePayload($article));
    }

    public function preview(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        return response()->json([
            'article_id' => $article->id,
            'version' => $article->version,
            'html' => $this->markdownRenderer->renderMarkdown((string) $article->body_markdown),
        ]);
    }

    /**
     * POST /api/v1/articles
     * Create a new article (manually or from skill output).
     * Required: project_id, title
     * Optional: body_markdown, summary, content_type, status, source_skill, quality_score, word_count, metadata
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|integer',
            'title' => 'required|string|max:500',
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'body_markdown' => 'nullable|string',
            'summary' => 'nullable|string|max:2000',
            'content_type' => 'nullable|string|in:explainer,comparison,ranking,general',
            'category' => 'nullable|string|max:120',
            'status' => 'nullable|string|in:draft,editing',
            'source_type' => 'nullable|string|in:manual,draft,geo_analysis,skill_execution,url_import',
            'source_skill' => 'nullable|string|max:100',
            'seo_title' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:500',
            'canonical_url' => 'nullable|url|max:2000',
            'og_title' => 'nullable|string|max:255',
            'og_description' => 'nullable|string|max:1000',
            'og_image' => 'nullable|url|max:1000',
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($q) => $q->where('tenant_id', TenantContext::id()))],
            'byline_author_id' => ['nullable', 'integer', Rule::exists('authors', 'id')->where(fn ($q) => $q->where('tenant_id', TenantContext::id()))],
            'quality_score' => 'nullable|integer|min:0|max:100',
            'word_count' => 'nullable|integer|min:0',
            'metadata' => 'nullable|array',
        ]);

        $validated = $this->normalizeArticleInput($validated);
        $project = $this->projectForCurrentTenant((int) $validated['project_id']);
        $validated['project_id'] = $project->id;
        $validated['author_id'] = $request->user()->id;

        // Auto-calculate word_count from body if not provided
        if (! isset($validated['word_count']) && ! empty($validated['body_markdown'])) {
            $validated['word_count'] = mb_strlen(strip_tags((string) $validated['body_markdown']));
        }

        // 内容合规扫描已上移到 Article::booted() 的 saving 钩子（执行书 T5）。
        // 这里曾经手写一遍 checkAny()，但全仓有 5 个文章写入点，逐处手写必然漏；
        // 钩子是唯一收口点，顺带覆盖平台基线词（T4 的合并读）。别在这里再写一份。
        $article = Article::create($validated);

        // 初始版本快照（version 1），便于回退到最初状态
        $article->snapshotVersion($request->user()->id);

        $article->load(['project:id,name', 'author:id,name', 'reviewer:id,name', 'category:id,name', 'bylineAuthor:id,name', 'skillExecution:id,skill_id,skill_name,status']);

        return response()->json($this->articlePayload($article), 201);
    }

    /**
     * PATCH /api/v1/articles/{article}
     * Update an existing article. Supports partial updates.
     * If body changes, auto-increment version and recalculate word_count.
     */
    public function update(Request $request, Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:500',
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],
            'body_markdown' => 'sometimes|nullable|string',
            'summary' => 'sometimes|nullable|string|max:2000',
            'content_type' => 'sometimes|string|in:explainer,comparison,ranking,general',
            'category' => 'sometimes|nullable|string|max:120',
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($q) => $q->where('tenant_id', TenantContext::id()))],
            'byline_author_id' => ['sometimes', 'nullable', 'integer', Rule::exists('authors', 'id')->where(fn ($q) => $q->where('tenant_id', TenantContext::id()))],
            'status' => 'sometimes|string|in:draft,editing',
            'seo_title' => 'sometimes|nullable|string|max:255',
            'seo_description' => 'sometimes|nullable|string|max:500',
            'canonical_url' => 'sometimes|nullable|url|max:2000',
            'og_title' => 'sometimes|nullable|string|max:255',
            'og_description' => 'sometimes|nullable|string|max:1000',
            'og_image' => 'sometimes|nullable|url|max:1000',
        ]);

        $validated = $this->normalizeArticleInput($validated);

        $bodyChanged = array_key_exists('body_markdown', $validated)
            && $validated['body_markdown'] !== $article->body_markdown;
        $contentChanged = $bodyChanged
            || (array_key_exists('title', $validated) && $validated['title'] !== $article->title)
            || (array_key_exists('summary', $validated) && $validated['summary'] !== $article->summary);

        // Auto version increment when body changes (atomic to prevent race)
        if ($bodyChanged) {
            $validated['word_count'] = mb_strlen(strip_tags((string) $validated['body_markdown']));
        }

        // 原子递增版本号（避免并发更新丢失）
        if ($contentChanged) {
            Article::where('id', $article->id)->increment('version');
            $article->refresh();
        }

        if ($contentChanged && in_array($article->status, ['pending', 'approved', 'published'], true)) {
            $validated['status'] = 'draft';
            $validated['submitted_for_review_at'] = null;
            $validated['reviewed_by'] = null;
            $validated['reviewed_at'] = null;
            $validated['review_notes'] = null;
        }

        // 排除 version 字段，由原子递增处理（避免 refresh→update 竞态）
        unset($validated['version']);

        // 同 store()：重新标记由 Article 的 saving 钩子负责（执行书 T5）。
        // 顺带修掉一个旧行为：以前每次 PATCH 都重扫，只改 status 也扫一遍全词表；
        // 现在钩子里有 isDirty(['title','summary','body_markdown']) 守卫，不再白跑。
        $article->update($validated);
        $article->refresh();

        // 审核所依据的标题、摘要和正文每次变更都留下版本快照。
        if ($contentChanged) {
            $article->snapshotVersion($request->user()->id);
        }

        $article->load(['project:id,name', 'author:id,name', 'reviewer:id,name', 'category:id,name', 'bylineAuthor:id,name', 'skillExecution:id,skill_id,skill_name,status']);

        return response()->json($this->articlePayload($article));
    }

    public function submitForReview(Request $request, Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);
        if ($article->status === 'pending') return response()->json($this->articlePayload($article));
        abort_unless(in_array($article->status, ['draft', 'editing'], true), 409, '当前文章状态不能提交审核。');

        $validated = $request->validate([
            'review_notes' => 'nullable|string|max:2000',
        ]);

        $article->forceFill([
            'status' => 'pending',
            'submitted_for_review_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => trim((string) ($validated['review_notes'] ?? '')) ?: null,
        ])->save();

        $article->recordReview($request->user()->id, ArticleReview::STATUS_SUBMITTED, $article->review_notes);

        $article->load(['project:id,name', 'author:id,name', 'reviewer:id,name', 'skillExecution:id,skill_id,skill_name,status']);

        return response()->json($this->articlePayload($article));
    }

    public function approve(Request $request, Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);
        if ($article->status === 'approved') return response()->json($this->articlePayload($article));
        abort_unless($article->status === 'pending', 409, '文章需要先提交审核。');

        $validated = $request->validate([
            'review_notes' => 'nullable|string|max:2000',
        ]);

        $article->forceFill([
            'status' => 'approved',
            'submitted_for_review_at' => $article->submitted_for_review_at ?: now(),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => trim((string) ($validated['review_notes'] ?? '')) ?: $article->review_notes,
        ])->save();

        $article->recordReview($request->user()->id, ArticleReview::STATUS_APPROVED, $article->review_notes);

        $article->load(['project:id,name', 'author:id,name', 'reviewer:id,name', 'skillExecution:id,skill_id,skill_name,status']);

        return response()->json($this->articlePayload($article));
    }

    public function requestChanges(Request $request, Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);
        abort_unless($article->status === 'pending', 409, '只有待审核文章可以退回。');

        $validated = $request->validate([
            'review_notes' => 'required|string|max:2000',
        ]);

        $article->forceFill([
            'status' => 'editing',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => trim((string) $validated['review_notes']),
        ])->save();

        $article->recordReview($request->user()->id, ArticleReview::STATUS_CHANGES_REQUESTED, $article->review_notes);

        $article->load(['project:id,name', 'author:id,name', 'reviewer:id,name', 'skillExecution:id,skill_id,skill_name,status']);

        return response()->json($this->articlePayload($article));
    }

    /**
     * DELETE /api/v1/articles/{article}
     * Delete an article into the global trash for the configured retention period.
     */
    public function destroy(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);
        $takedown = $this->takedown->takeDownForArticle($article);
        // 敏感操作审计（计划 §9.7）：删数据必须能答出"谁在什么时候删了哪一篇"。
        // 详情只放可公开的辨识信息（标题/项目），不放正文。
        $this->auditSensitive('article.deleted', $article, [
            'title' => (string) $article->title,
            'project_id' => $article->project_id,
            'status' => (string) $article->status,
        ]);
        $article->delete();

        return response()->json([
            'message' => sprintf('文章已移入回收站，%d 天内可以原样恢复。', $this->articleTrash->retentionDays())
                .(count($takedown['failed']) > 0 ? sprintf(' 外站还有 %d 篇未撤下，可在回收站重试。', count($takedown['failed'])) : ''),
            'deleted_at' => $article->deleted_at?->toISOString(),
            'purge_at' => $this->articleTrash->purgeAt($article)->toISOString(),
            'takedown' => $takedown,
        ]);
    }

    /** POST /api/v1/articles/{article}/undelete — restore a truly deleted article to its prior state. */
    public function undelete(Article $article): JsonResponse
    {
        if ((int) $article->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
        if (! $article->project()->exists()) {
            abort(404);
        }
        if (! $article->trashed()) {
            return response()->json(['message' => '这篇文章不在回收站里。'], 409);
        }
        if ($this->articleTrash->isExpired($article)) {
            return response()->json(['message' => sprintf('这篇文章已超过 %d 天保留期，无法恢复。', $this->articleTrash->retentionDays())], 410);
        }

        try {
            $restored = $this->articleTrash->restore($article);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 410);
        }

        $this->auditSensitive('article.restored', $restored, [
            'title' => (string) $restored->title,
            'project_id' => (int) $restored->project_id,
            'status' => (string) $restored->status,
            'restore_type' => 'soft_delete',
        ]);

        return response()->json([
            'restored' => true,
            'message' => '文章已原样恢复；已撤回的外站内容需要重新发布。',
            'data' => $this->articlePayload($restored->load(['project:id,name', 'author:id,name'])),
        ]);
    }

    /** Retry external takedown for a deleted article. */
    public function takedown(Article $article): JsonResponse
    {
        if ((int) $article->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
        if (! $article->project()->exists()) {
            abort(404);
        }
        if (! $article->trashed()) {
            return response()->json(['message' => '文章不在回收站里。'], 409);
        }

        $result = $this->takedown->takeDownForArticle($article);

        return response()->json([
            'message' => sprintf('撤稿完成：成功 %d 条，仍失败 %d 条。', $result['succeeded'], count($result['failed'])),
            'data' => $result,
        ]);
    }

    /**
     * GET /api/v1/projects/{project}/articles
     * List articles for a specific project.
     */
    public function projectArticles(Request $request, Project $project): JsonResponse
    {
        $this->ensureCurrentTenantProject($project);

        $articles = Article::with(['author:id,name', 'reviewer:id,name', 'category:id,name', 'bylineAuthor:id,name', 'skillExecution:id,skill_id,skill_name,status'])
            ->forProject($project->id)
            ->orderByDesc('created_at');

        // 同 index()：显式请求 status=recycled 时跳过这条无条件排除，否则回收站恒为空。
        // 前端内容库打的是本端点，所以「回收站能不能打开」实际取决于这一处。
        if ($request->query('status') !== Article::STATUS_RECYCLED) {
            $articles->where('status', '!=', Article::STATUS_RECYCLED);
        }

        if ($sourceType = $request->query('source_type')) {
            if ($sourceType !== 'all') {
                $articles->where('source_type', $sourceType);
            }
        }

        if ($status = $request->query('status')) {
            $articles->ofStatus($status);
        }

        if ($type = $request->query('content_type')) {
            $articles->ofType($type);
        }

        if ($category = $request->query('category')) {
            $articles->where('category', $category);
        }

        if ($categoryId = $request->query('category_id')) {
            $articles->where('category_id', (int) $categoryId);
        }

        if ($search = $request->query('search')) {
            $articles->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('summary', 'like', "%{$search}%")
                    ->orWhere('body_markdown', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $articles = $articles->paginate($perPage);
        $articles->getCollection()->transform(fn (Article $article) => $this->articlePayload($article));

        return response()->json($articles);
    }

    public function download(Article $article): Response
    {
        $this->ensureCurrentTenantArticle($article);

        $filename = sprintf(
            'geo-report-%d-%d.md',
            $article->project_id,
            $article->id,
        );

        return response(OperationalTextRedactor::redact($article->body_markdown ?? '', truncate: false) ?? '', 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function exportDocx(Article $article): Response
    {
        $this->ensureCurrentTenantArticle($article);

        $title = OperationalTextRedactor::redact($article->title ?? '', truncate: false) ?? '';
        $body = OperationalTextRedactor::redact($article->body_markdown ?? '', truncate: false) ?? '';
        $summary = OperationalTextRedactor::redact($article->summary ?? '', truncate: false) ?? '';

        return response($this->wordExporter->toDocx($title, $body, $summary), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="geo-article-'.$article->id.'.docx"',
        ]);
    }

    /**
     * GET /api/v1/articles/{article}/export/wechat
     * 将文章 Markdown 正文转换为可粘贴进微信公众号的富文本 HTML（阶段 4 缺口补齐：复刻 GEOFlow WeChatArticleHtmlExporter）。
     */
    public function exportWechat(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $html = $this->weChatExporter->toHtml($article->body_markdown ?? '');
        $plainText = $this->weChatExporter->toPlainText($html);

        return response()->json([
            'object' => 'wechat_article_export',
            'article_id' => $article->id,
            'html' => $html,
            'plain_text' => $plainText,
        ]);
    }

    /**
     * GET /api/v1/articles/{article}/reviews
     * 审核历史流水（对标 GEOFlow ArticleReview）。
     */
    public function reviews(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $reviews = $article->reviews()
            ->with(['reviewer:id,name'])
            ->get()
            ->map(fn (ArticleReview $review) => [
                'id' => $review->id,
                'review_status' => $review->review_status,
                // 与 articlePayload() 的 review_notes 同口径过一遍脱敏。
                // 本端点 2026-09-03 前从没有前端调过，接出来那天才第一次真的显示给人看；
                // 不过这一道，同一条退回意见会出现「详情页打了码、审核记录没打」的自相矛盾。
                'review_note' => OperationalTextRedactor::redact($review->review_note, truncate: false),
                'reviewer_id' => $review->reviewer_id,
                'reviewer_name' => $review->reviewer?->name,
                'created_at' => $review->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $reviews]);
    }

    /**
     * GET /api/v1/articles/{article}/versions
     * 版本快照列表（轻量版本，支持回退）。
     */
    public function versions(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $versions = $article->versions()
            ->with(['creator:id,name'])
            ->get()
            ->map(fn (ArticleVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'title' => $version->title,
                'summary' => $version->summary,
                'content_type' => $version->content_type,
                'category' => $version->category,
                'category_id' => $version->category_id,
                'created_by_user_id' => $version->created_by_user_id,
                'creator_name' => $version->creator?->name,
                'created_at' => $version->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $versions]);
    }

    /**
     * POST /api/v1/articles/{article}/versions/{versionId}/restore
     * 回退到指定版本快照。
     */
    public function restoreVersion(Request $request, Article $article, int $versionId): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $version = ArticleVersion::query()
            ->where('article_id', $article->id)
            ->where('id', $versionId)
            ->firstOrFail();

        $restored = [
            'title' => $version->title,
            'body_markdown' => $version->body_markdown,
            'summary' => $version->summary,
            'content_type' => $version->content_type,
            'category' => $version->category,
            'category_id' => $version->category_id,
            'seo_title' => $version->seo_title,
            'seo_description' => $version->seo_description,
            'canonical_url' => $version->canonical_url,
            'og_title' => $version->og_title,
            'og_description' => $version->og_description,
            'og_image' => $version->og_image,
        ];

        if (in_array($article->status, ['pending', 'approved', 'published'], true)) {
            $restored = array_merge($restored, [
                'status' => 'draft', 'submitted_for_review_at' => null,
                'reviewed_by' => null, 'reviewed_at' => null, 'review_notes' => null,
            ]);
        }

        Article::where('id', $article->id)->increment('version');
        $article->refresh();
        $article->update($restored);
        $article->refresh();

        // 回退动作本身也记一条版本快照，便于再回退
        $article->snapshotVersion($request->user()->id);

        $article->load(['project:id,name', 'author:id,name', 'reviewer:id,name', 'category:id,name', 'bylineAuthor:id,name', 'skillExecution:id,skill_id,skill_name,status']);

        return response()->json($this->articlePayload($article));
    }

    /**
     * POST /api/v1/articles/{article}/recycle
     * 废弃文章（审核状态机末端：...→recycle）。
     */
    public function recycle(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $article->forceFill(['status' => Article::STATUS_RECYCLED])->save();
        $article->recordReview($article->author_id, ArticleReview::STATUS_CHANGES_REQUESTED, '已废弃');
        $this->auditSensitive('article.recycled', $article, ['title' => (string) $article->title]);

        $article->load(['project:id,name', 'author:id,name', 'reviewer:id,name', 'category:id,name', 'bylineAuthor:id,name', 'skillExecution:id,skill_id,skill_name,status']);

        return response()->json($this->articlePayload($article));
    }

    /**
     * POST /api/v1/articles/{article}/restore
     * 将已废弃文章转为草稿。
     */
    public function restore(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $article->forceFill(['status' => Article::STATUS_DRAFT])->save();
        $this->auditSensitive('article.restored', $article, ['title' => (string) $article->title]);

        $article->load(['project:id,name', 'author:id,name', 'reviewer:id,name', 'category:id,name', 'bylineAuthor:id,name', 'skillExecution:id,skill_id,skill_name,status']);

        return response()->json($this->articlePayload($article));
    }

    /**
     * GET /api/v1/articles/{article}/images
     * 编辑器已插入的素材库图片列表（对标 GEOFlow ArticleImage）。
     */
    public function images(Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $images = $this->editorAssets->list($article)
            ->map(fn (ArticleImage $link) => [
                'id' => $link->id,
                'image_library_id' => $link->image_library_id,
                'position' => $link->position,
                'url' => $link->image?->url,
                'title' => $link->image?->title,
                'alt' => $link->image?->alt,
            ]);

        return response()->json(['data' => $images]);
    }

    /**
     * POST /api/v1/articles/{article}/images
     * 从素材库插入一张图片到编辑器（幂等：重复插入不报错）。
     */
    public function attachImage(Request $request, Article $article): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $validated = $request->validate([
            'image_library_id' => ['required', 'integer', Rule::exists('image_libraries', 'id')->where(fn ($q) => $q->where('tenant_id', TenantContext::id()))],
            'position' => 'nullable|integer|min:0',
        ]);

        $link = $this->editorAssets->attach($article, $validated['image_library_id'], $validated['position'] ?? 0);
        $link->load(['image:id,url,title,alt']);

        return response()->json([
            'id' => $link->id,
            'image_library_id' => $link->image_library_id,
            'position' => $link->position,
            'url' => $link->image?->url,
            'title' => $link->image?->title,
            'alt' => $link->image?->alt,
        ], 201);
    }

    /**
     * DELETE /api/v1/articles/{article}/images/{imageId}
     * 从编辑器移除一张素材库图片引用。
     */
    public function detachImage(Article $article, int $imageId): JsonResponse
    {
        $this->ensureCurrentTenantArticle($article);

        $this->editorAssets->detach($article, $imageId);

        return response()->json(['message' => '图片引用已移除']);
    }

    private function projectForCurrentTenant(int $projectId): Project
    {
        $project = Project::query()->whereKey($projectId)->first();

        if (! $project || (int) $project->tenant_id !== (int) TenantContext::id()) {
            throw ValidationException::withMessages([
                'project_id' => 'The selected project_id is invalid.',
            ]);
        }

        return $project;
    }

    private function ensureCurrentTenantProject(Project $project): void
    {
        if ((int) $project->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }
    }

    private function ensureCurrentTenantArticle(Article $article): void
    {
        if ((int) $article->tenant_id !== (int) TenantContext::id()) {
            abort(404);
        }

        if (! $article->project()->exists()) {
            abort(404);
        }
    }

    private function articlePayload(Article $article): array
    {
        $metadata = OperationalTextRedactor::redactOperationalFields($article->metadata ?? []);

        return [
            'id' => $article->id,
            'project_id' => $article->project_id,
            'project' => $article->project,
            'author_id' => $article->author_id,
            'author' => $article->author,
            'title' => $article->title,
            'slug' => $article->slug,
            'summary' => $article->summary,
            'body_markdown' => OperationalTextRedactor::redact($article->body_markdown, truncate: false),
            'content_type' => $article->content_type,
            'category' => $article->category,
            'category_id' => $article->category_id,
            'category_detail' => $article->category_id ? [
                'id' => $article->category?->id,
                'name' => $article->category?->name,
                'slug' => $article->category?->slug,
            ] : null,
            'byline_author_id' => $article->byline_author_id,
            'byline_author' => $article->bylineAuthor ? [
                'id' => $article->bylineAuthor->id,
                'name' => $article->bylineAuthor->name,
                'avatar_url' => $article->bylineAuthor->avatar_url,
            ] : null,
            'status' => $article->status,
            'source_type' => $article->source_type,
            'source_label' => $this->sourceLabel($article->source_type),
            'source_skill' => $article->source_skill,
            'skill_execution_id' => $article->skill_execution_id,
            'source_execution' => $article->skillExecution,
            'structured' => $metadata['structured'] ?? null,
            'quality_score' => $article->quality_score,
            'word_count' => $article->word_count,
            'version' => $article->version,
            'seo' => [
                'title' => $article->seo_title,
                'description' => $article->seo_description,
                'canonical_url' => $article->canonical_url,
                'og_title' => $article->og_title,
                'og_description' => $article->og_description,
                'og_image' => $article->og_image,
            ],
            'reviewed_by' => $article->reviewed_by,
            'reviewer' => $article->reviewer,
            'submitted_for_review_at' => $article->submitted_for_review_at?->toISOString(),
            'reviewed_at' => $article->reviewed_at?->toISOString(),
            'review_notes' => OperationalTextRedactor::redact($article->review_notes, truncate: false),
            'flagged_sensitive' => (bool) $article->flagged_sensitive,
            // T10：出站被合规闸门拦下时的作者可见落点。值只可能是那一句固定文案
            // （ArticleRiskScanner::PLATFORM_BLOCK_NOTICE）或 null，绝不含命中的平台词。
            'distribution_blocked_reason' => $article->distribution_blocked_reason,
            'metadata' => $metadata,
            'download_url' => "/api/v1/articles/{$article->id}/download",
            'created_at' => $article->created_at?->toISOString(),
            'updated_at' => $article->updated_at?->toISOString(),
            'published_at' => $article->published_at?->toISOString(),
        ];
    }

    private function sourceLabel(?string $sourceType): string
    {
        return match ($sourceType) {
            'geo_analysis' => '综合诊断',
            'skill_execution' => '技能产物',
            'draft' => '草稿',
            default => '手动内容',
        };
    }

    private function normalizeArticleInput(array $input): array
    {
        foreach (['slug', 'category', 'seo_title', 'seo_description', 'canonical_url'] as $key) {
            if (array_key_exists($key, $input) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]) ?: null;
            }
        }

        if (isset($input['slug']) && is_string($input['slug'])) {
            $input['slug'] = strtolower($input['slug']);
        }

        return $input;
    }
}
