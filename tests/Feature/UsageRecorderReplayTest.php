<?php

namespace Tests\Feature;

use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Billing\AssistUsageRecorder;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Enums\SkillCategory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UsageRecorderReplayTest extends TestCase
{
    use RefreshDatabase;


    public function test_gateway_result_replay_reuses_one_log(): void
    {
        [$tenant, $user, $execution] = $this->setupSet();
        TenantContext::set($tenant);
        Http::preventStrayRequests();
        $result = new AiProviderCallResult(
            success: true, provider: 'openai', model: 'gpt-4o',
            promptTokens: 100, completionTokens: 50, totalTokens: 150,
            gateway: ['request_id' => 'gateway-replayed-1'],
        );

        $recorder = app(ModelUsageRecorder::class);
        $first = $recorder->record($execution, $result);
        $second = $recorder->record($execution, $result);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ModelCallLog::count());
        Http::assertNothingSent();
        TenantContext::forget();
    }
    public function test_direct_recorder_reentry_rejects_changed_usage(): void
    {
        [$tenant, $user, $execution] = $this->setupSet();
        TenantContext::set($tenant);
        Queue::fake();
        Http::preventStrayRequests();
        $result = new AiProviderCallResult(
            success: true, provider: 'openai', model: 'gpt-4o',
            promptTokens: 100, completionTokens: 50, totalTokens: 150,
            gateway: ['request_id' => 'gateway-direct-conflict-1'],
        );
        $recorder = app(ModelUsageRecorder::class);
        $recorder->record($execution, $result);
        $changed = new AiProviderCallResult(
            success: true, provider: 'openai', model: 'gpt-4o',
            promptTokens: 100, completionTokens: 51, totalTokens: 151,
            gateway: ['request_id' => 'gateway-direct-conflict-1'],
        );

        try {
            $recorder->record($execution, $changed);
            $this->fail('Changed usage must not reuse the saved request identity.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('conflicts', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(1, ModelCallLog::count());
        TenantContext::forget();
    }
    public function test_assist_gateway_replay_does_not_create_orphan_execution(): void
    {
        [$tenant, $user, $execution] = $this->setupSet();
        TenantContext::set($tenant);
        Http::preventStrayRequests();
        $result = new AiProviderCallResult(
            success: true, provider: 'openai', model: 'gpt-4o',
            promptTokens: 100, completionTokens: 50, totalTokens: 150,
            gateway: ['request_id' => 'gateway-assist-replayed-1'],
        );
        $charger = app(AssistUsageRecorder::class);

        $first = $charger->record($execution->project_id, $user->id, $result,
            'ai-assist-test', 'Assist test', SkillCategory::GeoResearch);
        $second = $charger->record($execution->project_id, $user->id, $result,
            'ai-assist-test', 'Assist test', SkillCategory::GeoResearch);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, ModelCallLog::count());
        $this->assertSame(2, SkillExecution::count()); // original fixture + one assist execution
        Http::assertNothingSent();
        TenantContext::forget();
    }
    public function test_assist_reentry_rejects_a_changed_gateway_receipt(): void
    {
        [$tenant, $user, $execution] = $this->setupSet();
        TenantContext::set($tenant);
        Queue::fake();
        Http::preventStrayRequests();
        $result = new AiProviderCallResult(
            success: true, provider: 'openai', model: 'gpt-4o',
            promptTokens: 100, completionTokens: 50, totalTokens: 150,
            gateway: ['request_id' => 'gateway-assist-conflict-1'],
        );
        $charger = app(AssistUsageRecorder::class);
        $first = $charger->record($execution->project_id, $user->id, $result,
            'ai-assist-test', 'Assist test', SkillCategory::GeoResearch);
        $changed = new AiProviderCallResult(
            success: true, provider: 'openai', model: 'gpt-4o',
            promptTokens: 100, completionTokens: 51, totalTokens: 151,
            gateway: ['request_id' => 'gateway-assist-conflict-1'],
        );
        $replayed = $charger->record($execution->project_id, $user->id, $changed,
            'ai-assist-test', 'Assist test', SkillCategory::GeoResearch);

        $this->assertNotNull($first);
        $this->assertNull($replayed);
        $this->assertSame(1, ModelCallLog::count());
        $this->assertSame(2, SkillExecution::count());
        Http::assertNothingSent();
        TenantContext::forget();
    }
    private function setupSet(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'debit-retry',
            'name' => 'Debit Retry Test',
            'plan' => 'points',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'email' => 'fixture-user-25@example.com',
            'phone' => '13800000016',
            'role' => 'admin',
        ]);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'name' => 'Retry Project',
                'industry' => 'technology',
                'status' => 'active',
                'owner_id' => $user->id,
            ]);

            $execution = SkillExecution::withoutGlobalScopes()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'skill_id' => 'test-skill',
                'skill_name' => 'Test Skill',
                'category' => 'test',
                'stage_index' => 0,
                'status' => 'completed',
                'execution_mode' => 'sync',
                'duration_seconds' => 1,
                'token_usage' => 150,
            ]);
        } finally {
            TenantContext::forget();
        }

        $result = new AiProviderCallResult(
            success: true,
            provider: 'openai',
            model: 'gpt-4o',
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
            durationSeconds: 1.5,
        );

        return [$tenant, $user, $execution, $result];
    }
}
