<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DryRunPlatformSensitiveWordsJob;
use App\Models\PlatformSensitiveWord;
use App\Models\PlatformSensitiveWordDryRun;
use App\Models\SensitiveWordHit;
use App\Models\Tenant;
use App\Services\Publishing\ArticleRiskScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 平台敏感词基线库运营面（执行书 T8）。
 *
 * 路由前缀 /api/v1/platform，已在路由组上挂 auth:sanctum + can:platform:operations
 * + log-admin-activity。**审计（谁改了什么）由 log-admin-activity 中间件提供，
 * 本控制器不再自己写一份审计日志**——写两份只会让两份对不上。
 *
 * 与租户侧 SensitiveWordController 的分工（别把两者的接口混用）：
 *   - SensitiveWordController        ：/v1/sensitive-words，租户自己的词，门是 article:edit
 *   - PlatformSensitiveWordController：/v1/platform/sensitive-words，平台合规红线，
 *                                      门是 platform:operations，租户侧一个字读不到
 *
 * ⚠️ 本控制器存在的理由是老板第 6 条：「平台随时能更新词表」。
 * 在此之前改一条词要重新部署镜像，实际做不到「随时」。
 * 因此每个写操作都必须让平台词缓存版本号进位，否则改完最长要等一小时才生效——
 * 那仍然不叫「随时」。版本进位由 PlatformSensitiveWord::booted() 的
 * saved/deleted 钩子承担，本控制器在响应里回显当前版本号，
 * 让运营台与验收测试都能直接看到「这次写操作确实让版本动了」。
 */
class PlatformSensitiveWordController extends Controller
{
    /** GET /v1/platform/sensitive-words —— 列表，可按 category / is_enabled / 关键词筛。 */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:32'],
            'is_enabled' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $limit = (int) ($validated['limit'] ?? 200);
        $offset = (int) ($validated['offset'] ?? 0);

        $query = PlatformSensitiveWord::query();

        if (! empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if ($request->has('is_enabled') && $validated['is_enabled'] !== null) {
            $query->where('is_enabled', (bool) $validated['is_enabled']);
        }

        if (! empty($validated['q'])) {
            $query->where('word', 'like', '%'.$validated['q'].'%');
        }

        $total = (clone $query)->count();

        $words = $query
            // 停用的排后面：运营台首屏要先看到当前真正在拦的词。
            ->orderByDesc('is_enabled')
            ->orderBy('category')
            ->orderBy('word')
            ->offset($offset)
            ->limit($limit)
            ->get(['id', 'word', 'severity', 'category', 'is_enabled', 'suggestion', 'note', 'created_by', 'updated_by', 'created_at', 'updated_at']);

        return response()->json([
            'object' => 'platform_sensitive_word_list',
            'data' => $words->toArray(),
            'total' => $total,
            'enabled_total' => PlatformSensitiveWord::query()->where('is_enabled', true)->count(),
            'limit' => $limit,
            'offset' => $offset,
            'rule_cache_version' => PlatformSensitiveWord::ruleCacheVersion(),
        ]);
    }

    /** POST /v1/platform/sensitive-words —— 新增，支持批量（换行 / 逗号 / 顿号分隔）。 */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'word' => ['nullable', 'string', 'max:255'],
            'words' => ['nullable', 'string'],
            'severity' => ['nullable', 'string', 'in:'.implode(',', PlatformSensitiveWord::SEVERITIES)],
            'category' => ['nullable', 'string', 'in:'.implode(',', PlatformSensitiveWord::CATEGORIES)],
            'suggestion' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $candidates = $this->splitCandidates(
            (string) ($validated['word'] ?? ''),
            (string) ($validated['words'] ?? ''),
        );

        if ($candidates === []) {
            return response()->json(['message' => '请提供至少一个平台敏感词。'], 422);
        }

        // 平台基线词默认 blocked：这张表存在的意义就是「命中即拦」。
        // 默认成 warning 会让运营以为加上了红线，其实只是加了个提示，
        // 而漏拦一条政治词是合规事故。
        $severity = (string) ($validated['severity'] ?? PlatformSensitiveWord::SEVERITY_BLOCKED);
        $category = (string) ($validated['category'] ?? PlatformSensitiveWord::CATEGORY_POLITICS);
        $suggestion = trim((string) ($validated['suggestion'] ?? '')) ?: null;
        $note = trim((string) ($validated['note'] ?? '')) ?: null;
        $userId = $request->user()?->getKey();

        $existing = PlatformSensitiveWord::query()
            ->whereIn('word', $candidates)
            ->get()
            ->keyBy('word');

        $inserted = [];
        $updated = [];
        $skipped = 0;

        foreach ($candidates as $word) {
            $current = $existing->get($word);

            if ($current instanceof PlatformSensitiveWord) {
                // 已存在的词重复提交 = 运营想改它（升等级 / 重新启用），不能静默跳过。
                // 尤其「重新启用一条停用过的词」唯一入口就是再提交一次同样的词。
                $unchanged = (string) $current->severity === $severity
                    && (string) $current->category === $category
                    && (bool) $current->is_enabled === true
                    && (string) ($current->suggestion ?? '') === (string) ($suggestion ?? '');

                if ($unchanged) {
                    $skipped++;

                    continue;
                }

                $current->forceFill([
                    'severity' => $severity,
                    'category' => $category,
                    'is_enabled' => true,
                    'suggestion' => $suggestion,
                    'note' => $note ?? $current->note,
                    'updated_by' => $userId,
                ])->save();

                $updated[] = $current;

                continue;
            }

            $inserted[] = PlatformSensitiveWord::query()->create([
                'word' => $word,
                'severity' => $severity,
                'category' => $category,
                'is_enabled' => true,
                'suggestion' => $suggestion,
                'note' => $note,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }

        return response()->json([
            'object' => 'platform_sensitive_word_list',
            'data' => array_map(
                static fn (PlatformSensitiveWord $w): array => $w->toArray(),
                array_merge($inserted, $updated),
            ),
            'inserted' => count($inserted),
            'updated' => count($updated),
            'skipped_duplicates' => $skipped,
            // 「改完立刻生效」的可验证证据：调用方可以拿这个号和写之前的比。
            'rule_cache_version' => PlatformSensitiveWord::ruleCacheVersion(),
        ], 201);
    }

    /** PATCH /v1/platform/sensitive-words/{id} —— 改 severity / category / is_enabled / note。 */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'severity' => ['nullable', 'string', 'in:'.implode(',', PlatformSensitiveWord::SEVERITIES)],
            'category' => ['nullable', 'string', 'in:'.implode(',', PlatformSensitiveWord::CATEGORIES)],
            'is_enabled' => ['nullable', 'boolean'],
            'suggestion' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $word = PlatformSensitiveWord::query()->whereKey($id)->firstOrFail();

        // 故意不允许改 word 本身：改字面量等于「停掉一条词 + 加一条新词」，
        // 但审计里只会留下一条 update，事后没法回答「原来那条词什么时候不再生效的」。
        // 要换词就停用旧的、新增新的。
        $payload = [];

        foreach (['severity', 'category', 'suggestion', 'note'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $validated[$field] ?? null;
            }
        }

        if ($request->has('is_enabled')) {
            $payload['is_enabled'] = (bool) $validated['is_enabled'];
        }

        if ($payload === []) {
            return response()->json(['message' => '没有需要修改的字段。'], 422);
        }

        $payload['updated_by'] = $request->user()?->getKey();
        $word->forceFill($payload)->save();

        return response()->json([
            'object' => 'platform_sensitive_word',
            'data' => $word->fresh()?->toArray(),
            'rule_cache_version' => PlatformSensitiveWord::ruleCacheVersion(),
        ]);
    }

    /**
     * DELETE /v1/platform/sensitive-words/{id} —— **实际执行停用，不物理删除**。
     *
     * 运营台上那颗按钮写的是「删除」，这里做的是 is_enabled = false。
     * 不是偷懒：「这条词曾经存在、什么时候下掉的、谁下的」在合规问题上必须答得出来，
     * 物理删除之后这些全没了，而合规质询往往发生在下线几个月之后。
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $word = PlatformSensitiveWord::query()->whereKey($id)->firstOrFail();
        $alreadyDisabled = ! (bool) $word->is_enabled;

        $word->forceFill([
            'is_enabled' => false,
            'updated_by' => $request->user()?->getKey(),
        ])->save();

        return response()->json([
            'object' => 'platform_sensitive_word',
            // 明确回「停用」而不是「已删除」：运营需要知道这条词还在库里、可以再启用。
            'message' => $alreadyDisabled ? '该词此前已停用。' : '已停用该平台敏感词（记录保留，可随时重新启用）。',
            'soft_disabled' => true,
            'data' => $word->fresh()?->toArray(),
            'rule_cache_version' => PlatformSensitiveWord::ruleCacheVersion(),
        ]);
    }

    /**
     * POST /v1/platform/sensitive-words/dry-run —— 加词前试算（本批的安全阀）。
     *
     * 返回 202 + 试算记录 id，实际扫描仅针对当前账号工作区，在队列里运行。
     * 前端用 GET .../dry-run/{id} 轮询结果。
     */
    public function dryRun(Request $request): JsonResponse
    {
        $tenantId = $this->workspaceId($request);
        $validated = $request->validate([
            'word' => ['nullable', 'string', 'max:255'],
            'words' => ['nullable', 'string'],
        ]);

        $candidates = $this->splitCandidates(
            (string) ($validated['word'] ?? ''),
            (string) ($validated['words'] ?? ''),
        );

        if ($candidates === []) {
            return response()->json(['message' => '请提供至少一个候选词。'], 422);
        }

        if (count($candidates) > PlatformSensitiveWordDryRun::MAX_CANDIDATES) {
            return response()->json([
                'message' => sprintf(
                    '一次最多试算 %d 个候选词，当前 %d 个。请分批试算。',
                    PlatformSensitiveWordDryRun::MAX_CANDIDATES,
                    count($candidates),
                ),
            ], 422);
        }

        $dryRun = PlatformSensitiveWordDryRun::query()->create([
            'tenant_id' => $tenantId,
            'requested_by' => $request->user()->getKey(),
            'candidates' => $candidates,
            'status' => PlatformSensitiveWordDryRun::STATUS_PENDING,
        ]);

        DryRunPlatformSensitiveWordsJob::dispatch((int) $dryRun->getKey(), $tenantId);

        return response()->json([
            'object' => 'platform_sensitive_word_dry_run',
            'data' => $this->dryRunPayload($dryRun),
            'message' => '试算已入队，当前工作区扫描完成后返回结果。',
        ], 202);
    }

    /** GET /v1/platform/sensitive-words/dry-run/{id} —— 捞试算结果（前端轮询）。 */
    public function dryRunShow(Request $request, int $id): JsonResponse
    {
        $dryRun = PlatformSensitiveWordDryRun::query()->whereKey($id)
            ->where('tenant_id', $this->workspaceId($request))
            ->where('requested_by', $request->user()->getKey())
            ->firstOrFail();

        return response()->json([
            'object' => 'platform_sensitive_word_dry_run',
            'data' => $this->dryRunPayload($dryRun),
        ]);
    }

    /**
     * GET /v1/platform/sensitive-word-hits —— 命中日志查询（T7 那张表）。
     *
     * 支持按**文章 ID** 筛是硬要求：老板要的是「给定文章 ID，运营能答出它被哪条词拦的」。
     * 租户侧读不到这张表的任何一行，这里是唯一入口。
     */
    public function hits(Request $request): JsonResponse
    {
        $tenantId = $this->workspaceId($request);
        $validated = $request->validate([
            'article_id' => ['nullable', 'integer', 'min:1'],
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'word' => ['nullable', 'string', 'max:255'],
            'origin' => ['nullable', 'string', 'in:'.SensitiveWordHit::ORIGIN_PLATFORM.','.SensitiveWordHit::ORIGIN_TENANT],
            'trigger' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);
        $offset = (int) ($validated['offset'] ?? 0);

        $query = SensitiveWordHit::query()->where('tenant_id', $tenantId);

        foreach (['article_id', 'tenant_id', 'origin', 'trigger'] as $field) {
            if (! empty($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        if (! empty($validated['word'])) {
            $query->where('word', 'like', '%'.$validated['word'].'%');
        }

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('id')->offset($offset)->limit($limit)->get();
        $tenantNames = Tenant::query()->whereKey($tenantId)->pluck('name', 'id');

        return response()->json([
            'object' => 'sensitive_word_hit_list',
            'data' => $rows->map(static fn (SensitiveWordHit $hit): array => [
                'id' => (int) $hit->getKey(),
                'tenant_id' => (int) $hit->tenant_id,
                'tenant_name' => (string) ($tenantNames[$hit->tenant_id] ?? ('租户 #'.$hit->tenant_id)),
                'article_id' => $hit->article_id === null ? null : (int) $hit->article_id,
                'word' => (string) $hit->word,
                'severity' => (string) $hit->severity,
                'origin' => (string) $hit->origin,
                'surface' => (string) $hit->surface,
                'context' => $hit->context,
                'trigger' => (string) $hit->trigger,
                'created_at' => $hit->created_at?->toISOString(),
            ])->all(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'retention_days' => SensitiveWordHit::RETENTION_DAYS,
        ]);
    }

    /**
     * GET /v1/platform/sensitive-word-hits/summary —— **按词聚合**：误伤的事后发现手段。
     *
     * 默认按命中篇数倒序。这个默认排序不是审美问题：
     * 租户侧不会来报障（政治词不点名、不能自助停用、不开申诉通道），
     * 平台只能靠这张聚合表主动看出「哪条词加错了」。
     * 加错的常用词命中量会远高于真红线词，倒序排它自己就浮到最上面。
     */
    public function hitsSummary(Request $request): JsonResponse
    {
        $tenantId = $this->workspaceId($request);
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'origin' => ['nullable', 'string', 'in:'.SensitiveWordHit::ORIGIN_PLATFORM.','.SensitiveWordHit::ORIGIN_TENANT],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $limit = (int) ($validated['limit'] ?? 100);
        $days = isset($validated['days']) ? (int) $validated['days'] : null;

        $query = SensitiveWordHit::query()
            ->where('tenant_id', $tenantId)
            ->select('word', 'origin')
            ->selectRaw('count(*) as hit_count')
            ->selectRaw('count(distinct article_id) as article_count')
            ->selectRaw('count(distinct tenant_id) as tenant_count')
            ->selectRaw('max(created_at) as last_hit_at')
            ->groupBy('word', 'origin')
            ->orderByDesc(DB::raw('count(distinct article_id)'))
            ->orderByDesc(DB::raw('count(*)'))
            ->limit($limit);

        if ($days !== null) {
            $query->where('created_at', '>=', now()->subDays($days));
        }

        if (! empty($validated['origin'])) {
            $query->where('origin', $validated['origin']);
        }

        $rows = $query->get();

        return response()->json([
            'object' => 'sensitive_word_hit_summary',
            'window' => ['days' => $days],
            'sorted_by' => 'article_count_desc',
            'data' => $rows->map(static fn ($row): array => [
                'word' => (string) $row->word,
                'origin' => (string) $row->origin,
                'article_count' => (int) $row->article_count,
                'tenant_count' => (int) $row->tenant_count,
                'hit_count' => (int) $row->hit_count,
                'last_hit_at' => $row->last_hit_at,
            ])->all(),
            'retention_days' => SensitiveWordHit::RETENTION_DAYS,
            'scanner_version' => ArticleRiskScanner::SCAN_ALGORITHM_VERSION,
        ]);
    }

    /**
     * 批量拆词：支持换行 / 英文逗号 / 中文逗号 / 顿号 / 分号。
     *
     * 与租户侧 SensitiveWordController::store() 的拆法保持一致 —— 两侧不一致
     * 会让同一段粘贴内容在两个面板里拆出不同结果，运营/租户都会以为自己加错了。
     *
     * @return array<int, string>
     */
    private function splitCandidates(string $single, string $bulk): array
    {
        $candidates = [];

        if (trim($single) !== '') {
            $candidates[] = trim($single);
        }

        if (trim($bulk) !== '') {
            $candidates = array_merge($candidates, preg_split('/[\r\n,，、;；]+/u', $bulk) ?: []);
        }

        $candidates = array_filter(
            array_map('trim', $candidates),
            static fn (string $w): bool => $w !== '' && mb_strlen($w, 'UTF-8') <= 255,
        );

        return array_values(array_unique($candidates));
    }

    private function workspaceId(Request $request): int
    {
        $tenantId = (int) $request->user()->tenant_id;
        abort_unless($tenantId > 0, 403, '当前账号没有有效工作区。');
        abort_unless(Tenant::query()->whereKey($tenantId)->where('status', 'active')->exists(), 404);

        return $tenantId;
    }

    /** @return array<string, mixed> */
    private function dryRunPayload(PlatformSensitiveWordDryRun $dryRun): array
    {
        return [
            'id' => (int) $dryRun->getKey(),
            'status' => (string) $dryRun->status,
            'finished' => $dryRun->isFinished(),
            'candidates' => (array) $dryRun->candidates,
            'articles_scanned' => (int) $dryRun->articles_scanned,
            // 如实回「有几篇没扫到」：把跳过的篇数藏起来，运营会把不完整的
            // 试算结果当成完整结论，误伤面就被系统性低估了。
            'articles_skipped' => (int) $dryRun->articles_skipped,
            'hit_articles' => (int) $dryRun->hit_articles,
            'by_tenant' => $dryRun->by_tenant ?? [],
            'by_word' => $dryRun->by_word ?? [],
            'error' => $dryRun->error,
            'started_at' => $dryRun->started_at?->toISOString(),
            'finished_at' => $dryRun->finished_at?->toISOString(),
        ];
    }
}
