<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Article;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 账户注销（计划 §9.5；用户口径 2026-09-21：「账户可以注销，但是要提交申请注销，
 * 15 天以内再次登陆就等于恢复账户」）。
 *
 * 这组测试守四件事：
 *  1. 申请 = 进冷静期 + 全部登录被撤销 + 写审计（不能"申请了还继续能用"）；
 *  2. 冷静期内登录 = 恢复账户（这是唯一的撤销入口，因为申请时登录已被清空）；
 *  3. 期满清理默认只演练、--apply 才真清；清的是身份信息与内容，跟钱有关的流水照留；
 *  4. 二次确认是硬要求（文案 + 密码），一次误点走不到注销。
 */
class AccountDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = 'acct_deletion_user_001';
    private const USER_ID_ALT = 'acct_pending_user_001';

    public function test_requesting_deletion_starts_the_grace_period_and_signs_everyone_out(): void
    {
        [$tenant, $user] = $this->account();
        $user->forceFill(['password' => Hash::make('correct-horse')])->save();
        $user->createToken('geo-saas-token');

        $response = $this->forTenant($tenant)
            ->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/deletion', [
                'confirm_text' => '注销账户',
                'password' => 'correct-horse',
                'reason' => '不再使用',
            ])
            ->assertOk();

        $this->assertSame(15, $response->json('data.grace_days'));
        $this->assertStringContainsString('15 天内重新登录即可恢复账户', $response->json('message'));

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->deletion_requested_at);
        $this->assertNotNull($fresh->deletion_purge_at);
        // 冷静期就是 15 天（口径来自 config，断言到天）
        $this->assertSame(15, (int) $fresh->deletion_requested_at->diffInDays($fresh->deletion_purge_at));

        // 全部登录被撤销：申请之后不存在"还能继续用"的窗口
        $this->assertSame(0, $fresh->tokens()->count());

        // 审计：注销是敏感操作，事后必须答得出谁在什么时候申请
        $this->assertTrue(
            AdminActivityLog::query()->where('action', 'account.deletion_requested')->where('actor_id', $user->id)->exists(),
            '注销申请没有写审计',
        );
    }

    public function test_logging_in_within_the_grace_period_restores_the_account(): void
    {
        [$tenant, $user] = $this->account();

        $this->forTenant($tenant)->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/deletion', ['confirm_text' => '注销账户'])
            ->assertOk();

        // 冷静期内任意一次成功登录 = 恢复账户
        $login = $this->sessionLogin();
        $this->assertTrue($login->json('account_restored'), '登录没有恢复账户');
        $this->assertNotNull($login->json('account_restored_at'));

        $fresh = $user->fresh();
        $this->assertNull($fresh->deletion_requested_at);
        $this->assertNull($fresh->deletion_purge_at);
        $this->assertSame('', (string) $fresh->deletion_reason);

        // 恢复也要留痕（谁在什么时候撤销的）
        $this->assertTrue(
            AdminActivityLog::query()->where('action', 'account.deletion_cancelled')->where('actor_id', $user->id)->exists(),
            '账户恢复没有写审计',
        );

        // 恢复之后的普通登录不再报"已恢复"（这个字段只在真的撤销过一次时为真）
        $this->assertFalse($this->sessionLogin()->json('account_restored'));
    }

    public function test_purge_command_only_clears_expired_accounts_and_keeps_call_logs(): void
    {
        [$tenant, $user] = $this->account();

        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $user->id,
                'name' => '注销账户的项目',
                'current_stage' => 0,
                'progress' => 0,
            ]);
            $article = Article::query()->create([
                'project_id' => $project->id,
                'author_id' => $user->id,
                'title' => '注销账户的文章',
                'slug' => 'deleted-account-post',
                'body_markdown' => '# 正文',
                'status' => 'draft',
                'source_type' => 'manual',
                'metadata' => [],
            ]);
            $log = ModelCallLog::query()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'provider' => 'dashscope',
                'model' => 'vision',
                'status' => 'completed',
                'prompt_tokens' => 1,
                'completion_tokens' => 2,
                'total_tokens' => 3,
                'duration_seconds' => 1.0,
                'metadata' => ['points_deducted' => 7, 'points_deduction_status' => 'charged'],
            ]);
        } finally {
            TenantContext::forget();
        }

        // 第二个账户：也申请了注销，但**还没到期**
        $pending = User::factory()->create([
            'tenant_id' => Tenant::query()->create([
                'subdomain' => 'pending-'.$tenant->id,
                'name' => 'PENDING',
                'plan' => 'basic',
                'status' => 'active',
            ])->id,
            'email' => 'pending@example.com',
            'role' => 'admin',
            'password' => null,
        ]);

        $this->forTenant($tenant)->actingAs($user, 'sanctum')->postJson('/api/v1/account/deletion', ['confirm_text' => '注销账户'])->assertOk();
        $this->forTenant($pending->tenant)->actingAs($pending, 'sanctum')->postJson('/api/v1/account/deletion', ['confirm_text' => '注销账户'])->assertOk();

        // 把主账户的冷静期拨到 16 天前（冷静期 15 天）
        $user->forceFill([
            'deletion_requested_at' => now()->subDays(16),
            'deletion_purge_at' => now()->subDay(),
        ])->save();

        // 1. 默认只演练
        $this->artisan('geo:account-purge --json')
            ->expectsOutputToContain('"mode":"dry-run"')
            ->assertSuccessful();
        $this->assertNotNull($user->fresh()->phone, '演练不该改数据');

        // 2. --apply 真清理
        $this->artisan('geo:account-purge --apply --json')
            ->expectsOutputToContain('"mode":"apply"')
            ->assertSuccessful();

        $purged = $user->fresh();
        $this->assertNull($purged->phone, '手机号没清');
        $this->assertNull($purged->email, '登录邮箱没清');
        $this->assertNull($purged->password);
        $this->assertSame('已注销账户', $purged->name);
        $this->assertNotNull($purged->deletion_purged_at);

        // 内容真删（租户里只剩这一个账户）
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('articles', ['id' => $article->id]);

        // 跟钱有关的一条都不能少
        $kept = ModelCallLog::withoutGlobalScopes()->find($log->id);
        $this->assertNotNull($kept, '账户注销把调用流水删了 —— 跟钱有关的记录必须留下');
        $this->assertSame(7, (int) data_get($kept->metadata, 'points_deducted'));
        $this->assertSame('注销账户的项目', data_get($kept->metadata, 'project_name_snapshot'));

        // 没到期的账户原样不动
        $this->assertNotNull($pending->fresh()->deletion_purge_at);
        $this->assertNull($pending->fresh()->deletion_purged_at, '没到期的账户被提前清了');
        $this->assertSame('pending@example.com', $pending->fresh()->email, '没到期的账户身份被提前抹了');

        // 3. 清理也要留痕
        $this->assertTrue(
            AdminActivityLog::query()->where('action', 'account.deletion_purged')->where('target_id', $user->id)->exists(),
            '执行清理没有写审计',
        );
    }

    public function test_deletion_request_requires_the_typed_confirmation_and_the_password(): void
    {
        [$tenant, $user] = $this->account();
        $user->forceFill(['password' => Hash::make('correct-horse')])->save();

        // 1. 没打确认文案
        $this->forTenant($tenant)->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/deletion', ['password' => 'correct-horse'])
            ->assertStatus(422);

        // 2. 文案不对
        $this->forTenant($tenant)->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/deletion', ['confirm_text' => '注销', 'password' => 'correct-horse'])
            ->assertStatus(422);

        // 3. 密码错
        $this->forTenant($tenant)->actingAs($user, 'sanctum')
            ->postJson('/api/v1/account/deletion', ['confirm_text' => '注销账户', 'password' => 'wrong'])
            ->assertStatus(422);

        // 都没写进库
        $this->assertNull($user->fresh()->deletion_requested_at);
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }

    /** @return array{0:Tenant,1:User} */
    private function account(): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => 'deletion-'.substr(md5((string) random_int(1, 1_000_000)), 0, 6),
            'name' => 'DELETION',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'delete@example.com',
            'phone' => '13800000003',
            'phone_verified_at' => now(),
            'role' => 'admin',
            // 默认不设密码：客户里这两种登录方式都有（验证码登录的账号本来就没有密码），
            // "要不要验密码"这条分支由 test_deletion_request_requires_the_typed_confirmation_and_the_password 单独覆盖。
            'password' => null,
        ]);

        return [$tenant, $user];
    }

    /** Login with local credentials restores an account during its grace period. */
    private function sessionLogin()
    {
        $user = User::withoutGlobalScopes()->where('email', 'delete@example.com')->firstOrFail();
        $user->forceFill(['password' => 'test-password-1'])->save();
        Http::preventStrayRequests();
        $this->app['auth']->forgetGuards();
        return $this->withHeaders(['Authorization' => '', 'Cookie' => ''])
            ->postJson('/api/v1/account/login', ['email' => $user->email, 'password' => 'test-password-1'])->assertOk();
    }
}
