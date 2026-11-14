<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\ModelCallLog;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\SkillExecution;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\UrlImport;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 隐私生命周期盘点（计划 §9.5、总台账 S11「技术盘点可先做，规则需确认」）。
 *
 * 这组用例要证明三件事：
 *   1. 盘点**看得见残留**：删掉一个项目之后，哪些表还有行、有多少行，命令说得出来；
 *   2. 盘点**什么都不改**：跑完命令，全库行数与磁盘文件一字未动（它不是清理工具）；
 *   3. 孤儿报表目录认得出：文件还在、执行行已经没了的那种目录，必须点名，
 *      而且要点明"没有租户归属"这个事实。
 *
 * 顺带把当前保留口径钉死在断言里：`model_call_logs` 刻意保留（与钱有关）、
 * `tasks` / `url_imports` 未被级联 —— 将来谁改了删除行为，这两条会先红，
 * 而不是等客户发现"我删了项目怎么任务还在跑"。
 */
class PrivacyLifecycleAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        // The orphan count must describe this fixture, not files left by other tests.
        Storage::fake('reports');

        // 删项目现在会先撤外站内容（§9.5）：这一组测试里既有"已发到外站的文章"也有真渠道配置，
        // 不挡掉 HTTP 就会在测试里打真外站（本地是 DNS 快速失败，CI 里可能真发出去）。
        \Illuminate\Support\Facades\Http::fake();

        $this->tenant = Tenant::query()->create([
            'subdomain' => 'privacy-audit',
            'name' => 'PRIVACY',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',

            'phone' => '13800000020',
        ]);

        TenantContext::set($this->tenant);
        $this->project = Project::query()->create([
            'owner_id' => $this->user->id,
            'name' => '隐私盘点项目',
            'brand_name' => 'Acme',
            'brand_url' => 'https://acme.example.test',
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_it_reports_the_tenant_scope_and_changes_nothing(): void
    {
        $this->seedProjectContent();

        $before = $this->allRowCounts();

        $output = $this->runAudit(['--tenant' => (string) $this->tenant->id, '--json' => true]);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('tenant', $report['scope']);
        $this->assertSame('ok', $report['tenant']['status']);

        $rows = collect($report['tenant']['tables_with_rows'])->keyBy('table');
        $this->assertSame(1, $rows['projects']['rows'] ?? null, '项目行还在（软删不算消失）');
        $this->assertSame(1, $rows['articles']['rows'] ?? null);
        $this->assertSame(1, $rows['skill_executions']['rows'] ?? null);
        $this->assertSame(1, $rows['tasks']['rows'] ?? null);
        $this->assertSame(1, $rows['url_imports']['rows'] ?? null);
        $this->assertSame(1, $rows['model_call_logs']['rows'] ?? null);

        // 账户删除入口：当前不存在 —— 这条是给产品看的事实，不是"没问题"。
        $this->assertFalse($report['tenant']['account_deletion']['entry_point_exists']);
        $this->assertStringContainsString('没有账户/租户删除入口', $report['tenant']['account_deletion']['note']);

        // 只读证明：全库每张表的行数一字未动
        $this->assertSame($before, $this->allRowCounts(), '盘点命令动了数据 —— 它必须是只读的');
    }

    public function test_it_reports_what_is_left_after_a_project_is_deleted(): void
    {
        $this->seedProjectContent();

        Sanctum::actingAs($this->user, ['*']);
        $this->forTenant($this->tenant)
            ->deleteJson("/api/v1/projects/{$this->project->id}")
            ->assertNoContent();

        $report = json_decode(
            $this->runAudit(['--project' => (string) $this->project->id, '--json' => true]),
            true, 512, JSON_THROW_ON_ERROR,
        );

        $this->assertSame('ok', $report['project']['status']);
        $this->assertTrue($report['project']['project']['deleted']);
        $this->assertNotNull($report['project']['project']['deleted_at']);

        $leftovers = collect($report['project']['leftovers'])->keyBy('table');

        // 2026-09-21 口径：删除 = 进垃圾桶（7 天内可恢复）→ 内容**原地保留**，
        // 所以这三张表现在有行不是"删得不干净"，而是"还能恢复"。盘点要能分清这两件事。
        $this->assertSame(1, $leftovers['articles']['rows'] ?? null, '桶里的文章应当保留（否则无法恢复）');
        $this->assertSame(1, $leftovers['skill_executions']['rows'] ?? null, '桶里的执行记录应当保留');
        $this->assertTrue($report['project']['trash']['in_trash']);
        $this->assertSame(7, $report['project']['trash']['retention_days']);
        $this->assertSame(7, $report['project']['trash']['days_left']);
        $this->assertFalse($report['project']['trash']['expired']);
        $this->assertStringContainsString('刻意保留的可恢复内容', (string) $report['project']['trash']['note']);

        // 没处理的：还留着 —— 这就是要交给产品定规则的账
        $this->assertSame(1, $leftovers['tasks']['rows'] ?? null, '定时任务没有被级联删除');
        $this->assertSame(1, $leftovers['url_imports']['rows'] ?? null, 'URL 导入记录还留着（含客户填的地址）');
        $this->assertSame(
            1,
            $leftovers['model_call_logs']['rows'] ?? null,
            '调用流水按 2026-09-04 拍板刻意保留（与钱有关）',
        );
        $this->assertStringContainsString('刻意保留', (string) ($leftovers['model_call_logs']['note'] ?? ''));
        $this->assertGreaterThan(0, $report['project']['leftover_row_count']);
    }

    public function test_it_points_out_report_directories_whose_row_is_gone_without_deleting_them(): void
    {
        $disk = Storage::disk('reports');
        $disk->put('executions/999999/report.md', '# 孤儿报告');

        try {
            $report = json_decode(
                $this->runAudit(['--json' => true]),
                true, 512, JSON_THROW_ON_ERROR,
            );

            $directories = $report['report_directories'];
            $this->assertSame('ok', $directories['status']);
            $this->assertSame(1, $directories['orphan_count'], '执行行没了但文件还在，应当算孤儿');
            $this->assertSame('executions/999999', $directories['orphans'][0]['path']);
            $this->assertSame('skill_executions', $directories['orphans'][0]['table']);
            $this->assertGreaterThan(0, $directories['orphan_bytes']);

            // JSON 里孤儿只报路径、归属表与字节数：不读正文、不猜租户
            $this->assertArrayNotHasKey('tenant_id', $directories['orphans'][0]);

            // 人看的输出里必须点明这类目录没有租户归属 —— 否则运营会以为能按租户删
            $human = $this->runAudit([]);
            $this->assertStringContainsString('孤儿', $human);
            $this->assertStringContainsString('已经没有行能说明它属于谁', $human);

            $this->assertTrue($disk->exists('executions/999999/report.md'), '盘点命令删了文件 —— 它必须是只读的');
        } finally {
            $disk->deleteDirectory('executions/999999');
        }
    }

    public function test_a_project_from_another_tenant_is_rejected_instead_of_being_counted(): void
    {
        $other = Tenant::query()->create([
            'subdomain' => 'privacy-other', 'name' => 'OTHER', 'plan' => 'basic', 'status' => 'active',
        ]);

        $exitCode = Artisan::call('geo:privacy-lifecycle-audit', [
            '--project' => (string) $this->project->id,
            '--tenant' => (string) $other->id,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('tenant_mismatch', $report['project']['status']);
        $this->assertSame(2, $exitCode, '"没盘成"必须是非零状态，不能和"盘完没事"混在一起');
        $this->assertSame(2, $report['exit_code'], '报告里的 exit_code 要与实际返回码一致');
    }

    /** 报告里不许出现客户的正文/密钥类内容（盘点只输出行数与文件名）。 */
    public function test_the_audit_output_never_carries_customer_content(): void
    {
        $this->seedProjectContent(body: '# 客户的机密正文：别出现在任何盘点输出里');

        $raw = $this->runAudit(['--tenant' => (string) $this->tenant->id, '--json' => true]);

        $this->assertStringNotContainsString('机密正文', $raw);
        $this->assertStringNotContainsString('acct_privacy', $raw);
    }

    /**
     * 已退役的站内阅读页在项目删除前后均不得再次公开内容。
     */
    public function test_the_public_reading_link_dies_with_the_project(): void
    {
        config(['app.tenant_domain' => 'geo.example.test']);

        $slug = 'privacy-lifecycle-post';
        TenantContext::set($this->tenant);
        try {
            $article = Article::query()->create([
                'project_id' => $this->project->id,
                'author_id' => $this->user->id,
                'title' => '公开文章',
                'slug' => $slug,
                'body_markdown' => '# 公开内容',
                'status' => 'published',
                'source_type' => 'manual',
                'metadata' => [],
            ]);
        } finally {
            TenantContext::forget();
        }

        $url = "/read/{$this->tenant->subdomain}/{$slug}";
        $this->get($url)->assertStatus(410)->assertDontSee($article->title);

        Sanctum::actingAs($this->user, ['*']);
        $this->forTenant($this->tenant)
            ->deleteJson("/api/v1/projects/{$this->project->id}")
            ->assertNoContent();

        $this->get($url)->assertStatus(410);
    }

    /**
     * §9.5 的另一半：**删项目不会下架外站内容**。
     *
     * 本地发布记录随文章级联删除（连 remote_url 一起丢），客户 WordPress 上那篇却还在线。
     * 盘点要把这条缝指出来 —— 它属于"必须单列规则"的那一类，不能靠删除接口顺手解决。
     */
    public function test_it_warns_that_deleting_a_project_does_not_take_external_posts_down(): void
    {
        TenantContext::set($this->tenant);
        try {
            $channel = PublishingChannel::query()->create([
                'tenant_id' => $this->tenant->id,
                'name' => '客户 WordPress',
                'type' => PublishingChannel::TYPE_WORDPRESS_REST,
                'endpoint_url' => 'https://customer.example.test/wp-json',
                'is_active' => true,
            ]);

            $article = Article::query()->create([
                'project_id' => $this->project->id,
                'author_id' => $this->user->id,
                'title' => '已发到外站的文章',
                'body_markdown' => '# 正文',
                // 用 approved 而不是 published：published 会被 Article::created 的自动分发钩子
                // 抢先建一条 queued 记录（同一对 article+channel 有唯一约束），这里要的是"已经发出去"。
                'status' => 'approved',
                'source_type' => 'manual',
                'metadata' => [],
            ]);

            $publication = ArticlePublication::query()->create([
                'tenant_id' => $this->tenant->id,
                'article_id' => $article->id,
                'publishing_channel_id' => $channel->id,
                'status' => ArticlePublication::STATUS_PUBLISHED,
                'remote_id' => '12345',
                'remote_url' => 'https://customer.example.test/blog/post-1',
                'published_at' => now(),
            ]);
        } finally {
            TenantContext::forget();
        }

        $before = json_decode(
            $this->runAudit(['--project' => (string) $this->project->id, '--json' => true]),
            true, 512, JSON_THROW_ON_ERROR,
        );

        $external = $before['project']['external_publications'];
        $this->assertSame(1, $external['total']);
        $this->assertSame(['published' => 1], $external['by_status']);
        $this->assertSame(1, $external['with_remote_url']);
        $this->assertStringContainsString('不会下架外站内容', $external['note']);

        Sanctum::actingAs($this->user, ['*']);
        $this->forTenant($this->tenant)
            ->deleteJson("/api/v1/projects/{$this->project->id}")
            ->assertNoContent();

        // 桶内：本地记录**还在**（2026-09-21 起删除先软删项目）—— 这段窗口正是撤稿的最佳时机，
        // 所以盘点在桶内还能把 remote_url 报出来（下面清理之后再报不出来）。
        $this->assertDatabaseHas('article_publications', ['id' => $publication->id]);
        $this->assertDatabaseHas('articles', ['id' => $article->id]);

        $inTrash = json_decode(
            $this->runAudit(['--project' => (string) $this->project->id, '--json' => true]),
            true, 512, JSON_THROW_ON_ERROR,
        );
        $this->assertSame(1, $inTrash['project']['external_publications']['total']);
        $this->assertSame(1, $inTrash['project']['external_publications']['with_remote_url']);
        $this->assertStringContainsString('撤稿的最佳时机', $inTrash['project']['external_publications']['note']);

        // 期满清理（不可恢复的那一步）：本地记录与远端地址一起没了 —— 但外站那篇还在线。
        $this->travel(8)->days();
        app(\App\Services\Projects\ProjectTrashService::class)->purge($this->project->fresh());

        $this->assertDatabaseMissing('article_publications', ['id' => $publication->id]);
        $this->assertDatabaseMissing('articles', ['id' => $article->id]);

        // 清理之后连项目都查不到了（硬删）：盘点只能报 project_not_found ——
        // 恰是这条缝最危险的地方：数据上看起来"干净得没有痕迹"，外站那篇其实还挂着。
        $after = json_decode(
            $this->runAudit(['--project' => (string) $this->project->id, '--json' => true]),
            true, 512, JSON_THROW_ON_ERROR,
        );
        $this->assertSame('project_not_found', $after['project']['status']);
        $this->assertSame($this->project->id, $after['project']['project_id']);
    }

    // ── 装配 ─────────────────────────────────────────────────

    private function seedProjectContent(string $body = '# 报告'): void
    {
        TenantContext::set($this->tenant);
        try {
            $execution = SkillExecution::query()->create([
                'project_id' => $this->project->id,
                'user_id' => $this->user->id,
                'skill_id' => 'yao-geo-panorama-audit',
                'skill_name' => '全景诊断',
                'category' => 'geo_strategy',
                'stage_index' => 1,
                'status' => 'completed',
                'execution_mode' => 'async',
                'input_data' => [],
            ]);

            $article = Article::query()->create([
                'project_id' => $this->project->id,
                'author_id' => $this->user->id,
                'title' => '隐私盘点报告',
                'body_markdown' => $body,
                'status' => 'approved',
                'source_type' => 'geo_analysis',
                'source_skill' => 'yao-geo-panorama-audit',
                'skill_execution_id' => $execution->id,
            ]);
            $execution->update(['article_id' => $article->id]);

            ModelCallLog::query()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'user_id' => $this->user->id,
                'skill_execution_id' => $execution->id,
                'provider' => 'mock',
                'model' => 'mock-model',
                'status' => 'completed',
            ]);

            Task::query()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'user_id' => $this->user->id,
                'name' => '每天跑一次',
                'skill_id' => 'yao-geo-panorama-audit',
                'cadence' => 'daily',
            ]);

            UrlImport::query()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'url' => 'https://customer.example.test/blog/secret-post',
                'status' => 'pending',
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    /** @param  array<string, mixed>  $arguments */
    private function runAudit(array $arguments): string
    {
        Artisan::call('geo:privacy-lifecycle-audit', $arguments);

        return Artisan::output();
    }

    /**
     * 全库行数快照（只读证明用）。
     *
     * @return array<string, int>
     */
    private function allRowCounts(): array
    {
        $counts = [];
        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            $counts[$name] = (int) DB::table($name)->count();
        }
        ksort($counts);

        return $counts;
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain.'.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
