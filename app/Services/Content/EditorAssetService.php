<?php

namespace App\Services\Content;

use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\ImageLibrary;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * 编辑器素材接入服务（对标 GEOFlow ArticleEditorAsset / ArticleImage）
 *
 * 负责文章编辑器与素材库（ImageLibrary，阶段 1.1）之间的图片引用管理：
 * 插入即写 ArticleImage 引用并累加使用计数，移除即删引用并递减计数。
 */
final class EditorAssetService
{
    /**
     * 列出文章已插入的素材库图片（按 position 排序）。
     *
     * @return Collection<int, ArticleImage>
     */
    public function list(Article $article): Collection
    {
        return $article->articleImages()
            ->with(['image:id,url,title,alt'])
            ->orderBy('position')
            ->get();
    }

    /**
     * 插入一张素材库图片（幂等：重复插入不报错）。
     */
    public function attach(Article $article, int $imageLibraryId, ?int $position = null): ArticleImage
    {
        $this->assertImageBelongsToTenant($imageLibraryId);

        $link = ArticleImage::withoutGlobalScopes()->updateOrCreate(
            [
                'article_id' => $article->id,
                'image_library_id' => $imageLibraryId,
            ],
            [
                'tenant_id' => $article->tenant_id,
                'position' => (int) ($position ?? 0),
            ],
        );

        ImageLibrary::withoutGlobalScopes()
            ->where('id', $imageLibraryId)
            ->increment('usage_count');

        return $link;
    }

    /**
     * 移除一张素材库图片引用（需属于该文章 + 当前租户）。
     */
    public function detach(Article $article, int $linkId): void
    {
        $link = ArticleImage::withoutGlobalScopes()
            ->where('id', $linkId)
            ->where('article_id', $article->id)
            ->where('tenant_id', $article->tenant_id)
            ->firstOrFail();

        $imageLibraryId = $link->image_library_id;
        $link->delete();

        ImageLibrary::withoutGlobalScopes()
            ->where('id', $imageLibraryId)
            ->decrement('usage_count');
    }

    /**
     * 调整插入图片的顺序。
     *
     * @param  array<int>  $orderedLinkIds
     */
    public function reorder(Article $article, array $orderedLinkIds): void
    {
        foreach ($orderedLinkIds as $index => $linkId) {
            ArticleImage::withoutGlobalScopes()
                ->where('id', (int) $linkId)
                ->where('article_id', $article->id)
                ->where('tenant_id', $article->tenant_id)
                ->update(['position' => $index]);
        }
    }

    private function assertImageBelongsToTenant(int $imageLibraryId): void
    {
        $exists = ImageLibrary::withoutGlobalScopes()
            ->where('id', $imageLibraryId)
            ->where('tenant_id', TenantContext::id())
            ->exists();

        if (! $exists) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'image_library_id' => 'The selected image_library_id is invalid.',
            ]);
        }
    }
}
