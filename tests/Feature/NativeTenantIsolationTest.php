<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NativeTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $email, int $status = 200): array
    {
        Http::preventStrayRequests();
        if (!User::withoutGlobalScopes()->where('email', $email)->exists()) {
            app(\App\Services\Auth\LocalAccounts::class)->create($email, 'test-password-1', 'Local User');
        }
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => '', 'Cookie' => '', 'X-Tenant' => '']);
        $response = $this->postJson('/api/v1/account/login', ['email' => $email, 'password' => 'test-password-1'])->assertStatus($status);
        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'geo_session');
        return [...$response->json(), 'test_cookie' => $cookie?->getValue()];
    }

    private function useSession(array $session): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$session['token'],
            'Cookie' => 'geo_session='.$session['test_cookie'], 'X-Tenant' => $session['tenant']['subdomain']]);
    }

    public function test_independent_native_logins_cannot_list_read_change_or_delete_each_others_projects(): void
    {
        $a = $this->loginAs('a@example.com');
        $b = $this->loginAs('b@example.com');
        $this->assertNotSame($a['user']['id'], $b['user']['id']);
        $this->assertNotSame($a['tenant']['id'], $b['tenant']['id']);
        TenantContext::set(Tenant::findOrFail($a['tenant']['id']));
        try {
            $project = Project::create(['name' => 'Private project A', 'owner_id' => $a['user']['id'], 'status' => 'active']);
        } finally {
            TenantContext::forget();
        }

        $this->useSession($a);
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.0.id', $project->id);
        $this->useSession($b);
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/projects/'.$project->id)->assertNotFound();
        $this->patchJson('/api/v1/projects/'.$project->id, ['name' => 'Unauthorized change'])->assertNotFound();
        $this->deleteJson('/api/v1/projects/'.$project->id)->assertNotFound();
        $this->withHeader('X-Tenant', $a['tenant']['subdomain']);
        $this->getJson('/api/v1/projects')->assertForbidden();
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Private project A', 'deleted_at' => null]);

        $this->useSession($b);
        $this->withToken($a['token'])->withHeader('X-Tenant', $a['tenant']['subdomain']);
        $this->getJson('/api/v1/projects')->assertUnauthorized();
    }

    public function test_native_login_rejects_an_existing_team_membership(): void
    {
        $a = $this->loginAs('a@example.com');
        $member = User::create([
            'tenant_id' => $a['tenant']['id'], 'name' => 'Invited B', 'role' => 'member',
            'email' => 'b@example.com', 'password' => 'test-password-1',
        ]);
        $this->loginAs('b@example.com', 409);
        $this->useSession($a);
        $this->getJson('/api/v1/projects')->assertStatus(409);
        $this->assertSame(1, Tenant::count());
    }

    public function test_disabled_invitation_cannot_capture_a_new_users_workspace(): void
    {
        $a = $this->loginAs('a@example.com');
        $this->useSession($a);
        $this->postJson('/api/v1/team/invite', ['phone' => 'b@example.com', 'role' => 'admin'])->assertGone();
        $b = $this->loginAs('b@example.com');
        $this->assertNotSame($a['tenant']['id'], $b['tenant']['id']);
        $this->assertSame('admin', $b['user']['role']);
        $this->useSession($b);
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_duplicate_email_registration_cannot_replace_an_existing_account(): void
    {
        $a = $this->loginAs('a@example.com');
        \Illuminate\Support\Facades\DB::table('instance_settings')->where('id', 1)->update(['registration_enabled' => true]);
        $this->postJson('/api/v1/account/register', ['email' => 'A@example.com', 'name' => 'Impersonator',
            'password' => 'test-password-2', 'password_confirmation' => 'test-password-2'])->assertUnprocessable();
        $user = User::withoutGlobalScopes()->findOrFail($a['user']['id']);
        $this->assertSame('a@example.com', $user->email);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('test-password-1', $user->password));
        $this->assertSame($a['tenant']['id'], $user->tenant_id);
        $this->assertSame(1, User::withoutGlobalScopes()->count());
    }
}
