<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSkillJob;
use App\Jobs\RunGeoAnalysisJob;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Enums\SkillCategory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 批 D · 卡死执行清道夫（geo:reap-stuck-executions）。
 *
 * 判死三条件各一组用例：running 超时 / 队列里没有对应 job / 没有新的模型调用。
 * 其中「队列里有 job」那两条**必须真的 dispatch 一次**，不能自己拼 payload ——
 * payload 里的 executionId 是 PHP serialize() 出来的形态，手写一份假的等于把
 * 命令的匹配规则抄进测试自己校自己，Laravel 换了序列化方式也照样全绿。
 */
class StuckExecutionReaperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml 把 QUEUE_CONNECTION 钉成 sync，而本命令只在 database 驱动下工作。
        config(['queue.default' => 'database']);
    }

    public function test_reaps_running_execution_with_no_job_and_no_recent_model_call(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();
        $execution = $this->execution($tenant, $user, $project, [
            'status' => 'running',
            'execution_mode' => 'sync',
        ], stuckMinutes: 30);

        $this->artisan('geo:reap-stuck-executions')
            ->expectsOutputToContain('Reaped 1 stuck execution(s)')
            ->assertExitCode(0);

        $reaped = SkillExecution::withoutGlobalScopes()->find($execution->id);
        $this->assertSame('failed', $reaped->status);
        $this->assertStringContainsString('超过 15 分钟没有进展', $reaped->error_message);
        // 客户看得到的这句话不许带这几个字符（见命令类 failureMessage() 注释）。
        $this->assertStringNotContainsString('/', $reaped->error_message);
        $this->assertStringNotContainsString('\\', $reaped->error_message);
        $this->assertStringNotContainsString("\n", $reaped->error_message);
    }

    public function test_prepared_model_attempt_is_held_for_reconciliation_instead_of_retry(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();
        $execution = $this->execution($tenant, $user, $project, [
            'execution_mode' => 'sync',
            'billing_pending_result' => [
                'version' => 1, 'kind' => 'model_attempt_prepared',
                'gateway' => ['attribution' => ['client_request_id' => 'original-attempt']],
            ],
        ], stuckMinutes: 30);

        $this->artisan('geo:reap-stuck-executions')->assertExitCode(0);

        $held = SkillExecution::withoutGlobalScopes()->findOrFail($execution->id);
        $this->assertSame('billing_pending', $held->status);
        $this->assertSame('model_attempt_prepared', data_get($held->billing_pending_result, 'kind'));
        $this->assertStringContainsString('请勿重新提交', $held->error_message);
    }

    public function test_leaves_execution_alone_when_its_job_is_still_in_the_queue(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();

        $skillExecution = $this->execution($tenant, $user, $project, [
            'skill_id' => 'yao-geo-intent-miner',
        ], stuckMinutes: 30);
        $analysisExecution = $this->execution($tenant, $user, $project, [
            'skill_id' => 'yao-geo-panorama-audit',
        ], stuckMinutes: 30);

        // 真投一次，让 payload 由框架自己序列化。
        ExecuteSkillJob::dispatch($skillExecution->id, ['skill_id' => 'yao-geo-intent-miner'], $tenant->id);
        RunGeoAnalysisJob::dispatch($analysisExecution->id, $project->id, $user->id, [], $tenant->id);
        $this->assertSame(2, DB::table('jobs')->count());

        $this->artisan('geo:reap-stuck-executions')
            ->expectsOutputToContain('Reaped 0 stuck execution(s)')
            ->expectsOutputToContain('Skipped 2 still queued')
            ->assertExitCode(0);

        $this->assertSame('running', SkillExecution::withoutGlobalScopes()->find($skillExecution->id)->status);
        $this->assertSame('running', SkillExecution::withoutGlobalScopes()->find($analysisExecution->id)->status);
    }

    public function test_a_job_for_another_execution_does_not_protect_this_one(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();
        $execution = $this->execution($tenant, $user, $project, ['id' => 3], stuckMinutes: 30);

        // 33 与 3 在 payload 里只差一个字符，别让它护住 3 号。
        ExecuteSkillJob::dispatch(33, [], $tenant->id);

        $this->artisan('geo:reap-stuck-executions')->assertExitCode(0);

        $this->assertSame('failed', SkillExecution::withoutGlobalScopes()->find(3)->status);
    }

    public function test_leaves_execution_alone_when_model_call_log_is_recent(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();
        // 同步执行路没有 job 行，判它死活只能看模型调用有没有在动。
        $execution = $this->execution($tenant, $user, $project, ['execution_mode' => 'sync'], stuckMinutes: 30);

        ModelCallLog::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'skill_execution_id' => $execution->id,
            'provider' => 'custom',
            'model' => 'text',
            'status' => 'success',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
        ]);

        $this->artisan('geo:reap-stuck-executions')
            ->expectsOutputToContain('Skipped 0 still queued, 1 still calling the model.')
            ->assertExitCode(0);

        $this->assertSame('running', SkillExecution::withoutGlobalScopes()->find($execution->id)->status);
    }

    public function test_old_model_call_log_does_not_protect_a_dead_execution(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();
        $execution = $this->execution($tenant, $user, $project, ['execution_mode' => 'sync'], stuckMinutes: 30);

        $log = ModelCallLog::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'skill_execution_id' => $execution->id,
            'provider' => 'custom',
            'model' => 'text',
            'status' => 'success',
            'prompt_tokens' => 10,
            'completion_tokens' => 20,
            'total_tokens' => 30,
        ]);
        ModelCallLog::withoutGlobalScopes()->where('id', $log->id)->update(['created_at' => now()->subMinutes(29)]);

        $this->artisan('geo:reap-stuck-executions')->assertExitCode(0);

        $this->assertSame('failed', SkillExecution::withoutGlobalScopes()->find($execution->id)->status);
    }

    public function test_leaves_fresh_running_execution_alone(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();
        $execution = $this->execution($tenant, $user, $project, [], stuckMinutes: 14);

        $this->artisan('geo:reap-stuck-executions')
            ->expectsOutputToContain('No running skill execution older than 15m.')
            ->assertExitCode(0);

        $this->assertSame('running', SkillExecution::withoutGlobalScopes()->find($execution->id)->status);
    }

    public function test_only_touches_running_executions(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();

        $untouched = [];
        foreach (['pending', 'completed', 'failed', 'cancelled'] as $status) {
            $untouched[$status] = $this->execution($tenant, $user, $project, ['status' => $status], stuckMinutes: 30);
        }

        $this->artisan('geo:reap-stuck-executions')
            ->expectsOutputToContain('No running skill execution older than 15m.')
            ->assertExitCode(0);

        foreach ($untouched as $status => $execution) {
            $this->assertSame($status, SkillExecution::withoutGlobalScopes()->find($execution->id)->status);
        }
    }

    public function test_reaps_across_tenants(): void
    {
        [$tenantA, $userA, $projectA] = $this->tenantProjectAdmin('reaper-a');
        [$tenantB, $userB, $projectB] = $this->tenantProjectAdmin('reaper-b');

        $a = $this->execution($tenantA, $userA, $projectA, [], stuckMinutes: 30);
        $b = $this->execution($tenantB, $userB, $projectB, [], stuckMinutes: 30);

        // 调度器里没有 TenantContext，这里显式确认「没有上下文」也扫得到两个租户。
        TenantContext::forget();

        $this->artisan('geo:reap-stuck-executions')
            ->expectsOutputToContain('Reaped 2 stuck execution(s)')
            ->assertExitCode(0);

        $this->assertSame('failed', SkillExecution::withoutGlobalScopes()->find($a->id)->status);
        $this->assertSame('failed', SkillExecution::withoutGlobalScopes()->find($b->id)->status);
    }

    public function test_reaps_nothing_when_queue_driver_is_not_database(): void
    {
        config(['queue.default' => 'sync']);

        [$tenant, $user, $project] = $this->tenantProjectAdmin();
        $execution = $this->execution($tenant, $user, $project, [], stuckMinutes: 30);

        $this->artisan('geo:reap-stuck-executions')
            ->expectsOutputToContain('Reaped nothing')
            ->assertExitCode(0);

        $this->assertSame('running', SkillExecution::withoutGlobalScopes()->find($execution->id)->status);
    }

    public function test_dry_run_reports_but_writes_nothing(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();
        $execution = $this->execution($tenant, $user, $project, ['skill_id' => 'yao-geo-intent-miner'], stuckMinutes: 30);

        $this->artisan('geo:reap-stuck-executions', ['--dry-run' => true])
            ->expectsOutputToContain("[dry-run] #{$execution->id} yao-geo-intent-miner")
            ->expectsOutputToContain('Would reap 1 stuck execution(s)')
            ->assertExitCode(0);

        $this->assertSame('running', SkillExecution::withoutGlobalScopes()->find($execution->id)->status);
    }

    public function test_minutes_option_moves_the_threshold(): void
    {
        [$tenant, $user, $project] = $this->tenantProjectAdmin();
        $execution = $this->execution($tenant, $user, $project, [], stuckMinutes: 30);

        $this->artisan('geo:reap-stuck-executions', ['--minutes' => 60])
            ->expectsOutputToContain('No running skill execution older than 60m.')
            ->assertExitCode(0);
        $this->assertSame('running', SkillExecution::withoutGlobalScopes()->find($execution->id)->status);

        $this->artisan('geo:reap-stuck-executions', ['--minutes' => 20])->assertExitCode(0);
        $reaped = SkillExecution::withoutGlobalScopes()->find($execution->id);
        $this->assertSame('failed', $reaped->status);
        $this->assertStringContainsString('超过 20 分钟没有进展', $reaped->error_message);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function execution(Tenant $tenant, User $user, Project $project, array $overrides, int $stuckMinutes): SkillExecution
    {
        TenantContext::set($tenant);
        try {
            $execution = new SkillExecution;
            $execution->forceFill(array_merge([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'skill_id' => 'yao-geo-panorama-audit',
                'skill_name' => '全景诊断',
                'category' => SkillCategory::GeoStrategy->value,
                'stage_index' => 1,
                'status' => 'running',
                'execution_mode' => 'async',
                'input_data' => [],
            ], $overrides))->save();
        } finally {
            TenantContext::forget();
        }

        // create() 自己盖时间戳，只能事后把 updated_at 推回过去。
        // 显式传 updated_at 时 Eloquent 不会再覆盖它（addUpdatedAtColumn 用的是 array_merge）。
        SkillExecution::withoutGlobalScopes()
            ->where('id', $execution->id)
            ->update(['updated_at' => now()->subMinutes($stuckMinutes)]);

        return SkillExecution::withoutGlobalScopes()->find($execution->id);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Project}
     */
    private function tenantProjectAdmin(string $subdomain = 'reaper'): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $user->id,
                'name' => 'Reaper Project',
                'brand_name' => 'Acme',
                'brand_url' => 'https://acme.example.test',
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        return [$tenant, $user, $project];
    }
}
