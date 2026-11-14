<?php

namespace App\Services\Distribution;

use App\Models\Article;
use App\Models\PublishingChannel;
use App\Services\Distribution\Publishers\WordPressRestPublisher;
use Illuminate\Support\Facades\Log;

/**
 * WordPress 分类 / 标签同步服务（复刻 GEOFlow WordPressTaxonomySyncService）。
 *
 * 与 GEOFlow 的差异：
 * - GEOFlow 基于 DistributionChannel + WordPressRestRequestFactory；本实现适配 geo-saas 的
 *   PublishingChannel，并复用 WordPressRestPublisher 的鉴权客户端（requestFor）。
 * - 为避免与 WordPressRestPublisher 形成构造期循环依赖，发布器实例在运行时通过
 *   app(WordPressRestPublisher::class) 惰性获取（requestFor 已为 public）。
 * - 除文章发布时的分类/标签查找或创建外，额外提供 preSync 批量预同步能力，
 *   便于先把租户的分类/标签词表同步到目标 WP 站点（GEOFlow 仅在发布时按需创建）。
 */
class WordPressTaxonomySyncService
{

    /**
     * 文章发布时解析分类 ID 列表（委托自 WordPressRestPublisher）。
     *
     * @return list<int>
     */
    public function resolveCategories(PublishingChannel $channel, array $config, Article $article): array
    {
        $strategy = (string) data_get($config, 'category_strategy', 'disabled');

        if ($strategy === 'disabled') {
            return [];
        }

        if ($strategy === 'fixed') {
            return array_values(array_filter(array_map('intval', data_get($config, 'fixed_category_ids', []))));
        }

        $categoryName = $article->content_type ?? 'general';

        return $this->findOrCreateTerm($channel, 'categories', $categoryName);
    }

    /**
     * 文章发布时解析标签 ID 列表（委托自 WordPressRestPublisher）。
     *
     * @return list<int>
     */
    public function resolveTags(PublishingChannel $channel, array $config, Article $article): array
    {
        $strategy = (string) data_get($config, 'tag_strategy', 'disabled');

        if ($strategy === 'disabled') {
            return [];
        }

        $keywords = data_get($article->metadata, 'keywords', []);
        if (! is_array($keywords) || $keywords === []) {
            return [];
        }

        $ids = [];
        foreach (array_slice($keywords, 0, 10) as $keyword) {
            $ids = array_merge($ids, $this->findOrCreateTerm($channel, 'tags', (string) $keyword));
        }

        return $ids;
    }

    /**
     * 查找或创建单个 WP 术语（分类/标签）。命中返回 [id]，失败返回 []。
     *
     * @return list<int>
     */
    public function findOrCreateTerm(PublishingChannel $channel, string $taxonomy, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $baseUrl = rtrim((string) $channel->endpoint_url, '/');
        $url = $baseUrl . "/wp-json/wp/v2/{$taxonomy}";

        // 先按名称精确匹配已有术语
        try {
            $search = app(WordPressRestPublisher::class)->requestFor($channel)
                ->get($url, ['search' => $name, 'per_page' => 5]);

            if ($search->successful()) {
                foreach ($search->json() ?? [] as $term) {
                    if (strtolower((string) data_get($term, 'name', '')) === strtolower($name)) {
                        return [(int) data_get($term, 'id')];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WordPress taxonomy search failed', ['channel_id' => $channel->id, 'error' => $e->getMessage()]);
        }

        // 未命中 → 创建
        try {
            $create = app(WordPressRestPublisher::class)->requestFor($channel)
                ->post($url, ['name' => $name]);

            if ($create->successful()) {
                $data = $create->json() ?? [];

                return [(int) data_get($data, 'id')];
            }
        } catch (\Throwable $e) {
            Log::warning('WordPress taxonomy create failed', ['channel_id' => $channel->id, 'error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * 批量预同步一组术语到目标 WP 站点（先查后建），返回 名称 => 远程 ID 映射。
     *
     * @param  list<string>  $names
     * @return array<string, int>
     */
    public function preSync(PublishingChannel $channel, string $taxonomy, array $names): array
    {
        $result = [];

        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $found = $this->findOrCreateTerm($channel, $taxonomy, $name);
            if ($found !== []) {
                $result[$name] = $found[0];
            }
        }

        return $result;
    }
}
