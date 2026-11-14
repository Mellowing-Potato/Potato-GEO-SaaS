<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Publishing\SitemapGeneratorService;
use App\Support\TenantContext;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __construct(private readonly SitemapGeneratorService $sitemaps) {}

    public function index(): Response
    {
        return $this->xml($this->sitemaps->renderIndex());
    }

    public function tenant(string $tenantSlug): Response
    {
        return $this->tenantMap($tenantSlug);
    }

    public function project(string $tenantSlug, int $projectId): Response
    {
        return $this->tenantMap($tenantSlug, $projectId);
    }

    private function tenantMap(string $tenantSlug, ?int $projectId = null): Response
    {
        $tenant = Tenant::query()->where('subdomain', $tenantSlug)->where('status', 'active')->firstOrFail();
        TenantContext::set($tenant);
        try {
            [$xml] = $this->sitemaps->renderTenant($tenant, $projectId);
            return $this->xml($xml);
        } finally {
            TenantContext::forget();
        }
    }

    private function xml(string $content): Response
    {
        return response($content, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
