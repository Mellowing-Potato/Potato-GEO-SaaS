<?php

namespace App\Services\Publishing;

use App\Models\Article;
use App\Models\Tenant;
use App\Support\TenantContext;
use League\CommonMark\CommonMarkConverter;
use Throwable;

/**
 * 租户托管文章阅读页交付服务（阶段 3.5，按 D-1 决策：不复刻 WP 主题引擎）。
 *
 * 已发布文章通过公读 URL（/read/{tenantSlug}/{slug} 或租户子域）对外可读；
 * 未发布 / 私有 / 不存在的文章一律返回 null（调用方转 404）。
 */
final class SiteDeliveryService
{
    private ?CommonMarkConverter $converter = null;

    /**
     * 解析公读文章视图数据；不可读时返回 null。
     *
     * @return array{article: Article, tenant: Tenant, html: string, seo: array<string,string>}|null
     */
    public function resolve(string $tenantSlug, string $articleSlug): ?array
    {
        $tenant = Tenant::query()->where('subdomain', strtolower($tenantSlug))->first();
        if (! $tenant) {
            return null;
        }
        if (! $tenant->isActive()) {
            return null;
        }

        TenantContext::set($tenant);

        try {
            $article = Article::query()
                ->where('tenant_id', $tenant->id)
                ->where('slug', $articleSlug)
                ->where('status', 'published')
                ->first();

            if (! $article) {
                return null;
            }

            // 项目进垃圾桶（计划 §9.5 的软删，7 天内可恢复）时，它的托管内容同步下线：
            // 项目已经"删除"了，公开页却还在线，等于删除没发生（客户看到的是"删不掉"）。
            // Project 是 SoftDeletes，所以这里读到的 null 既包含"项目真没了"也包含"项目在桶里"，
            // 恢复出桶后这段守卫自然放行 —— 不需要另开一份"桶内文章"清单来维护。
            if ($article->project_id !== null && $article->project === null) {
                return null;
            }

            // 私有文章不对外公开（metadata.visibility = private）
            if (($article->metadata['visibility'] ?? 'public') === 'private') {
                return null;
            }

            return [
                'article' => $article,
                'tenant' => $tenant,
                'html' => $this->renderReadingBody((string) $article->body_markdown, (string) $article->title),
                'seo' => $this->seo($article, $tenant),
            ];
        } finally {
            TenantContext::forget();
        }
    }

    public function renderMarkdown(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        if ($this->converter === null) {
            $this->converter = new CommonMarkConverter([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
        }

        try {
            return (string) $this->converter->convert($markdown);
        } catch (Throwable) {
            return e($markdown);
        }
    }

    private function renderReadingBody(string $markdown, string $title): string
    {
        // The public page already renders the article title as its main heading.
        // Drop a matching Markdown heading at the start of the body to avoid a duplicate h1.
        if (preg_match('/\A[ \t]*#[ \t]+([^\r\n]+)(?:\r?\n|$)/u', $markdown, $heading) === 1
            && trim($heading[1]) === trim($title)) {
            $markdown = ltrim(substr($markdown, strlen($heading[0])), "\r\n");
        }

        return $this->renderMarkdown($markdown);
    }

    /**
     * @return array<string,string>
     */
    private function seo(Article $article, Tenant $tenant): array
    {
        $description = $article->seo_description
            ?? $article->summary
            ?? mb_substr(strip_tags((string) $article->body_markdown), 0, 160);

        $selfUrl = route('reading.article', ['tenantSlug' => $tenant->subdomain, 'slug' => $article->slug]);
        $canonical = $article->canonical_url ?: $selfUrl;

        return [
            'title' => $article->seo_title ?: $article->title,
            'description' => (string) $description,
            'og_title' => $article->og_title ?: ($article->seo_title ?: $article->title),
            'og_description' => $article->og_description ?: (string) $description,
            'og_image' => $article->og_image ?: '',
            'canonical_url' => $canonical,
            'external_canonical' => rtrim($canonical, '/') !== rtrim($selfUrl, '/'),
            'site_name' => trim((string) ($article->project?->brand_name)) ?: ($tenant->name ?: $tenant->subdomain),
        ];
    }
}
