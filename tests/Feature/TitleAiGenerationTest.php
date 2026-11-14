<?php

namespace Tests\Feature;

use App\Models\ModelCallLog;
use App\Models\ApiIdempotencyKey;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\Tenant;
use App\Models\TitleLibrary;
use App\Models\User;
use App\Services\Content\TitleAiGenerationService;
use App\Services\AI\AiProviderServiceImpl;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TitleAiGenerationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\UsesWorkspaceModels;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        // Existing model and billing cases exercise the legacy direct route;
        // the owner/body claim contract is covered in TitleGenerationClaimTest.
        config(['geo.title_generation_claim_required' => false]);
    }

    public function test_generates_titles_and_persists_them(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        // 稳定的假 AI：返回换行分隔的标题列表
        $this->app->bind(AiProvider::class, fn () => new class implements AiProvider {
            public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
            {
                $content = "GEO 优化的 5 个核心策略\nAI 搜索时代的内容生产指南\n如何让品牌被 AI 主动推荐";

                return new AiProviderCallResult(success: true, provider: 'fake', model: 'fake', content: $content);
            }
            public function generateStructured(string $prompt, string $schema, ?string $system = null, array $options = []): AiProviderCallResult
            {
                return $this->generate($prompt, $system);
            }
            public function availableProviders(\App\Models\Tenant $tenant): array { return []; }
            public function pickBestModel(\App\Models\Tenant $tenant, ?string $preferred = null): array { return ['provider' => 'fake', 'model' => 'fake']; }
            public function getTenantApiKey(\App\Models\Tenant $tenant, string $provider): ?string { return null; }
            public function embed(string $text, string $model = 'embedding', array $options = []): array { return ['embedding' => [], 'tokens' => 0]; }
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array { return ['embeddings' => [], 'tokens' => 0]; }
        });

        $project = $this->makeProject($tenant, $admin);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->withHeader('X-Idempotency-Key', 'title-success-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id,
                'keywords' => ['GEO', 'AI 搜索'],
                'count' => 3,
                'style' => 'seo',
                'category' => 'listicle',
            ])
            ->assertStatus(201)
            ->assertJsonPath('fallback_used', false)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.title', 'GEO 优化的 5 个核心策略');

        // 数据库落库 + 租户归属
        TenantContext::set($tenant);
        $this->assertSame(3, TitleLibrary::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame('listicle', TitleLibrary::query()->first()->category);
        TenantContext::forget();
    }

    public function test_falls_back_to_templates_when_ai_fails(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        // 模型在发送前确认不可用 → 触发模板兜底
        $this->app->bind(AiProvider::class, fn () => new class implements AiProvider {
            public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
            {
                return new AiProviderCallResult(success: false, provider: 'fake', model: 'fake',
                    errorMessage: 'boom', gateway: ['pre_send_rejection' => true]);
            }
            public function generateStructured(string $prompt, string $schema, ?string $system = null, array $options = []): AiProviderCallResult
            {
                return $this->generate($prompt, $system);
            }
            public function availableProviders(\App\Models\Tenant $tenant): array { return []; }
            public function pickBestModel(\App\Models\Tenant $tenant, ?string $preferred = null): array { return ['provider' => 'fake', 'model' => 'fake']; }
            public function getTenantApiKey(\App\Models\Tenant $tenant, string $provider): ?string { return null; }
            public function embed(string $text, string $model = 'embedding', array $options = []): array { return ['embedding' => [], 'tokens' => 0]; }
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array { return ['embeddings' => [], 'tokens' => 0]; }
        });

        $project = $this->makeProject($tenant, $admin);

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->withHeader('X-Idempotency-Key', 'title-fallback-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id,
                'keywords' => ['GEO'],
                'count' => 2,
                'style' => 'attractive',
            ])
            ->assertStatus(201)
            ->assertJsonPath('fallback_used', true)
            ->assertJsonCount(2, 'data');

        TenantContext::set($tenant);
        $this->assertSame(2, TitleLibrary::query()->where('tenant_id', $tenant->id)->count());
        TenantContext::forget();
    }

    public function test_missing_text_model_is_an_error_without_generated_titles(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        Http::preventStrayRequests();
        $this->app->bind(AiProvider::class, AiProviderServiceImpl::class);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-no-key-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id, 'keywords' => ['GEO'], 'count' => 2,
            ])->assertUnprocessable()->assertJsonPath('message', '未配置文本模型');
        $this->assertSame(0, TitleLibrary::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_member_cannot_generate_titles(): void
    {
        [$tenant, $member] = $this->makeTenantUser('tenant-a', 'member');

        Sanctum::actingAs($member, ['*']);

        $this->forTenant($tenant)
            ->withHeader('X-Idempotency-Key', 'title-member-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => 1,
                'keywords' => ['GEO'],
                'count' => 2,
            ])
            ->assertStatus(403);
    }

    public function test_json_stub_object_triggers_fallback_without_noise(): void
    {
        $service = $this->makeServiceWithContent("{\n}\n");
        $result = $service->generateTitles(['GEO'], 3);

        $this->assertTrue($result['fallback_used']);
        foreach ($result['titles'] as $title) {
            $this->assertStringNotContainsString('{', $title);
            $this->assertStringNotContainsString('}', $title);
            $this->assertStringNotContainsString('[', $title);
            $this->assertStringNotContainsString(']', $title);
        }
    }

    public function test_json_stub_array_triggers_fallback_without_noise(): void
    {
        $service = $this->makeServiceWithContent("[\n]\n");
        $result = $service->generateTitles(['GEO'], 3);

        $this->assertTrue($result['fallback_used']);
        foreach ($result['titles'] as $title) {
            $this->assertStringNotContainsString('[', $title);
            $this->assertStringNotContainsString(']', $title);
        }
    }

    public function test_compliant_json_extracts_title_strings(): void
    {
        $service = $this->makeServiceWithContent('{"titles":["标题甲","标题乙"]}');
        $result = $service->generateTitles(['GEO'], 2);

        $this->assertFalse($result['fallback_used']);
        $this->assertSame(['标题甲', '标题乙'], $result['titles']);
    }

    public function test_malformed_json_falls_back_without_line_splitting(): void
    {
        $service = $this->makeServiceWithContent('{"titles":["标题甲"');
        $result = $service->generateTitles(['GEO'], 2);

        $this->assertTrue($result['fallback_used']);
        // 不得把畸形 JSON 的片段（如 标题甲）当成标题入库
        foreach ($result['titles'] as $title) {
            $this->assertStringNotContainsString('标题甲', $title);
        }
    }

    public function test_plain_text_three_lines_parsed_without_regression(): void
    {
        $service = $this->makeServiceWithContent("标题一\n标题二\n标题三");
        $result = $service->generateTitles(['GEO'], 3);

        $this->assertFalse($result['fallback_used']);
        $this->assertSame(['标题一', '标题二', '标题三'], $result['titles']);
    }

    public function test_quoted_comma_lines_are_cleaned(): void
    {
        $service = $this->makeServiceWithContent('"标题甲",' . "\n" . '"标题乙",');
        $result = $service->generateTitles(['GEO'], 2);

        $this->assertFalse($result['fallback_used']);
        $this->assertSame(['标题甲', '标题乙'], $result['titles']);
    }

    public function test_error_object_not_mis_extracted_as_titles(): void
    {
        // 工程师硬化点：{"error":"request_timeout","code":"invalid_key"} 是关联数组
        // （非顺序列表），不得被 isListOfStrings 当成标题列表抽出其字符串值。
        // request_timeout 含 'x' 可作区分标记。
        $service = $this->makeServiceWithContent('{"error":"request_timeout","code":"invalid_key"}');
        $result = $service->generateTitles(['GEO'], 2);

        $this->assertTrue($result['fallback_used']);
        foreach ($result['titles'] as $title) {
            $this->assertStringNotContainsString('x', $title);
            $this->assertStringNotContainsString('y', $title);
        }
    }

    public function test_top_level_string_array_is_extracted(): void
    {
        // 对照：顶层顺序字符串数组是合法标题列表，array_is_list 不得误杀。
        $service = $this->makeServiceWithContent('["标题甲","标题乙"]');
        $result = $service->generateTitles(['GEO'], 2);

        $this->assertFalse($result['fallback_used']);
        $this->assertSame(['标题甲', '标题乙'], $result['titles']);
    }

    private function makeServiceWithContent(string $content): TitleAiGenerationService
    {
        $provider = new class($content) implements AiProvider {
            public function __construct(private string $content) {}

            public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
            {
                return new AiProviderCallResult(success: true, provider: 'fake', model: 'fake', content: $this->content);
            }

            public function generateStructured(string $prompt, string $schema, ?string $system = null, array $options = []): AiProviderCallResult
            {
                return $this->generate($prompt, $system);
            }

            public function availableProviders(\App\Models\Tenant $tenant): array { return []; }
            public function pickBestModel(\App\Models\Tenant $tenant, ?string $preferred = null): array { return ['provider' => 'fake', 'model' => 'fake']; }
            public function getTenantApiKey(\App\Models\Tenant $tenant, string $provider): ?string { return null; }
            public function embed(string $text, string $model = 'embedding', array $options = []): array { return ['embedding' => [], 'tokens' => 0]; }
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array { return ['embeddings' => [], 'tokens' => 0]; }
        };

        return new TitleAiGenerationService($provider);
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

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain . $role),
            'role' => $role,
        ]);

        return [$tenant, $user];
    }

    /**
     * 扣费回归：真实模型调用必须落一条可计费的模型调用记录。
     *
     * 网关对 geo_saas 的 chat completion 零扣款，geo 侧不显式扣点就是两头都不扣＝免费。
     * 本接口 2026-08-19 之前就是这个状态（真调模型、真出上游成本、客户零扣费）。
     */
    public function test_real_generation_records_a_billable_model_call(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);

        $this->app->bind(AiProvider::class, fn () => $this->fakeProvider("标题一\n标题二", tokens: 137));

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->withHeader('X-Idempotency-Key', 'title-billable-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id,
                'keywords' => ['GEO'],
                'count' => 2,
            ])
            ->assertStatus(201)
            ->assertJsonPath('fallback_used', false);

        TenantContext::set($tenant);
        try {
            $execution = SkillExecution::query()->where('skill_id', 'ai-assist-titles')->first();
            $this->assertNotNull($execution, '真实生成必须建 assist 执行记录，否则无处记账');
            $this->assertSame('assist', $execution->execution_mode);
            $this->assertSame('批量生成标题', $execution->skill_name);
            $this->assertSame($project->id, $execution->project_id);

            $logs = ModelCallLog::query()->where('skill_execution_id', $execution->id)->get();
            $this->assertCount(1, $logs, '一次生成只应记一条模型调用');
            $this->assertSame(137, (int) $logs->first()->total_tokens);
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * 反向闸：模板兜底没调模型，一分钱都不能扣。
     */
    public function test_template_fallback_is_not_charged(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);

        // 模型失败 → 走模板兜底
        $this->app->bind(AiProvider::class, fn () => $this->fakeProvider('', success: false));

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->withHeader('X-Idempotency-Key', 'title-fallback-no-send-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id,
                'keywords' => ['GEO'],
                'count' => 2,
            ])
            ->assertStatus(201)
            ->assertJsonPath('fallback_used', true);

        TenantContext::set($tenant);
        try {
            $this->assertSame(0, SkillExecution::query()->where('skill_id', 'ai-assist-titles')->count());
            $this->assertSame(0, ModelCallLog::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * project_id 必填：缺了就无处记账，宁可 422 也不能静默免费生成。
     */
    public function test_project_id_is_required_so_generation_cannot_go_unbilled(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');

        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)
            ->postJson('/api/v1/materials/titles/generate', [
                'keywords' => ['GEO'],
                'count' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('project_id');
    }

    public function test_missing_or_foreign_project_is_rejected_before_any_model_call(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        [$foreignTenant, $foreignAdmin] = $this->makeTenantUser('tenant-b', 'admin');
        $foreignProject = $this->makeProject($foreignTenant, $foreignAdmin);

        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldNotReceive('generate');
        $this->app->instance(AiProvider::class, $provider);
        Sanctum::actingAs($admin, ['*']);

        foreach ([$foreignProject->id, $foreignProject->id + 1000] as $projectId) {
            $this->forTenant($tenant)
                ->postJson('/api/v1/materials/titles/generate', [
                    'project_id' => $projectId,
                    'keywords' => ['GEO'],
                    'count' => 5,
                ])
                ->assertStatus(404);
        }

        TenantContext::set($tenant);
        try {
            $this->assertSame(0, TitleLibrary::query()->count());
            $this->assertSame(0, SkillExecution::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    public function test_title_generation_requires_a_stable_key_before_model_send(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldNotReceive('generate');
        $this->app->instance(AiProvider::class, $provider);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->postJson('/api/v1/materials/titles/generate', [
            'project_id' => $project->id, 'keywords' => ['GEO'],
        ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_title_generation_replay_reuses_the_result_without_another_model_call(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->withArgs(function ($prompt, $system, $options) {
            $this->assertSame('batch-titles', $options['feature_id']);
            $this->assertSame('generate', $options['step_id']);
            $this->assertStringStartsWith('assist:', $options['task_id']);

            return true;
        })->andReturn(new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text',
            content: "标题一\n标题二", totalTokens: 75,
            gateway: ['request_id' => 'title-gateway-replay-1'],
        ));
        $this->app->instance(AiProvider::class, $provider);
        Sanctum::actingAs($admin, ['*']);
        $input = ['project_id' => $project->id, 'keywords' => ['GEO'], 'count' => 2];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-replay-1')
                ->postJson('/api/v1/materials/titles/generate', $input)
                ->assertCreated()->assertJsonCount(2, 'data');
        }
        TenantContext::set($tenant);
        try {
            $this->assertSame(2, TitleLibrary::query()->count());
            $this->assertSame(1, ModelCallLog::query()->count());
        } finally {
            TenantContext::forget();
        }
        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-replay-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id, 'keywords' => ['different'], 'count' => 2,
            ])->assertConflict();
    }

    public function test_title_generation_recovers_same_attempt_after_usage_save_fails(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->withArgs(function ($prompt, $system, $options): bool {
            $this->assertSame('generate', $options['step_id']);
            $this->assertStringStartsWith('assist:', $options['task_id']);

            return true;
        })->andReturn(new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text', content: '标题一',
            gateway: ['request_id' => 'title-unsaved-1'],
        ));
        $this->app->instance(AiProvider::class, $provider);
        $this->mock(\App\Services\Billing\AssistUsageRecorder::class, function ($mock) {
            $mock->shouldReceive('record')->twice()->andReturn(null, new ModelCallLog);
        });
        Sanctum::actingAs($admin, ['*']);
        $input = ['project_id' => $project->id, 'keywords' => ['GEO']];

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-unsaved-1')
            ->postJson('/api/v1/materials/titles/generate', $input)
            ->assertStatus(503)->assertJsonPath('action', 'idempotency_result_pending')
            ->assertDontSee('标题一');
        TenantContext::set($tenant);
        try {
            $this->assertSame(0, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-unsaved-1')
                ->postJson('/api/v1/materials/titles/generate', $input)
                ->assertCreated()->assertJsonPath('data.0.title', '标题一');
        }
        TenantContext::set($tenant);
        try {
            $this->assertSame(1, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    public function test_unknown_title_attempt_stays_pending_without_replay(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $provider = \Mockery::mock(AiProvider::class);
        $taskIds = [];
        $provider->shouldReceive('generate')->once()->withArgs(function ($prompt, $system, $options) use (&$taskIds): bool {
            $taskIds[] = $options['task_id'];

            return true;
        })->andReturn(
            AiProviderCallResult::failure('gateway', 'text', 'pending', ['http_status' => 202]),
            new AiProviderCallResult(success: true, provider: 'gateway', model: 'text', content: '已恢复标题',
                gateway: ['request_id' => 'title-pending-replay-1']),
        );
        $this->app->instance(AiProvider::class, $provider);
        $this->mock(\App\Services\Billing\AssistUsageRecorder::class, function ($mock) {
            $mock->shouldReceive('record')->twice()->andReturn(new ModelCallLog);
        });
        Sanctum::actingAs($admin, ['*']);
        $input = ['project_id' => $project->id, 'keywords' => ['GEO']];

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-pending-replay-1')
            ->postJson('/api/v1/materials/titles/generate', $input)
            ->assertStatus(503)->assertJsonPath('action', 'idempotency_result_pending');
        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-pending-replay-1')
            ->postJson('/api/v1/materials/titles/generate', $input)
            ->assertStatus(503)->assertJsonPath('action', 'idempotency_result_pending');
        $this->assertCount(1, $taskIds);
        TenantContext::set($tenant);
        try {
            $this->assertSame(0, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    public function test_failed_title_attempt_without_proof_of_pre_send_rejection_stays_pending(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $taskIds = [];
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->withArgs(function ($prompt, $system, $options) use (&$taskIds): bool {
            $taskIds[] = $options['task_id'];

            return true;
        })->andReturn(AiProviderCallResult::failure('gateway', 'text', 'response lost'));
        $this->app->instance(AiProvider::class, $provider);
        Sanctum::actingAs($admin, ['*']);
        $input = ['project_id' => $project->id, 'keywords' => ['GEO']];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-no-receipt-1')
                ->postJson('/api/v1/materials/titles/generate', $input)
                ->assertStatus(503)->assertJsonPath('action', 'idempotency_result_pending');
        }
        $this->assertCount(1, $taskIds);
        TenantContext::set($tenant);
        try {
            $this->assertSame(0, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    public function test_gateway_failure_cannot_claim_it_was_rejected_before_send(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $this->useWorkspaceModels($tenant);
        Http::fake(['https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::response([
            'gateway' => ['pre_send_rejection' => true],
        ], 503)]);
        $this->app->bind(AiProvider::class, AiProviderServiceImpl::class);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-forged-pre-send-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id, 'keywords' => ['GEO'],
            ])->assertStatus(503)->assertJsonPath('action', 'idempotency_result_pending');
        TenantContext::set($tenant);
        try {
            $this->assertSame(0, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    public function test_titles_deliver_without_billing_configuration(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);

        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn(new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text', content: '待结算标题',
            gateway: ['request_id' => 'title-wallet-pending-1'],
        ));
        $this->app->instance(AiProvider::class, $provider);
        $pending = new ModelCallLog;
        $pending->metadata = ['points_deduction_status' => 'failed'];
        $settled = new ModelCallLog;
        $settled->metadata = ['points_deduction_status' => 'deducted'];
        $this->mock(\App\Services\Billing\AssistUsageRecorder::class, function ($mock) use ($pending, $settled) {
            $mock->shouldReceive('record')->once()->andReturn($pending);
        });
        Sanctum::actingAs($admin, ['*']);
        $input = ['project_id' => $project->id, 'keywords' => ['GEO']];

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-wallet-pending-1')
            ->postJson('/api/v1/materials/titles/generate', $input)
            ->assertCreated()->assertJsonPath('data.0.title', '待结算标题');
        TenantContext::set($tenant);
        try {
            $this->assertSame(1, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-wallet-pending-1')
            ->postJson('/api/v1/materials/titles/generate', $input)
            ->assertCreated()->assertJsonPath('data.0.title', '待结算标题');
        TenantContext::set($tenant);
        try {
            $this->assertSame(1, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
    }


    public function test_title_http_reentry_uses_identical_gateway_attempt_identity(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $this->useWorkspaceModels($tenant);
        Http::fake(['https://models.example.test/v1/chat/completions' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['id' => 'title-http-pending', 'status' => 'in_progress'], 202)
            ->push([
                'choices' => [['message' => ['content' => '网关重放标题'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                'gateway' => ['request_id' => 'title-http-completed'],
            ], 200)]);
        $this->app->bind(AiProvider::class, AiProviderServiceImpl::class);
        Sanctum::actingAs($admin, ['*']);
        $input = ['project_id' => $project->id, 'keywords' => ['GEO']];

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-http-reentry-1')
            ->postJson('/api/v1/materials/titles/generate', $input)->assertStatus(503);
        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-http-reentry-1')
            ->postJson('/api/v1/materials/titles/generate', $input)
            ->assertStatus(503)->assertJsonPath('action', 'idempotency_result_pending');

        Http::assertSentCount(1);
        $this->assertSame(0, TitleLibrary::withoutGlobalScopes()->count());
        $this->assertSame('unknown', ModelCallLog::query()->firstOrFail()->status);
        $this->assertNull(ModelCallLog::query()->firstOrFail()->total_tokens);
    }

    public function test_pending_title_attempt_rejects_same_key_with_changed_request(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn(
            AiProviderCallResult::failure('gateway', 'text', 'pending', ['http_status' => 202]),
        );
        $this->app->instance(AiProvider::class, $provider);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-pending-conflict-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id, 'keywords' => ['original'],
            ])->assertStatus(503);
        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-pending-conflict-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id, 'keywords' => ['changed'],
            ])->assertConflict();
    }

    public function test_legacy_cached_title_503_cannot_repeat_an_unknown_call(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn(
            AiProviderCallResult::failure('gateway', 'text', 'pending', ['http_status' => 202]),
            new AiProviderCallResult(success: true, provider: 'gateway', model: 'text', content: '旧请求恢复标题',
                gateway: ['request_id' => 'title-old-503-1']),
        );
        $this->app->instance(AiProvider::class, $provider);
        $this->mock(\App\Services\Billing\AssistUsageRecorder::class, function ($mock) {
            $mock->shouldReceive('record')->times(3)->andReturn(new ModelCallLog);
        });
        Sanctum::actingAs($admin, ['*']);
        $input = ['project_id' => $project->id, 'keywords' => ['GEO']];

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-old-503-1')
            ->postJson('/api/v1/materials/titles/generate', $input)->assertStatus(503);
        ApiIdempotencyKey::query()->where('idempotency_key', 'title-old-503-1')->update([
            'response_status' => 503,
            'response_body' => json_encode(['action' => 'idempotency_result_pending'], JSON_THROW_ON_ERROR),
        ]);
        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-old-503-1')
            ->postJson('/api/v1/materials/titles/generate', $input)
            ->assertStatus(503)->assertJsonPath('action', 'idempotency_result_pending');
        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-old-503-1')
            ->postJson('/api/v1/materials/titles/generate', $input)->assertStatus(503);
        TenantContext::set($tenant);
        try {
            $this->assertSame(0, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    public function test_legacy_unmarked_pending_title_attempt_requires_review(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn(
            AiProviderCallResult::failure('gateway', 'text', 'pending', ['http_status' => 202]),
        );
        $this->app->instance(AiProvider::class, $provider);
        Sanctum::actingAs($admin, ['*']);
        $input = ['project_id' => $project->id, 'keywords' => ['GEO']];

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-old-pending-1')
            ->postJson('/api/v1/materials/titles/generate', $input)->assertStatus(503);
        ApiIdempotencyKey::query()->where('idempotency_key', 'title-old-pending-1')
            ->update(['response_body' => null]);
        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-old-pending-1')
            ->postJson('/api/v1/materials/titles/generate', $input)->assertConflict();
    }

    public function test_title_rows_roll_back_when_completed_replay_cannot_be_saved(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn(new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text', content: '原结果标题',
            gateway: ['request_id' => 'title-final-save-failure-1'],
        ));
        $this->app->instance(AiProvider::class, $provider);
        $this->mock(\App\Services\Billing\AssistUsageRecorder::class, function ($mock) {
            $mock->shouldReceive('record')->twice()->andReturn(new ModelCallLog);
        });
        $failSave = true;
        ApiIdempotencyKey::saving(function (ApiIdempotencyKey $row) use (&$failSave): void {
            if ($failSave && $row->response_status === 201) {
                throw new \RuntimeException('isolated completed replay write failure');
            }
        });
        Sanctum::actingAs($admin, ['*']);
        $input = ['project_id' => $project->id, 'keywords' => ['GEO']];

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-final-save-failure-1')
            ->postJson('/api/v1/materials/titles/generate', $input)->assertStatus(500);
        TenantContext::set($tenant);
        try {
            $this->assertSame(0, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
        $failSave = false;
        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-final-save-failure-1')
            ->postJson('/api/v1/materials/titles/generate', $input)
            ->assertCreated()->assertJsonPath('data.0.title', '原结果标题');
        TenantContext::set($tenant);
        try {
            $this->assertSame(1, TitleLibrary::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    public function test_invalid_successful_model_output_still_records_billable_usage(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $this->app->bind(AiProvider::class, fn () => $this->fakeProvider('{', tokens: 75));
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-invalid-output-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id, 'keywords' => ['GEO'],
            ])->assertCreated()->assertJsonPath('fallback_used', true);

        TenantContext::set($tenant);
        try {
            $this->assertSame(1, ModelCallLog::query()->count());
        } finally {
            TenantContext::forget();
        }
    }

    public function test_unconfirmed_gateway_result_never_turns_into_free_template_titles(): void
    {
        [$tenant, $admin] = $this->makeTenantUser('tenant-a', 'admin');
        $project = $this->makeProject($tenant, $admin);
        $provider = \Mockery::mock(AiProvider::class);
        $provider->shouldReceive('generate')->once()->andReturn(AiProviderCallResult::failure(
            'gateway', 'text', 'still pending', ['http_status' => 202, 'status' => 'unknown', 'usage_available' => false, 'request_id' => 'model:test-title-unknown'],
        ));
        $this->app->instance(AiProvider::class, $provider);
        Sanctum::actingAs($admin, ['*']);

        $this->forTenant($tenant)->withHeader('X-Idempotency-Key', 'title-gateway-pending-1')
            ->postJson('/api/v1/materials/titles/generate', [
                'project_id' => $project->id, 'keywords' => ['GEO'],
            ])->assertStatus(503)->assertJsonPath('action', 'idempotency_result_pending');
        TenantContext::set($tenant);
        try {
            $this->assertSame(0, TitleLibrary::query()->count());
            $this->assertSame(1, ModelCallLog::query()->count());
            $this->assertSame('unknown', ModelCallLog::query()->sole()->status);
            $this->assertNull(ModelCallLog::query()->sole()->total_tokens);
        } finally {
            TenantContext::forget();
        }
    }

    private function fakeProvider(string $content, bool $success = true, int $tokens = 0): AiProvider
    {
        return new class($content, $success, $tokens) implements AiProvider {
            public function __construct(
                private string $content,
                private bool $ok,
                private int $tokens,
            ) {}

            public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
            {
                if (! $this->ok) {
                    return AiProviderCallResult::failure('fake', 'fake', 'boom', ['pre_send_rejection' => true]);
                }

                return new AiProviderCallResult(
                    success: true,
                    provider: 'fake',
                    model: 'text',
                    content: $this->content,
                    promptTokens: (int) floor($this->tokens / 2),
                    completionTokens: $this->tokens - (int) floor($this->tokens / 2),
                    totalTokens: $this->tokens,
                );
            }

            public function generateStructured(string $prompt, string $schema, ?string $system = null, array $options = []): AiProviderCallResult
            {
                return $this->generate($prompt, $system);
            }
            public function availableProviders(\App\Models\Tenant $tenant): array { return []; }
            public function pickBestModel(\App\Models\Tenant $tenant, ?string $preferred = null): array { return ['provider' => 'fake', 'model' => 'fake']; }
            public function getTenantApiKey(\App\Models\Tenant $tenant, string $provider): ?string { return null; }
            public function embed(string $text, string $model = 'embedding', array $options = []): array { return ['embedding' => [], 'tokens' => 0]; }
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array { return ['embeddings' => [], 'tokens' => 0]; }
        };
    }

    private function makeProject(Tenant $tenant, User $owner): Project
    {
        TenantContext::set($tenant);
        try {
            return Project::query()->create([
                'owner_id' => $owner->id,
                'name' => 'Proj '.$tenant->subdomain,
                'settings' => [],
            ]);
        } finally {
            TenantContext::forget();
        }
    }

    private function forTenant(Tenant $tenant): self
    {
        return $this
            ->withServerVariables(['HTTP_HOST' => $tenant->subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $tenant->subdomain);
    }
}
