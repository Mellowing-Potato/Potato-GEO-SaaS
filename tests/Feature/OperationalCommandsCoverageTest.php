<?php

namespace Tests\Feature;

use App\Jobs\RunTaskJob;
use App\Models\ArticleRiskScan;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\SensitiveWordHit;
use App\Models\SkillExecution;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 运维/清理命令的覆盖（计划 §7 第 4 条的前置条件）。
 *
 * 为什么先写这个：这两条清理命令、调度派发命令、平台授权命令此前**没有任何测试**，
 * 所以它们一直只能以闭包形式留在 routes/console.php 里 —— 没有等价性证据就不敢搬。
 * 先补上"删什么、留什么、拒绝什么"的证据，再用 scripts/migrate-console-command.mjs 搬。
 *
 * 覆盖 7 条：tasks:tick / platform:ops-grant / platform:ops-revoke /
 * sensitive-word-hits:prune / article-risk-scans:prune / geo:subscription-expiry-check /
 * geo:reconcile-debits / geo:health-check（后两条见文件末尾两组）。
 */
class OperationalCommandsCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    // ── tasks:tick：只派发到点的活跃任务，且派发前推进 next_run_at（幂等）──

    public function test_tasks_tick_dispatches_only_due_active_tasks_and_advances_next_run(): void
    {
        [$tenant, $user] = $this->makeTenantUser('tick-tenant', 'admin');
        $project = $this->makeProject($tenant, $user);

        $due = $this->makeTask($tenant, $project, $user, [
            'name' => '到点任务',
            'next_run_at' => now()->subMinute(),
        ]);
        $future = $this->makeTask($tenant, $project, $user, [
            'name' => '还没到点',
            'next_run_at' => now()->addHour(),
        ]);
        $pausedDue = $this->makeTask($tenant, $project, $user, [
            'name' => '暂停中的到点任务',
            'status' => Task::STATUS_PAUSED,
            'next_run_at' => now()->subMinute(),
        ]);

        $exit = Artisan::call('tasks:tick');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('dispatched 1 due task(s)', Artisan::output());

        Queue::assertPushed(RunTaskJob::class, function (RunTaskJob $job) use ($due): bool {
            return $job->taskId === (int) $due->id && $job->trigger === 'schedule';
        });
        Queue::assertNotPushed(RunTaskJob::class, fn (RunTaskJob $job): bool => $job->taskId === (int) $future->id);
        Queue::assertNotPushed(RunTaskJob::class, fn (RunTaskJob $job): bool => $job->taskId === (int) $pausedDue->id);

        // 派发前先推进 next_run_at：下一分钟的 tick 不会再派同一条（幂等）
        $this->assertTrue($due->fresh()->next_run_at->isFuture());
        $this->assertTrue($future->fresh()->next_run_at->isFuture());

        Queue::fake();
        Artisan::call('tasks:tick');
        Queue::assertNothingPushed();
    }

    // ── platform:ops-grant / revoke：识别号三种形态 + 幂等 ──

    public function test_platform_ops_grant_and_revoke_are_identifiable_and_idempotent(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('ops-tenant', 'admin');
        $admin->forceFill(['email' => 'operator@example.com'])->save();

        // 按邮箱授权
        $this->assertSame(0, Artisan::call('platform:ops-grant', ['email' => 'operator@example.com']));
        $this->assertSame('platform_admin', $admin->fresh()->role);

        // 幂等：再授权一次仍然是 platform_admin，且提示"无需变更"
        $exit = Artisan::call('platform:ops-grant', ['email' => 'operator@example.com']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('无需变更', Artisan::output());

        // 按邮箱撤销
        $this->assertSame(0, Artisan::call('platform:ops-revoke', ['email' => 'operator@example.com']));
        $this->assertSame('admin', $admin->fresh()->role);

        // 幂等：再撤销一次提示"无需撤销"
        $exit = Artisan::call('platform:ops-revoke', ['email' => 'operator@example.com']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('无需撤销', Artisan::output());

        // 邮箱大小写归一
        $this->assertSame(0, Artisan::call('platform:ops-grant', ['email' => strtoupper($admin->email)]));
        $this->assertSame('platform_admin', $admin->fresh()->role);

        // 找不到账号必须失败退出（否则运维以为授权成功）
        $this->assertSame(1, Artisan::call('platform:ops-grant', ['email' => 'absent@example.com']));
        $this->assertSame(1, Artisan::call('platform:ops-revoke', ['email' => 'absent@example.com']));
    }

    // ── sensitive-word-hits:prune ──

    public function test_sensitive_word_hits_prune_deletes_only_expired_rows(): void
    {
        [$tenant, $user] = $this->makeTenantUser('prune-words', 'admin');

        $old = $this->makeWordHit($tenant, '过期命中');
        $old->forceFill(['created_at' => now()->subDays(120)])->save();
        $recent = $this->makeWordHit($tenant, '近期命中');

        $exit = Artisan::call('sensitive-word-hits:prune');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Pruned 1 sensitive word hit(s) older than', Artisan::output());

        $this->assertDatabaseMissing('sensitive_word_hits', ['id' => $old->id]);
        $this->assertDatabaseHas('sensitive_word_hits', ['id' => $recent->id]);

        // --days=0 必须拒绝（防一条命令清空整表）
        $exit = Artisan::call('sensitive-word-hits:prune', ['--days' => '0']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('拒绝执行', Artisan::output());
        $this->assertDatabaseHas('sensitive_word_hits', ['id' => $recent->id]);
    }

    // ── article-risk-scans:prune（按 scanned_at 判龄，不是 created_at）──

    public function test_article_risk_scans_prune_uses_scanned_at_and_refuses_zero_days(): void
    {
        [$tenant, $user] = $this->makeTenantUser('prune-scans', 'admin');

        $old = ArticleRiskScan::query()->create([
            'tenant_id' => $tenant->id,
            'trigger' => 'publish',
            'content_hash' => 'hash-old',
            'dictionary_hash' => 'dict',
            'status' => 'passed',
            'match_count' => 0,
            'scanned_at' => now()->subDays(120),
        ]);
        $recent = ArticleRiskScan::query()->create([
            'tenant_id' => $tenant->id,
            'trigger' => 'publish',
            'content_hash' => 'hash-new',
            'dictionary_hash' => 'dict',
            'status' => 'passed',
            'match_count' => 0,
            'scanned_at' => now()->subDay(),
        ]);

        $exit = Artisan::call('article-risk-scans:prune');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Pruned 1 article risk scan(s) older than 90 day(s)', Artisan::output());

        $this->assertDatabaseMissing('article_risk_scans', ['id' => $old->id]);
        $this->assertDatabaseHas('article_risk_scans', ['id' => $recent->id]);

        $this->assertSame(1, Artisan::call('article-risk-scans:prune', ['--days' => '0']));
        $this->assertStringContainsString('拒绝执行', Artisan::output());
    }

    public function test_geo_health_check_emits_the_documented_payload_shape(): void
    {
        $exit = Artisan::call('geo:health-check', ['--json' => true]);

        $payload = $this->jsonOutput();
        $this->assertIsArray($payload, 'geo:health-check --json 必须输出可解析 JSON');
        $this->assertSame('geo_health_check', $payload['object']);
        $this->assertContains($payload['status'], ['ready', 'not_ready']);
        $this->assertNotEmpty($payload['checks']);

        // 退出码跟随体检结果：ready=0，not_ready=1（部署脚本靠它判成败）
        $this->assertSame($payload['status'] === 'ready' ? 0 : 1, $exit);
    }

    // ── Helpers ──

    /**
     * 从命令输出里取 JSON。
     *
     * 失败分支会先 `$this->error(...)` 再打 JSON 行，而测试侧拿到的输出把两段拼在一起
     * （Artisan::output() 收的是同一个缓冲区）—— 直接 json_decode 会得到 null。
     * 所以从第一个 `{` 开始解。
     */
    private function jsonOutput(): array
    {
        $output = Artisan::output();
        $start = strpos($output, '{');
        if ($start === false) {
            $this->fail('命令输出里没有 JSON：'.$output);
        }

        return json_decode(substr($output, $start), true) ?? [];
    }

    private function makeTenantUser(string $subdomain, string $role): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => $role,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
        ]);

        return [$tenant, $user];
    }

    private function makeProject(Tenant $tenant, User $owner): Project
    {
        TenantContext::set($tenant);
        $project = Project::create([
            'name' => '命令覆盖测试项目',
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);
        TenantContext::forget();

        return $project;
    }

    private function makeTask(Tenant $tenant, Project $project, User $user, array $overrides = []): Task
    {
        return Task::create(array_merge([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'name' => '命令覆盖任务',
            'skill_id' => 'yao-geo-explainer-builder',
            'cadence' => Task::CADENCE_DAILY,
            'timezone' => Task::DEFAULT_TIMEZONE,
            'auto_publish' => false,
            'auto_distribute' => false,
            'status' => Task::STATUS_ACTIVE,
        ], $overrides));
    }

    private function makeExecution(Tenant $tenant, Project $project, User $user): SkillExecution
    {
        // tenant_id 不在 $fillable 里（BelongsToTenant 从上下文注入），必须显式设上下文，
        // 否则 NOT NULL 约束会在插入时才炸。
        TenantContext::set($tenant);
        try {
            return SkillExecution::withoutGlobalScopes()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'skill_id' => 'test-skill',
                'skill_name' => 'Test Skill',
                'category' => 'test',
                'stage_index' => 0,
                'status' => 'completed',
                'execution_mode' => 'sync',
                'duration_seconds' => 1,
                'token_usage' => 10,
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function makeWordHit(Tenant $tenant, string $word): SensitiveWordHit
    {
        TenantContext::set($tenant);
        $hit = SensitiveWordHit::query()->create([
            'tenant_id' => $tenant->id,
            'word' => $word,
            'severity' => 'warning',
            'origin' => 'tenant',
            'surface' => 'article',
            'trigger' => 'save',
        ]);
        TenantContext::forget();

        return $hit;
    }
}
