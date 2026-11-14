<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeamInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_writes_are_gone_and_cannot_create_reassign_or_remove_users(): void
    {
        $tenant = Tenant::create(['name' => 'Owner workspace', 'subdomain' => 'owner', 'status' => 'active', 'plan' => 'basic']);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        Sanctum::actingAs($owner, ['*']);
        $this->withHeader('X-Tenant', $tenant->subdomain);
        $this->postJson('/api/v1/team/invite', ['phone' => '13800000011', 'role' => 'admin'])->assertGone();
        $this->patchJson('/api/v1/team/'.$owner->id.'/role', ['role' => 'member'])->assertGone();
        $this->deleteJson('/api/v1/team/'.$owner->id)->assertGone();
        $this->assertSame(1, User::withoutGlobalScopes()->count());
        $this->assertSame('admin', $owner->fresh()->role);
    }

    public function test_assignment_list_only_returns_the_current_user_even_with_legacy_rows(): void
    {
        $tenant = Tenant::create(['name' => 'Owner workspace', 'subdomain' => 'owner', 'status' => 'active', 'plan' => 'basic']);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'member']);
        Sanctum::actingAs($owner, ['*']);
        $this->withHeader('X-Tenant', $tenant->subdomain)->getJson('/api/v1/team')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $owner->id)->assertJsonPath('data.0.is_self', true);
    }
}
