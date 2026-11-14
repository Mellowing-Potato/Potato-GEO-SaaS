<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $subdomain = $this->extractSubdomain($request);

        if (! $subdomain) {
            abort(404, 'Tenant not found');
        }

        $tenant = Tenant::where('subdomain', $subdomain)->first();

        if (! $tenant || ! $tenant->isActive()) {
            abort(404, 'Tenant not found or inactive');
        }

        $user = $request->user();
        if ($user && (int) $user->tenant_id !== (int) $tenant->id) {
            abort(403, 'You do not have access to this tenant');
        }

        TenantContext::set($tenant);

        try {
            return $next($request);
        } finally {
            TenantContext::forget();
        }
    }

    protected function extractSubdomain(Request $request): ?string
    {
        if (app()->isLocal() || app()->environment('testing')) {
            $override = $request->header('X-Tenant') ?? $request->query('tenant');
            if ($override) {
                return (string) $override;
            }
        }

        $host = $request->getHost();
        $baseDomain = config('app.tenant_domain');
        $override = $request->header('X-Tenant') ?? $request->query('tenant');

        if ($host === $baseDomain && $override) {
            return (string) $override;
        }

        if (str_ends_with($host, '.'.$baseDomain)) {
            return str_replace('.'.$baseDomain, '', $host);
        }

        // Shared-origin installations use the authenticated user's workspace.
        // Explicit header/subdomain selection above still undergoes ownership validation.
        if ($host === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return $request->user()?->tenant?->subdomain;
        }
        return null;
    }
}
