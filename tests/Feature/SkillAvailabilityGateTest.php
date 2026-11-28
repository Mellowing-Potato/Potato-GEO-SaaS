<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\SkillDefinition;
use App\Services\SkillAgent\SkillRegistryService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 技能可用性闸门。
 *
 * 2026-08-07 查出：RenderBridge 固定用 `--input <json> --output-dir <目录>` 调
 * 渲染脚本，而「排名型文章」和「知识库构建」两个脚本要的是 `--source <markdown>`
 * —— 参数名不对，要的东西也不对。argparse 在 main() 第一行就退出，渲染必然失败，
 * 客户只拿到一段裸文字，界面还什么都不提示。
 *
 * 适配层做出来后两个都恢复了：知识库构建当天恢复（质检 20/20 全过、四种格式齐全），
 * 排名型文章 2026-08-20 恢复（三种格式，Word 本来就不在产品要求里，
 * 见 SkillRegistryService 里它自己的注释）。**现在没有任何技能处于停用状态。**
 *
 * 这里守三件事：
 *   1) 真注册表里谁都不该被停用 —— 多停一个就是白白砍掉客户能用的能力；
 *   2) 列表接口要如实告诉前端「不可用 + 为什么」，前端据此置灰；
 *   3) 执行接口自己也要挡 —— 只靠前端置灰，直接打接口就绕过去了。
 *
 * 2 和 3 是闸门机制本身，跟「此刻有没有技能被停用」是两回事，所以用
 * StubDisabledSkillRegistry 造一个假的停用技能来测，不再依赖真有一个技能坏着。
 */
class SkillAvailabilityGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 当前停用的技能。停用一个就往这里加一个，同时在 SkillRegistryService 里
     * 写清「客户为什么点不了」。
     */
    private const DISABLED = [];

    /** 闸门机制自己的测试用哪个技能装停用（真实状态是可用的）。 */
    private const STUB_DISABLED_ID = 'yao-geo-ranking-article-builder';

    private const STUB_REASON = '闸门自测：这条原因是测试注入的，真注册表里它是可用的。';

    /** 把容器里的注册表换成「STUB_DISABLED_ID 被停用」的那一版。 */
    private function stubOneSkillDisabled(): void
    {
        $this->app->singleton(SkillRegistry::class, fn() => new StubDisabledSkillRegistry(
            self::STUB_DISABLED_ID,
            self::STUB_REASON,
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['geo.skill_execution_claim_required' => false]);
        // 本组测「模块已开放时」的技能可用性；独立面板各自有总开关，
        // 开关关闭时入口置灰是预期行为，不属于技能实现损坏。
        config([
            'app.tenant_domain' => 'geo.example.test',
            'geo.visibility_panel_enabled' => true,
            'geo.manual_publish_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    /** @return array{0: Tenant, 1: User} */
    private function signIn(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            'role' => 'admin',
        ]);
        Sanctum::actingAs($admin, ['*']);

        $this->withServerVariables(['HTTP_HOST' => $subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $subdomain);

        return [$tenant, $admin];
    }

    private function projectFor(Tenant $tenant, User $owner): Project
    {
        TenantContext::set($tenant);

        return Project::query()->create([
            'tenant_id' => $tenant->id,
            'owner_id' => $owner->id,
            'name' => '闸门测试项目',
            'status' => 'research',
        ]);
    }

    public function test_registry_marks_exactly_the_incompatible_skills_unavailable(): void
    {
        $registry = new SkillRegistryService();

        $disabled = [];
        foreach ($registry->all() as $skill) {
            if (! $skill->isAvailable()) {
                $disabled[] = $skill->id;
                $this->assertNotEmpty($skill->unavailableReason, "{$skill->id} 停用了却没写原因");
            }
        }

        sort($disabled);
        $expected = self::DISABLED;
        sort($expected);

        // 多停用一个 = 无故砍掉客户能用的能力；少停用一个 = 客户又会拿到残次品。
        $this->assertSame($expected, $disabled);
    }

    public function test_ranking_article_builder_is_open_to_customers(): void
    {
        // 2026-08-20：它曾因为「缺 Word」被整个停掉，而产品要求的三种格式
        // （Markdown / 网页 / PDF）一直是产出得了的。别再因为第四种格式关掉前三种。
        $skill = (new SkillRegistryService())->find('yao-geo-ranking-article-builder');

        $this->assertTrue($skill->isAvailable(), '排名型文章不该再被停用');
        $this->assertNull($skill->unavailableReason);
    }

    public function test_no_skill_is_disabled_right_now(): void
    {
        // 上面那条是逐字比对，这条把话说明白：此刻客户能点到全部技能。
        [$tenant] = $this->signIn('gate-all-open');

        $skills = collect($this->getJson('/api/v1/skills')->assertOk()->json('data'));
        $this->assertNotEmpty($skills, '技能列表不该是空的');

        $blocked = $skills->reject(fn($s) => $s['available'] === true)->pluck('id')->all();
        $this->assertSame([], $blocked, '这些技能客户点不了：' . implode(', ', $blocked));
    }

    public function test_skills_endpoint_exposes_availability_and_reason(): void
    {
        // 闸门机制本身：注册表说某个技能停用时，列表接口必须如实转达「不可用 + 为什么」。
        $this->stubOneSkillDisabled();
        [$tenant] = $this->signIn('gate-list');

        $response = $this->getJson('/api/v1/skills')->assertOk();

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertNotEmpty($byId, '技能列表不该是空的');

        $this->assertFalse($byId[self::STUB_DISABLED_ID]['available'], '停用的技能应当标为不可用');
        $this->assertSame(self::STUB_REASON, $byId[self::STUB_DISABLED_ID]['unavailable_reason'], '要给客户一句原因');

        // 其余技能必须照常可用 —— 别把整片产品误伤了。
        $stillUsable = $byId->reject(fn($s) => $s['id'] === self::STUB_DISABLED_ID);
        $this->assertGreaterThan(0, $stillUsable->count());
        foreach ($stillUsable as $skill) {
            $this->assertTrue($skill['available'], "{$skill['id']} 被误伤了");
            $this->assertNull($skill['unavailable_reason']);
        }
    }

    public function test_execute_rejects_a_disabled_skill(): void
    {
        // 前端置灰只是第一道；直接打接口一样要挡住，否则停用等于没停用。
        $this->stubOneSkillDisabled();
        [$tenant, $admin] = $this->signIn('gate-exec');
        $project = $this->projectFor($tenant, $admin);

        $this->postJson('/api/v1/skills/execute', [
            'skill_id' => self::STUB_DISABLED_ID,
            'project_id' => $project->id,
        ])->assertStatus(422)
          ->assertJsonPath('message', self::STUB_REASON);
    }

    public function test_execute_async_rejects_a_disabled_skill(): void
    {
        $this->stubOneSkillDisabled();
        [$tenant, $admin] = $this->signIn('gate-async');
        $project = $this->projectFor($tenant, $admin);

        $this->postJson('/api/v1/skills/execute-async', [
            'skill_id' => self::STUB_DISABLED_ID,
            'project_id' => $project->id,
        ])->assertStatus(422);
    }
}

/**
 * 真注册表的复制品，只把指定的一个技能改成停用。
 *
 * 用它而不是「挑一个真坏着的技能」来测闸门：闸门机制得一直有测试守着，
 * 不该因为某天所有技能都修好了就没人测了。
 */
class StubDisabledSkillRegistry extends SkillRegistryService
{
    private ?array $patched = null;

    public function __construct(
        private readonly string $disabledId,
        private readonly string $reason,
    ) {}

    public function all(): array
    {
        return $this->patched ??= array_map(
            fn(SkillDefinition $s) => $s->id === $this->disabledId ? $this->disable($s) : $s,
            parent::all(),
        );
    }

    /** SkillDefinition 是 final readonly，改不了，只能照原样重建一个。 */
    private function disable(SkillDefinition $s): SkillDefinition
    {
        return new SkillDefinition(
            id: $s->id,
            name: $s->name,
            category: $s->category,
            maturity: $s->maturity,
            requiresWeb: $s->requiresWeb,
            stageIndex: $s->stageIndex,
            description: $s->description,
            sceneDescription: $s->sceneDescription,
            effectDescription: $s->effectDescription,
            briefFields: $s->briefFields,
            renderScriptPath: $s->renderScriptPath,
            referencePaths: $s->referencePaths,
            actionPath: $s->actionPath,
            actionLabel: $s->actionLabel,
            unavailableReason: $this->reason,
        );
    }
}
