<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 文章分类 CRUD（对标 GEOFlow CategoryController）
 *
 * 多租户隔离：Category 使用 BelongsToTenant，路由模型绑定 `{category}`
 * 在 tenant 中间件下自动按当前租户过滤，跨租户直接 404。
 */
class CategoryController extends Controller
{
    /**
     * GET /api/v1/categories
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::ordered();

        if ($request->query('with_counts')) {
            $query->withCount('articles');
        }

        return response()->json([
            'data' => $query->get()->map(fn (Category $category) => $this->payload($category)),
        ]);
    }

    /**
     * POST /api/v1/categories
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('categories', 'slug')->where(fn ($q) => $q->where('tenant_id', TenantContext::id())),
            ],
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer',
        ]);

        if (isset($validated['slug']) && is_string($validated['slug'])) {
            $validated['slug'] = strtolower($validated['slug']) ?: null;
        }

        $category = Category::create([
            'tenant_id' => TenantContext::id(),
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? null,
            'description' => $validated['description'] ?? null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return response()->json($this->payload($category), 201);
    }

    /**
     * GET /api/v1/categories/{category}
     */
    public function show(Category $category): JsonResponse
    {
        $this->ensureOwns($category);

        return response()->json($this->payload($category));
    }

    /**
     * PATCH /api/v1/categories/{category}
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $this->ensureOwns($category);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:180',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('categories', 'slug')->where(fn ($q) => $q->where('tenant_id', TenantContext::id()))->ignore($category->id),
            ],
            'description' => 'sometimes|nullable|string|max:2000',
            'sort_order' => 'sometimes|nullable|integer',
        ]);

        if (array_key_exists('slug', $validated) && is_string($validated['slug'])) {
            $validated['slug'] = strtolower($validated['slug']) ?: null;
        }

        $category->update($validated);

        return response()->json($this->payload($category));
    }

    /**
     * DELETE /api/v1/categories/{category}
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->ensureOwns($category);

        $category->delete();

        return response()->json(['message' => __('messages.category.deleted')]);
    }

    /**
     * 路由模型绑定在 tenant 中间件之前执行，TenantScope 此时为空操作，
     * 因此必须手动校验租户归属（与项目内其他控制器一致）。
     */
    private function ensureOwns(Category $category): void
    {
        if ((int) $category->tenant_id !== (int) TenantContext::id()) {
            abort(404, __('messages.category.not_found'));
        }
    }

    private function payload(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'sort_order' => $category->sort_order,
            'articles_count' => $category->articles_count ?? null,
            'created_at' => $category->created_at?->toISOString(),
            'updated_at' => $category->updated_at?->toISOString(),
        ];
    }
}
