<?php

namespace Tests\Feature;

use App\Models\SkillExecution;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DTOs\SkillBrief;
use App\Services\Contracts\AiProvider;
use App\Services\SkillAgent\PageObservation;
use App\Services\SkillAgent\SkillModelCaller;
use App\Services\Enums\ExecutionStatus;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 模型调用与用量登记 · 特征化测试（计划 §7 第 3 步：`SkillAgentRunnerService` 按职责拆小的第七桶）。
 *
 * 这次搬出去的是「一次模型调用」的全过程：组装 options、调用 provider、**每次尝试都登记用量**、
 * 按需升级兜底模型、判断执行是否已被取消。前两件事要真跑 provider 与扣费，由既有集成测试兜底
 * （PanoramaChunkedAnalysisTest / SkillRelayExecutionTest / SkillExecutionReliabilityTest）；
 * 这里冻结的是**纯函数部分的判据**：
 *
 *   - visualInputs：只有显式图片字段算图片。写了「截图」两个字不算 —— 这决定了走 vision
 *     还是 text（多模态调用更贵），也决定了会不会把一段普通文字当图片丢给模型；
 *   - modelForSkill / fallbackModelFor：有图走 vision，无图走 text；未知模型名不许兜底
 *     （返回 null → 调用方不会重复发起一次注定失败的升级调用）；
 *   - recoveryModelForReasoningStarvation：只有 analysis 档才值得再问一次；
 *   - buildFallbackSystemPrompt：降级模式的系统提示词逐字冻结（改一个字就是改了所有降级产出）；
 *   - observationFromState：老状态行（没存过观察）必须回 null，不许造一个假的观察出来；
 *   - isCancelled：段间取消闸。执行 ID 为空 → 不查库；已取消 → true。
 */
class SkillModelCallerTest extends TestCase
{
    use RefreshDatabase;

    private function caller(): SkillModelCaller
    {
        return app(SkillModelCaller::class);
    }

    private function brief(array $fields = []): SkillBrief
    {
        return new SkillBrief(
            skillId: 'yao-geo-panorama-audit',
            projectId: 1,
            userId: 2,
            fields: $fields,
            query: null,
        );
    }

    public function test_missing_execution_id_blocks_model_before_send(): void
    {
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->never())->method('generate');
        $this->app->instance(AiProvider::class, $provider);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('durable skill execution');
        $this->caller()->generateAndRecord(
            'prompt', 'system', 100, 'text', $this->brief(),
        );
    }

    public function test_visual_inputs_only_accept_explicit_image_fields(): void
    {
        $caller = $this->caller();
        $png = 'data:image/png;base64,iVBORw0KGgo=';

        // images 数组里的 http(s) 与 base64 data URI 都算
        $this->assertSame(
            [$png, 'https://cdn.test/a.png', 'http://cdn.test/b.jpg'],
            $caller->visualInputs($this->brief(['images' => [$png, 'https://cdn.test/a.png', 'http://cdn.test/b.jpg']])),
        );

        // image_url / screenshot_url 是另外两个显式图片字段
        $this->assertSame(
            ['https://cdn.test/shot.png'],
            $caller->visualInputs($this->brief(['screenshot_url' => 'https://cdn.test/shot.png'])),
        );
        $this->assertSame(
            ['https://cdn.test/cover.webp'],
            $caller->visualInputs($this->brief(['image_url' => 'https://cdn.test/cover.webp'])),
        );

        // 「截图」「配图」这些字眼不是图片；非 http(s) 协议也不许当图片送出去
        $this->assertSame([], $caller->visualInputs($this->brief(['notes' => '请参考官网截图'])));
        $this->assertSame([], $caller->visualInputs($this->brief(['images' => ['ftp://cdn.test/a.png', 'javascript:alert(1)', 42, null, '不是链接']])));
        $this->assertSame([], $caller->visualInputs($this->brief()));
        // images 不是数组时当作没有，不能抛错
        $this->assertSame([], $caller->visualInputs($this->brief(['images' => 'https://cdn.test/a.png'])));
    }

    public function test_model_selection_is_frozen(): void
    {
        $caller = $this->caller();
        $skill = new \App\Services\DTOs\SkillDefinition(
            id: 'yao-geo-panorama-audit',
            name: '全景诊断',
            category: \App\Services\Enums\SkillCategory::GeoStrategy,
            maturity: 'stable',
            requiresWeb: false,
            stageIndex: 1,
            description: '全景诊断',
        );

        $this->assertSame('text', $caller->modelForSkill($skill, $this->brief()));
        $this->assertSame(
            'vision',
            $caller->modelForSkill($skill, $this->brief(['image_url' => 'https://cdn.test/a.png'])),
        );

        // 只有这两个模型名有兜底；其余（含空串）返回 null —— 调用方据此放弃升级重试
        $this->assertSame('text', $caller->fallbackModelFor('text'));
        $this->assertSame('vision', $caller->fallbackModelFor('vision'));
        $this->assertNull($caller->fallbackModelFor('gpt-5.4'));
        $this->assertNull($caller->fallbackModelFor(''));
    }

    public function test_recovery_model_is_only_offered_for_analysis_profile(): void
    {
        $caller = $this->caller();

        $this->assertSame(
            'text',
            $caller->recoveryModelForReasoningStarvation('text', ['routing' => ['task' => ['profile' => 'analysis']]]),
        );
        $this->assertNull(
            $caller->recoveryModelForReasoningStarvation('text', ['routing' => ['task' => ['profile' => 'chat']]]),
        );
        $this->assertNull($caller->recoveryModelForReasoningStarvation('text'));
    }

    public function test_fallback_system_prompt_is_frozen(): void
    {
        // 降级模式（无 SKILL.md 资产）的系统提示词：改一个字就是改了所有降级产出
        $this->assertSame(
            hash('sha256', $this->caller()->buildFallbackSystemPrompt()),
            'b957eea4998c89cdf55683011bcbab041c8be5fedefd4bf3366d77568005fd41',
        );
        $this->assertStringContainsString('生成式引擎优化', $this->caller()->buildFallbackSystemPrompt());
    }

    public function test_observation_from_state_never_invents_an_observation(): void
    {
        $caller = $this->caller();

        $this->assertNull($caller->observationFromState([]), '没存过观察的老状态行必须回 null');
        $this->assertNull($caller->observationFromState(['page_observation' => 'oops']));

        $observation = PageObservation::success('https://brand.test', 'https://brand.test/', 200, ['title' => '品牌官网']);
        $restored = $caller->observationFromState(['page_observation' => $observation->toArray()]);

        $this->assertNotNull($restored);
        $this->assertSame('https://brand.test', $restored->requestedUrl);
        $this->assertTrue($restored->ok);
        $this->assertSame(['title' => '品牌官网'], $restored->facts);
    }

    public function test_cancel_check_is_frozen(): void
    {
        $caller = $this->caller();

        // 没有执行 ID（同步路径）→ 不查库，直接 false
        $this->assertFalse($caller->isCancelled(null));

        $tenant = Tenant::query()->create([
            'subdomain' => 'model-caller',
            'name' => 'CALLER',
            'plan' => 'basic',
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => '13800000017',
            'role' => 'admin',
        ]);
        // tenant_id 由 BelongsToTenant 从上下文写入，查询也走全局租户作用域 ——
        // 队列路径之所以每次都查得到，就是因为 Job 里先设了上下文；这里照同一套来。
        TenantContext::set($tenant);
        try {
            $project = Project::query()->create([
                'owner_id' => $user->id,
                'name' => 'Caller Project',
                'brand_name' => 'Acme',
                'brand_url' => 'https://acme.example.test',
                'settings' => [],
            ]);
            $execution = SkillExecution::query()->create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'skill_id' => 'yao-geo-panorama-audit',
                'skill_name' => '全景诊断',
                'category' => 'geo_strategy',
                'stage_index' => 1,
                'status' => 'running',
                'execution_mode' => 'async',
                'input_data' => [],
            ]);

            $this->assertFalse($caller->isCancelled($execution->id), 'running 不算取消');

            $execution->forceFill(['status' => ExecutionStatus::Cancelled->value])->save();
            $this->assertTrue($caller->isCancelled($execution->id), 'cancelled 必须拦住后续分段的调用');

            $this->assertFalse($caller->isCancelled($execution->id + 100000), '执行不存在时当作没取消');
        } finally {
            TenantContext::forget();
        }
    }
}
