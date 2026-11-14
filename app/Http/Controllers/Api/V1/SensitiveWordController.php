<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SensitiveWord;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 敏感词管理控制器（复刻 GEOFlow SensitiveWord 实体 + 管理台，租户隔离）。
 *
 * 路由前缀 /api/v1/sensitive-words，读写需 article:edit（内容合规管理）。
 *
 * ⚠️ T10 红线：这个控制器**只碰租户自己的词**（`tenant_id = TenantContext::id()`），
 * 任何情况下都不要在这里 join 或回显平台词（`PlatformSensitiveWord`）。
 * 平台红线词由独立的 v1/platform 控制器管理，且绝不回显给租户侧——返工 1 已证明
 * 一旦从租户接口漏出平台词原文，作者试几次就能把整份清单试出来。
 */
class SensitiveWordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // severity 必须回给前端：warning 与 blocked 在产品里是两件事
        // （blocked 会真的挡住发布单进「待发」，见 ManualPublicationService），
        // 列表不显示等级，管理员就无从知道自己加的词到底拦不拦得住。
        $words = SensitiveWord::query()
            ->where('tenant_id', TenantContext::id())
            ->orderBy('severity')
            ->orderBy('word')
            ->get(['id', 'word', 'severity', 'category', 'is_enabled', 'suggestion', 'created_at']);

        return response()->json([
            'object' => 'sensitive_word_list',
            'data' => $words->toArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'word' => 'nullable|string|max:255',
            'words' => 'nullable|string',
            // 2026-08-30：此前不收 severity，新词一律落模型默认值 warning，
            // 于是「拦截」级在产品里根本加不出来，扫描器的 blocked 分支是死路。
            'severity' => 'nullable|string|in:warning,blocked',
            'category' => 'nullable|string|max:32',
            'suggestion' => 'nullable|string|max:500',
        ]);

        $severity = (string) ($validated['severity'] ?? 'warning');
        $category = trim((string) ($validated['category'] ?? '')) ?: 'sensitive';
        $suggestion = trim((string) ($validated['suggestion'] ?? '')) ?: null;

        // 支持单次一个词，或批量（换行 / 逗号 / 顿号分隔）
        $candidates = [];
        if (! empty($validated['word'])) {
            $candidates[] = trim((string) $validated['word']);
        }
        if (! empty($validated['words'])) {
            $candidates = array_merge(
                $candidates,
                preg_split('/[\r\n,，、;；]+/u', (string) $validated['words']) ?: [],
            );
        }

        $candidates = array_values(array_filter(array_map('trim', $candidates), static fn (string $w): bool => $w !== ''));
        $candidates = array_unique($candidates);

        if ($candidates === []) {
            return response()->json(['message' => '请提供至少一个敏感词。'], 422);
        }

        $tenantId = TenantContext::id();
        $existing = SensitiveWord::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('word', $candidates)
            ->get()
            ->keyBy('word');

        $inserted = [];
        $updated = [];
        $skipped = 0;
        foreach ($candidates as $word) {
            $current = $existing->get($word);
            if ($current instanceof SensitiveWord) {
                // 已存在的词：等级/建议有变就升级，不能静默跳过。
                // 库里 18 个种子词全是 warning，管理员想把某个词提到 blocked，
                // 唯一入口就是重新提交同一个词——静默跳过等于这个操作永远无效。
                if ((string) $current->severity === $severity
                    && (string) $current->category === $category
                    && (string) ($current->suggestion ?? '') === (string) ($suggestion ?? '')) {
                    $skipped++;

                    continue;
                }
                $current->forceFill([
                    'severity' => $severity,
                    'category' => $category,
                    'suggestion' => $suggestion,
                ])->save();
                $updated[] = $current;

                continue;
            }
            $inserted[] = SensitiveWord::query()->create([
                'tenant_id' => $tenantId,
                'word' => $word,
                'severity' => $severity,
                'category' => $category,
                'suggestion' => $suggestion,
            ]);
        }

        return response()->json([
            'object' => 'sensitive_word_list',
            'data' => array_map(
                static fn (SensitiveWord $w): array => $w->toArray(),
                array_merge($inserted, $updated),
            ),
            'inserted' => count($inserted),
            'updated' => count($updated),
            'skipped_duplicates' => $skipped,
        ], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $word = SensitiveWord::query()
            ->where('tenant_id', TenantContext::id())
            ->whereKey($id)
            ->firstOrFail();

        $word->delete();

        return response()->json(['message' => '敏感词已删除。']);
    }
}
