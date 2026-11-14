<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Prompt;
use App\Services\Content\PromptTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 提示词管理台控制器（阶段 1.2）
 *
 * 路由前缀 /api/v1/skill-prompts，按技能 slug（skill_id）管理租户级提示词覆盖。
 * 查看需 project:view，写入需 prompt:manage（admin / editor）。
 *
 * 注：路由参数 {skill} 是技能 slug 字符串（非模型绑定），所有查询均显式按当前租户过滤，
 * 不存在跨租户绑定问题。无覆盖时返回 404（调用方据此回退默认资产）。
 */
class PromptController extends Controller
{
    public function __construct(
        private readonly PromptTemplateService $prompts,
    ) {}

    // ─── 列出当前租户全部提示词覆盖 ───
    public function index(): JsonResponse
    {
        return response()->json([
            'object' => 'skill_prompt_list',
            'data' => $this->prompts->listForTenant()
                ->map(fn (Prompt $p): array => $p->toArray())
                ->values(),
        ]);
    }

    // ─── 读取某技能的提示词覆盖（无则 404）───
    public function show(string $skill): JsonResponse
    {
        $prompt = Prompt::query()
            ->where('skill_id', $skill)
            ->first();

        if ($prompt === null) {
            abort(404);
        }

        return response()->json([
            'object' => 'skill_prompt',
            'data' => $prompt->toArray(),
        ]);
    }

    // ─── 新建 / 更新某技能的提示词覆盖 ───
    public function update(Request $request, string $skill): JsonResponse
    {
        $validated = $request->validate([
            'prompt_text' => 'required|string|min:1',
            'is_active' => 'nullable|boolean',
        ]);

        $prompt = $this->prompts->upsert(
            $skill,
            $validated['prompt_text'],
            (bool) ($validated['is_active'] ?? true),
        );

        return response()->json([
            'object' => 'skill_prompt',
            'data' => $prompt->toArray(),
        ]);
    }

    // ─── 删除覆盖（恢复默认资产）───
    public function reset(string $skill): JsonResponse
    {
        $this->prompts->reset($skill);

        return response()->json(['message' => '已重置为默认提示词。']);
    }
}
