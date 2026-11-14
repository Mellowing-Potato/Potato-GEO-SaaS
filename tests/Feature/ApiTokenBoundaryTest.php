<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Api\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiTokenBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function machine(array $scopes, string $role = 'admin'): User
    {
        Http::preventStrayRequests();
        $tenant = Tenant::create(['name' => 'Boundary', 'subdomain' => 'boundary', 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $this->withHeader('X-Tenant', 'boundary')->withToken($user->createToken('integration', $scopes)->plainTextToken);

        return $user;
    }

    public function test_article_read_token_cannot_write_projects_or_mint_tokens(): void
    {
        $this->machine(['articles:read']);
        $this->getJson('/api/v1/articles')->assertOk();
        $this->postJson('/api/v1/projects', ['name' => 'Forbidden'])->assertForbidden();
        $this->postJson('/api/v1/api-tokens', ['name' => 'Expanded', 'scopes' => ['articles:write']])->assertForbidden();
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        Http::assertNothingSent();
    }

    public function test_all_business_scopes_still_cannot_manage_credentials_or_team(): void
    {
        $this->machine(app(ApiTokenService::class)->getAvailableScopes());
        foreach (['api-tokens', 'ai-keys', 'team', 'platform/operations'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertForbidden();
        }
        $this->postJson('/api/v1/api-tokens', ['name' => 'Expanded', 'scopes' => ['articles:publish']])->assertForbidden();
    }

    public function test_project_write_token_can_create_but_not_mint_credentials(): void
    {
        $this->machine(['projects:read', 'projects:write']);
        $this->postJson('/api/v1/projects', ['name' => 'Allowed'])->assertCreated();
        $this->getJson('/api/v1/projects')->assertOk();
        $this->getJson('/api/v1/api-tokens')->assertForbidden();
        $this->assertDatabaseCount('projects', 1);
    }

    public function test_scopes_do_not_override_user_role_or_tenant(): void
    {
        $this->machine(['projects:read', 'projects:write'], 'viewer');
        // Legacy non-owner accounts are blocked before resource permissions.
        $this->postJson('/api/v1/projects', ['name' => 'Forbidden'])->assertStatus(409);
        Tenant::create(['name' => 'Other', 'subdomain' => 'other', 'plan' => 'basic', 'status' => 'active']);
        $this->withHeader('X-Tenant', 'other')->getJson('/api/v1/projects')->assertForbidden();
    }

    public function test_read_scopes_reject_writes_across_every_resource(): void
    {
        $this->machine(['articles:read', 'projects:read', 'materials:read', 'tasks:read', 'categories:read', 'knowledge:read', 'analytics:read']);
        foreach (['projects', 'articles', 'materials/titles', 'tasks', 'categories', 'knowledge-bases', 'skills/execute', 'visibility/runs', 'enterprise-knowledge/1/validate'] as $path) {
            $this->postJson('/api/v1/'.$path, [])->assertForbidden();
        }
    }

    public function test_correct_resource_read_scopes_are_usable_without_cookie(): void
    {
        $user = $this->machine(['projects:read', 'materials:read', 'tasks:read', 'categories:read', 'knowledge:read']);
        $project = new \App\Models\Project();
        $project->forceFill(['tenant_id' => $user->tenant_id, 'owner_id' => $user->id, 'name' => 'Knowledge scope', 'status' => 'active'])->save();
        $this->withHeader('X-Project-Id', $project->id);
        foreach (['projects', 'materials/titles', 'tasks', 'categories', 'knowledge-bases'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertOk();
        }
        Http::assertNothingSent();
    }

    public function test_new_route_without_scope_is_closed_to_machine_tokens(): void
    {
        $this->machine(['projects:read']);
        Route::middleware(['api', 'auth:sanctum'])->get('api/v1/future-sensitive-endpoint', fn () => ['secret' => 'not exposed']);
        $this->getJson('/api/v1/future-sensitive-endpoint')->assertForbidden();
    }

    public function test_read_token_cannot_replay_a_cached_write_response_for_the_same_user(): void
    {
        $user = $this->machine(['projects:write']);
        $headers = ['X-Idempotency-Key' => 'same-user-cached-write'];
        $this->postJson('/api/v1/projects', ['name' => 'Once'], $headers)->assertCreated();
        $read = $user->createToken('reader', ['projects:read']);
        $this->app['auth']->forgetGuards();
        $this->withToken($read->plainTextToken)->postJson('/api/v1/projects', ['name' => 'Once'], $headers)->assertForbidden();
        $this->assertDatabaseCount('projects', 1);
    }
}
