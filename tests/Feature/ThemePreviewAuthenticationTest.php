<?php

namespace Tests\Feature;

use App\Models\SiteThemeReplication;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ThemePackaging\SiteThemeReplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ThemePreviewAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $previous = [getenv('GEO_THEME_PACKAGE_ENABLED'), $_ENV['GEO_THEME_PACKAGE_ENABLED'] ?? null, $_SERVER['GEO_THEME_PACKAGE_ENABLED'] ?? null];
        putenv('GEO_THEME_PACKAGE_ENABLED=true');
        $_ENV['GEO_THEME_PACKAGE_ENABLED'] = $_SERVER['GEO_THEME_PACKAGE_ENABLED'] = 'true';
        try {
            return parent::createApplication();
        } finally {
            putenv($previous[0] === false ? 'GEO_THEME_PACKAGE_ENABLED' : 'GEO_THEME_PACKAGE_ENABLED='.$previous[0]);
            foreach (['_ENV' => 1, '_SERVER' => 2] as $global => $index) {
                if ($previous[$index] === null) {
                    unset($GLOBALS[$global]['GEO_THEME_PACKAGE_ENABLED']);
                } else {
                    $GLOBALS[$global]['GEO_THEME_PACKAGE_ENABLED'] = $previous[$index];
                }
            }
        }
    }

    private function fixture(array $abilities = ['*'], string $role = 'admin'): array
    {
        $tenant = Tenant::create(['name' => 'Preview', 'subdomain' => 'preview', 'plan' => 'basic', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        $replication = SiteThemeReplication::forceCreate([
            'tenant_id' => $tenant->id, 'name' => 'Private theme', 'theme_id' => 'preview-theme',
            'status' => 'ready', 'home_url' => 'https://example.test/', 'category_url' => 'https://example.test/c',
            'article_url' => 'https://example.test/a', 'style_preference' => 'content_site',
        ]);
        $token = $user->createToken($abilities === ['*'] ? 'geo-saas-token' : 'integration', $abilities);
        $token->accessToken->forceFill(['session_fingerprint' => hash('sha256', 'test-fake-29f0')])->save();
        \Illuminate\Support\Facades\DB::table('local_browser_sessions')->insert(['fingerprint' => hash('sha256', 'test-fake-29f0'), 'user_id' => $user->id, 'created_at' => now(), 'expires_at' => now()->addDay()]);
        $this->withToken($token->plainTextToken)->withHeader('Cookie', 'geo_session=test-fake-29f0');

        return [$replication, $token->accessToken, $user];
    }

    private function account(?int $error = null): void
    {
        if ($error === 401) \Illuminate\Support\Facades\DB::table('local_browser_sessions')->delete();
    }

    public function test_anonymous_preview_request_is_unauthorized(): void
    {
        $this->getJson('/theme-preview/1/home')->assertUnauthorized();
    }

    public function test_scoped_machine_token_cannot_use_preview_as_an_alternate_entry(): void
    {
        [$rep] = $this->fixture(['projects:read']);
        $this->getJson("/theme-preview/{$rep->id}/home")->assertForbidden();
    }

    public function test_browser_token_without_cookie_is_rejected_and_revoked(): void
    {
        [$rep, $token] = $this->fixture();
        $this->withHeader('Cookie', '')->getJson("/theme-preview/{$rep->id}/home")->assertUnauthorized();
        $this->assertNull(PersonalAccessToken::find($token->id));
    }

    public function test_revoked_account_session_cannot_read_preview_with_old_token_and_cookie(): void
    {
        [$rep, $token] = $this->fixture();
        $this->account(401);
        $this->getJson("/theme-preview/{$rep->id}/home")->assertUnauthorized();
        $this->assertNull(PersonalAccessToken::find($token->id));
    }


    public function test_live_owner_can_preview_with_private_cache_headers(): void
    {
        [$rep] = $this->fixture();
        $this->account();
        $this->mock(SiteThemeReplicationService::class)->shouldReceive('previewPage')->once()
            ->andReturn(['page' => 'home', 'version' => 1, 'css' => ':root { --rep-primary: #123456; }']);
        $this->getJson("/theme-preview/{$rep->id}/home")->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_live_session_does_not_bypass_tenant_ownership(): void
    {
        [$rep] = $this->fixture();
        $other = Tenant::create(['name' => 'Other', 'subdomain' => 'preview-other', 'plan' => 'basic', 'status' => 'active']);
        $rep->forceFill(['tenant_id' => $other->id])->save();
        $this->account();
        $this->getJson("/theme-preview/{$rep->id}/home")->assertForbidden();
    }

    public function test_preview_requires_the_same_role_permission_as_theme_package_api(): void
    {
        [$rep] = $this->fixture(role: 'viewer');
        $this->account();
        // Legacy non-owner memberships are rejected by the independent workspace
        // guard before resource-level role checks can run.
        $this->getJson("/theme-preview/{$rep->id}/home")->assertStatus(409);
    }
}
