<?php

namespace App\Services\Content;

use App\Models\Author;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\TitleLibrary;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * 素材库服务（对标 GEOFlow MaterialLibraryService）
 *
 * 统一管理四类可复用素材：标题 / 关键词 / 图片 / 作者。
 * 全部租户隔离（依赖 BelongsToTenant 全局作用域 + TenantContext）。
 */
class MaterialLibraryService
{
    private const TYPES = [
        'title' => TitleLibrary::class,
        'keyword' => KeywordLibrary::class,
        'image' => ImageLibrary::class,
        'author' => Author::class,
    ];

    /**
     * 列出某类素材，支持按分类精确过滤与关键词模糊搜索。
     */
    public function list(string $type, array $filters = []): Collection
    {
        $model = $this->model($type);
        $query = $model::query();

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['q'])) {
            $column = match ($type) {
                'title' => 'title',
                'keyword' => 'keyword',
                'image' => 'url',
                'author' => 'name',
            };
            $query->where($column, 'like', '%' . $filters['q'] . '%');
        }

        return $query->orderByDesc('id')->get();
    }

    /**
     * 创建素材，自动归属当前租户。
     */
    public function create(string $type, array $data): Model
    {
        $model = $this->model($type);
        $data['tenant_id'] = TenantContext::id();

        return $model::create($data);
    }

    /**
     * 更新素材。
     */
    public function update(string $type, int $id, array $data): Model
    {
        $material = $this->find($type, $id);
        $material->update($data);

        return $material->fresh();
    }

    /**
     * 删除素材。
     */
    public function delete(string $type, int $id): void
    {
        $this->find($type, $id)->delete();
    }

    /**
     * 取单条（受租户作用域约束，跨租户返回 404）。
     */
    public function find(string $type, int $id): Model
    {
        $model = $this->model($type);

        return $model::query()->whereKey($id)->firstOrFail();
    }

    /**
     * 为技能 brief 抽取若干素材（随机取，可选分类）。
     */
    public function pick(string $type, int $limit, ?string $category = null): Collection
    {
        $model = $this->model($type);
        $query = $model::query();

        if ($category) {
            $query->where('category', $category);
        }

        if ($limit > 0) {
            $query->inRandomOrder()->limit($limit);
        }

        return $query->get();
    }

    /**
     * 记录素材被使用（技能执行 / 文章引用后调用），自增 usage_count。
     *
     * @param  array<int, array{type: string, id: int}>  $refs
     */
    public function recordUsage(array $refs): void
    {
        foreach ($refs as $ref) {
            if (! isset($ref['type'], $ref['id'])) {
                continue;
            }

            if (! isset(self::TYPES[$ref['type']])) {
                continue;
            }

            $model = self::TYPES[$ref['type']];
            // TenantScope 已自动追加 tenant_id 条件，保证只命中本租户行。
            $model::query()->whereKey((int) $ref['id'])->increment('usage_count');
        }
    }

    private function model(string $type): string
    {
        if (! isset(self::TYPES[$type])) {
            throw new InvalidArgumentException("未知的素材类型: {$type}");
        }

        return self::TYPES[$type];
    }
}
