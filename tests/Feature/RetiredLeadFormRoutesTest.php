<?php

namespace Tests\Feature;

use App\Services\Reports\AiVisibilityAnalyticsService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RetiredLeadFormRoutesTest extends TestCase
{
    public function test_retired_lead_form_routes_are_unregistered_and_return_404(): void
    {
        $this->assertNull(config('geo.lead_form_enabled'));
        $this->assertArrayNotHasKey('lead:manage', Permissions::MATRIX);
        $this->assertFalse(Permissions::allows('editor', 'lead:manage'));
        $this->assertFalse(Permissions::allows('member', 'lead:manage'));

        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();

        $leaked = array_values(array_filter(
            $registered,
            fn (string $uri): bool => $uri === 'forms'
                || str_starts_with($uri, 'forms/')
                || $uri === 'api/v1/lead-forms'
                || str_starts_with($uri, 'api/v1/lead-forms/')
                || $uri === 'api/v1/leads'
                || str_starts_with($uri, 'api/v1/leads/')
                || $uri === 'api/v1/analytics/leads'
                || str_starts_with($uri, 'api/v1/analytics/leads/')
                || $uri === 'api/v1/lead-analytics'
                || str_starts_with($uri, 'api/v1/lead-analytics/'),
        ));
        $this->assertSame([], $leaked);

        $this->get('/forms/example-tenant/contact')->assertStatus(404);
        $this->post('/forms/example-tenant/contact', ['name' => 'Test'])->assertStatus(404);

        $this->getJson('/api/v1/lead-forms')->assertStatus(404);
        $this->postJson('/api/v1/lead-forms', ['name' => 'Test'])->assertStatus(404);
        $this->getJson('/api/v1/lead-forms/1')->assertStatus(404);
        $this->putJson('/api/v1/lead-forms/1', ['name' => 'Test'])->assertStatus(404);
        $this->deleteJson('/api/v1/lead-forms/1')->assertStatus(404);
        $this->postJson('/api/v1/lead-forms/1/toggle-status')->assertStatus(404);

        $this->getJson('/api/v1/leads')->assertStatus(404);
        $this->getJson('/api/v1/leads/export')->assertStatus(404);
        $this->getJson('/api/v1/leads/1')->assertStatus(404);
        $this->putJson('/api/v1/leads/1', ['status' => 'contacted'])->assertStatus(404);

        $this->getJson('/api/v1/analytics/leads')->assertStatus(404);
        $this->getJson('/api/v1/lead-analytics')->assertStatus(404);
    }

    public function test_workspace_shell_omits_leads_and_retains_ai_visibility_analytics(): void
    {
        $html = view('welcome', ['cspNonce' => 'test-nonce'])->render();

        $this->assertStringNotContainsString('data-workspace-view="leads"', $html);
        $this->assertStringNotContainsString('data-view-target="leads"', $html);
        $this->assertStringNotContainsString('id="leadsMenuLink"', $html);
        $this->assertStringNotContainsString('geo-entry-leads.js', $html);
        $this->assertStringNotContainsString('geo-entry-lead-export.js', $html);

        $this->assertInstanceOf(AiVisibilityAnalyticsService::class, app(AiVisibilityAnalyticsService::class));

        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();
        $this->assertContains('api/v1/analytics/crawler-breakdown', $registered);
        $this->assertContains('api/v1/analytics/visit-logs', $registered);
    }
}
