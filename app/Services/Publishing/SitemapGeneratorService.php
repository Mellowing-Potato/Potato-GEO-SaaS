<?php

namespace App\Services\Publishing;

use App\Models\Article;
use App\Models\Project;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Public sitemaps are rendered from current publication state on every request. */
class SitemapGeneratorService
{
    private const MAX_URLS_PER_FILE = 5000;

    public function generate(?int $projectId = null): array
    {
        $tenant = $this->currentTenant();
        [$xml, $count] = $this->renderTenant($tenant, $projectId);
        $path = $this->path($tenant, $projectId);
        $url = $this->baseUrl().$path;

        return [
            'path' => $path,
            'url' => $url,
            'url_count' => $count,
            'generated_at' => now()->toISOString(),
            'status' => 'generated',
            'live_check' => $this->checkLive($url, $xml),
            'delivery' => 'dynamic',
        ];
    }

    /** A project map is a subset of the tenant map; neither writes over the other. */
    public function renderTenant(Tenant $tenant, ?int $projectId = null): array
    {
        if ($tenant->status !== 'active') {
            abort(404);
        }
        if ($projectId !== null) {
            Project::query()->where('tenant_id', $tenant->id)->whereKey($projectId)->firstOrFail();
        }

        $query = Article::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'published')
            ->whereNotNull('slug')
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->with('project:id,tenant_id')
            ->orderBy('id');

        $seen = [];
        $urls = [];
        foreach ($query->lazyById(100) as $article) {
            if ($article->project_id !== null && $article->project === null) {
                continue;
            }
            if (($article->metadata['visibility'] ?? 'public') === 'private') {
                continue;
            }
            $loc = $this->articleUrl($article, $tenant);
            if ($loc === null || isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $urls[] = [
                'loc' => $loc,
                'lastmod' => ($article->updated_at ?? $article->published_at ?? now())->toW3cString(),
            ];
            if (count($urls) > self::MAX_URLS_PER_FILE) {
                throw ValidationException::withMessages([
                    'sitemap' => '公开文章超过单张 Sitemap 的 5000 条上限；请按项目拆分，不能将截断结果当作全量地图。',
                ]);
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.$this->escape($url['loc']).'</loc><lastmod>'.$this->escape($url['lastmod'])."</lastmod></url>\n";
        }

        return [$xml."</urlset>\n", count($urls)];
    }

    public function renderIndex(): string
    {
        $tenantIds = Article::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->distinct()->pluck('tenant_id');
        $tenants = Tenant::query()->whereIn('id', $tenantIds)->where('status', 'active')->orderBy('id')->get();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($tenants as $tenant) {
            TenantContext::set($tenant);
            try {
                [, $count] = $this->renderTenant($tenant);
                if ($count > 0) {
                    $xml .= '  <sitemap><loc>'.$this->escape($this->baseUrl().$this->path($tenant))."</loc></sitemap>\n";
                }
            } finally {
                TenantContext::forget();
            }
        }
        return $xml."</sitemapindex>\n";
    }

    /** Domain-level preview. A tenant cannot change the domain robots policy. */
    public function robotsTxt(?int $projectId = null): string
    {
        return (string) file_get_contents(public_path('robots.txt'));
    }

    public function robotsStatus(): array
    {
        return $this->checkLive($this->baseUrl().'/robots.txt', $this->robotsTxt());
    }

    public function publicPageStatus(): array
    {
        return ['state' => 'retired', 'checked_at' => now()->toISOString()];
    }

    private function articleUrl(Article $article, Tenant $tenant): ?string
    {
        if (! is_string($article->slug) || trim($article->slug) === '' || trim($tenant->subdomain) === '') {
            return null;
        }
        $url = $this->baseUrl().'/read/'.rawurlencode($tenant->subdomain).'/'.rawurlencode($article->slug);
        // An external canonical belongs to that target site's sitemap, not this domain.
        if ($article->canonical_url && rtrim($article->canonical_url, '/') !== rtrim($url, '/')) {
            return null;
        }
        return $url;
    }

    private function path(Tenant $tenant, ?int $projectId = null): string
    {
        $prefix = '/sitemaps/'.rawurlencode($tenant->subdomain);
        return $projectId === null ? $prefix.'.xml' : $prefix.'/projects/'.$projectId.'.xml';
    }

    private function currentTenant(): Tenant
    {
        $tenant = TenantContext::get();
        if (! $tenant) {
            throw new \RuntimeException('Sitemap 生成需要租户上下文。');
        }
        return $tenant;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function checkLive(string $url, string $expected): array
    {
        try {
            $response = Http::timeout(3)->get($url);
            $tag = $response->header('X-Robots-Tag');
            $matches = $response->successful() && $response->body() === $expected;
            return [
                'state' => $matches ? 'live' : 'mismatch',
                'checked_at' => now()->toISOString(),
                'http_status' => $response->status(),
                'x_robots_tag' => $tag,
                'matches_preview' => $matches,
            ];
        } catch (\Throwable $exception) {
            return ['state' => 'check_failed', 'checked_at' => now()->toISOString(), 'reason' => '公开地址检查失败。'];
        }
    }
}
