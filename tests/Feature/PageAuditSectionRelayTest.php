<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSkillJob;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\SkillExecutionRelay;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Content\PromptTemplateService;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\DTOs\SkillBrief;
use App\Services\DTOs\SkillDefinition;
use App\Services\Enums\SkillCategory;
use App\Services\SkillAgent\PageFetchService;
use App\Services\SkillAgent\PageObservation;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 页面审计按章节分节生成。
 *
 * 这一批修的是什么 —— 页面审计一次调用根本产不完这份报告：
 * 网关按「这次请求还剩多少秒」反算 max_tokens（超时 280 秒、实测约 17.9 毫秒/词元），
 * 单次天花板落在 12k 词元上下。2026-09-07 生产 execution 53 实证：
 * 请求 16384、实得 12810、耗时 228.9 秒、finish_reason=length，
 * 报告写到第 3 节就断了，客户被扣 ¥0.16 拿到半份东西。
 *
 * 原来的分段机器只会切 JSON 字段（切完交给 Python 渲染脚本排版），
 * 而页面审计没有渲染脚本、直出 Markdown。本批给它加了「按章节切」这条路。
 *
 * 本文件守三件事：
 *   1. 一节一个队列任务，拼起来是一份**顺序正确、干净**的报告；
 *   2. 分节路绝不能被强制成 JSON —— 那会把正文整个毁掉；
 *   3. 一节写不完时，客户已经付过的钱要能换回已经写出来的字。
 */
class PageAuditSectionRelayTest extends TestCase
{
    use RefreshDatabase;

    private const SKILL = 'yao-geo-page-audit';

    /** 每次 generate() 的入参，按顺序 */
    private array $calls = [];

    private int $fetchCount = 0;

    private Tenant $tenant;

    private User $user;

    private Project $project;

    private SkillExecution $execution;

    // ── 分节谱本身 ───────────────────────────────────────────

    /**
     * 分节谱要覆盖 SKILL.md 里那 16 个必备模块，而且键不能重复。
     *
     * 键重复＝两节写进同一个格子，后写的把先写的顶掉，报告会凭空少一节，
     * 而完整性闸只看「这一节交没交东西」，两节共用一个格子时它是察觉不到的。
     */
    public function test_the_section_plan_is_well_formed(): void
    {
        $specs = $this->specs();

        $this->assertGreaterThanOrEqual(5, count($specs), '切得太粗就还会撞天花板');

        $keys = array_column($specs, 'key');
        $this->assertSame($keys, array_unique($keys), '分节谱的 key 不能重复');

        $fields = array_merge(...array_column($specs, 'fields'));
        $this->assertSame($fields, array_unique($fields), '各节的存放键不能重复，否则后一节会顶掉前一节');

        foreach ($specs as $spec) {
            $this->assertSame('markdown', $spec['format'] ?? null, "第「{$spec['key']}」节没有标成 markdown");
            $this->assertCount(1, $spec['fields'], '分节谱一节只对应一个存放键');
            $this->assertNotEmpty($spec['modules'] ?? [], "第「{$spec['key']}」节没写要覆盖哪些模块");
            $this->assertNotEmpty($spec['target_chars'] ?? null, "第「{$spec['key']}」节没有篇幅目标，等于放任它再撞一次天花板");
        }

        // 只有第一节写报告标题。多于一节写一级标题的话，拼完是几份报告摞在一起。
        $leads = array_filter($specs, fn (array $spec): bool => (bool) ($spec['lead'] ?? false));
        $this->assertCount(1, $leads);
        $this->assertSame($specs[0]['key'], array_values($leads)[0]['key']);
    }

    /**
     * 分节谱是给「直出 Markdown」的技能用的；有渲染脚本的技能必须还走字段谱。
     * 拿错谱的后果是致命的：字段谱那条路会强制 json_object，Markdown 正文当场报废。
     */
    public function test_the_section_plan_is_only_used_when_there_is_no_render_script(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $lookup = new ReflectionMethod($runner, 'chunkSpecsFor');

        $this->assertNull(
            $lookup->invoke($runner, self::SKILL, true),
            '页面审计要是哪天有了渲染脚本，就该走字段谱，不该再按章节切',
        );

        // 全景审计名字里也含 audit，别被 page-audit 的匹配串抢走。
        $panorama = $lookup->invoke($runner, 'yao-geo-panorama-audit', false);
        $this->assertNull($panorama, '没有渲染脚本的全景审计不该落到分节谱上');
    }

    // ── 接力主干 ─────────────────────────────────────────────

    /**
     * 一节一个队列任务，最后拼成一份顺序正确的报告。
     *
     * 「每个任务最多一次模型请求」这一条塌了，接力就退化回单进程那个必死的形状
     * （一个 PHP 进程只有 set_time_limit(420) 秒，七节十来分钟装不下）。
     */
    public function test_the_report_is_written_one_section_per_job_and_assembled_in_order(): void
    {
        $this->bootRelay($this->sectionResponses());

        $callsPerStep = $this->drive();

        $this->assertSame(0, $callsPerStep[0], '第 0 步只做准备，不该发模型请求');
        $this->assertSame(
            array_fill(0, $this->sectionCount(), 1),
            array_values(array_slice($callsPerStep, 1, -1)),
            '每个队列任务必须恰好写一节、发一次模型请求',
        );
        $this->assertSame(0, end($callsPerStep), '收尾那一步不该发模型请求');

        $this->execution->refresh();
        $this->assertSame('completed', $this->execution->status);

        $report = $this->execution->output_data['result_text'] ?? '';

        // 顺序必须照谱走，不能照写完的先后 —— 补跑会打乱完成顺序。
        $positions = [];
        foreach ($this->specs() as $spec) {
            $marker = $this->sectionBody($spec);
            $this->assertStringContainsString($marker, $report, "报告里缺了「{$spec['label']}」这一节");
            $positions[] = mb_strpos($report, $marker);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, '各节没有按谱的顺序拼');

        // 🔴 交付物必须干净：raw_output 会被原样落进 output_data.result_text 给客户看，
        //    字段谱那条路往里塞的 <!-- chunk: --> 诊断注释绝不能跟着漏出去。
        $this->assertStringNotContainsString('<!-- chunk:', $report);

        $this->assertSame('markdown', $this->execution->output_data['chunk_format'] ?? null);
        $this->assertSame(0, SkillExecutionRelay::count(), '收尾后接力状态行应被删除');
    }

    public function test_markdown_quality_checks_use_page_evidence_and_article_keeps_the_same_score(): void
    {
        $responses = $this->sectionResponses();
        $overview = $this->specs()[0];
        $responses[0] = $this->response(
            $this->sectionBody($overview)
            ."\n\n实测页面返回 HTTP 200，canonical、robots.txt、sitemap 和 JSON-LD 均已检查。",
        );

        $this->bootRelay($responses);
        $this->drive();

        $this->execution->refresh();
        $quality = $this->execution->quality_report;
        $checks = collect($quality['checks'] ?? [])->keyBy('name');

        $this->assertTrue($quality['passed'] ?? false);
        $this->assertTrue($checks->get('页面实测证据')['passed'] ?? false);
        $this->assertFalse($checks->has('JSON 解析'), 'Markdown 报告不应该被判一次 JSON 解析失败');
        $this->assertFalse($checks->has('数据引用'), '页面审计应该检查实测页面证据，而不是只找百分号');
        $this->assertSame(
            $quality['score'],
            $this->execution->article()->value('quality_score'),
            '文章必须保存本次执行刚算出的质量分，不能在执行落库前读到默认 0 分',
        );
    }

    /**
     * 🔴 分节路绝不能开 json_object。
     *
     * 那个开关是给字段谱用的（回复要 json_decode 后交给渲染脚本）。开在分节路上，
     * 模型会把整节正文塞进一个字符串字段或者写出一坨转义文本，报告直接报废。
     */
    public function test_sections_are_asked_for_markdown_not_json(): void
    {
        $this->bootRelay($this->sectionResponses());
        $this->drive();

        $this->assertNotEmpty($this->calls);
        foreach ($this->calls as $index => $call) {
            $this->assertFalse(
                (bool) ($call['options']['json_object'] ?? false),
                "第 {$index} 次调用开了 json_object，Markdown 正文会被毁掉",
            );
        }
    }

    /** 只有第一节写报告标题，其余节从二级标题起 —— 否则拼完是七份报告摞在一起。 */
    public function test_only_the_first_section_writes_the_report_title(): void
    {
        $this->bootRelay($this->sectionResponses());
        $this->drive();

        $this->assertStringContainsString('一级标题', $this->calls[0]['prompt']);
        $this->assertStringContainsString('不要写一级标题', $this->calls[1]['prompt']);
    }

    /** 每一节都要被告知本节该覆盖哪些模块、写多长。 */
    public function test_each_section_is_told_what_to_cover_and_how_long(): void
    {
        $this->bootRelay($this->sectionResponses());
        $this->drive();

        foreach ($this->specs() as $index => $spec) {
            $prompt = $this->calls[$index]['prompt'];
            $this->assertStringContainsString($spec['label'], $prompt);
            $this->assertStringContainsString((string) $spec['target_chars'], $prompt);
            foreach ($spec['modules'] as $module) {
                $this->assertStringContainsString($module, $prompt, "第「{$spec['key']}」节漏了要覆盖的模块");
            }
        }
    }

    /**
     * 后面的节要看得见前面写了什么，否则修复清单和路线图会引用一批不存在的结论。
     * 但只带每节开头一段 —— 不截的话最后一节的提示词里会拖着前六节全文，输入词元
     * 一路涨，一份报告多花一倍多的钱。
     */
    public function test_later_sections_see_the_earlier_ones_but_only_the_opening_part(): void
    {
        // 末尾放一句独一无二的话：它出现在后一节的提示词里，就说明整篇被塞了进去。
        $long = '开头就是结论。'.str_repeat('这里是正文细节。', 400).'【这是本节的最后一句】';

        $responses = [];
        foreach ($this->specs() as $index => $spec) {
            $responses[] = $this->response($index === 0 ? $long : $this->sectionBody($spec));
        }

        $this->bootRelay($responses);
        $this->drive();

        $second = $this->calls[1]['prompt'];
        $this->assertStringContainsString('开头就是结论。', $second, '后面的节看不到前面写了什么');
        $this->assertStringContainsString('本节后半略', $second, '前面的节没有被截断，输入词元会一路涨');
        $this->assertStringNotContainsString(
            '【这是本节的最后一句】', $second,
            '前一节被整篇塞进了后一节的提示词：输入词元会一路涨，一份报告多花一倍多的钱',
        );

        // 章节顺序要一起给：不然模型会在中间某节写「综上所述」，或者把后面几节提前写完。
        foreach ($this->specs() as $spec) {
            $this->assertStringContainsString($spec['label'], $second);
        }
    }

    /** 分节路没有渲染脚本，收尾不该去碰渲染管线。 */
    public function test_the_markdown_path_never_calls_the_render_bridge(): void
    {
        $this->bootRelay($this->sectionResponses(), expectRender: false);
        $this->drive();

        $this->execution->refresh();
        $this->assertSame('completed', $this->execution->status);
        $this->assertArrayNotHasKey('structured', $this->execution->output_data ?? []);
    }

    // ── 写不完的时候 ─────────────────────────────────────────

    /**
     * 一节写超了被截断 → 压短篇幅重写一次，而不是整单判失败。
     *
     * 整单失败意味着客户要点重试、重跑全部七节、再付一次钱 —— 那是拿我们的缺陷
     * 收客户第二次钱。补跑一次只花一次调用。
     */
    public function test_a_truncated_section_is_rewritten_shorter_instead_of_failing_the_report(): void
    {
        $specs = $this->specs();
        $responses = [$this->response(str_repeat('写超了。', 200), finish: 'length')];
        foreach ($specs as $spec) {
            $responses[] = $this->response($this->sectionBody($spec));
        }

        $this->bootRelay($responses);
        $this->drive();

        $this->execution->refresh();
        $this->assertSame('completed', $this->execution->status, '一节被截断就整单失败＝让客户为我们的缺陷再付一次钱');

        // 重写那一次要说清「上次是写超了」，并把篇幅目标压下来 ——
        // 不说清楚的话模型会以为是内容有问题，转而去删该写的条目。
        $retry = $this->calls[1]['prompt'];
        $this->assertStringContainsString('篇幅超了', $retry);
        $this->assertStringContainsString(
            (string) (int) round($specs[0]['target_chars'] * 0.7), $retry,
            '重写没有把篇幅目标压下来，会再被截断一次',
        );

        $report = $this->execution->output_data['result_text'] ?? '';
        $this->assertStringNotContainsString('写超了。', $report, '被截断的半截正文不该混进成功的报告');
        $this->assertStringContainsString($this->sectionBody($specs[0]), $report);
    }

    /**
     * 重写也没写完 → 整单判失败，但已经写出来的字要交回给客户，并标清楚哪一节没写完。
     *
     * 钱已经扣了，不能让客户既付了钱又一个字都拿不到；
     * 也不能不加标记地混进去，那会让他以为这节本来就这么短。
     */
    public function test_a_section_that_never_finishes_fails_the_run_but_keeps_what_was_written(): void
    {
        $specs = $this->specs();
        $responses = [
            $this->response('第一次写到一半就断了。'.str_repeat('细节。', 50), finish: 'length'),
            $this->response('重写也没写完。'.str_repeat('细节。', 50), finish: 'length'),
        ];
        foreach (array_slice($specs, 1) as $spec) {
            $responses[] = $this->response($this->sectionBody($spec));
        }

        $this->bootRelay($responses);
        $this->drive();

        $this->execution->refresh();
        $this->assertSame('failed', $this->execution->status);

        // 量词要说对：分节谱拆的是报告目录上真有的「节」，不是内部分片的「段」。
        $this->assertStringContainsString('节', (string) $this->execution->error_message);
        $this->assertStringContainsString($specs[0]['label'], (string) $this->execution->error_message);

        $report = $this->execution->output_data['result_text'] ?? '';
        $this->assertStringContainsString('重写也没写完。', $report, '客户付了钱，写出来的字必须交回去');
        $this->assertStringContainsString('没有写完', $report, '半截正文必须标清楚，不能让客户以为这节就这么短');
        // 后面那些写成了的节照样要在
        $this->assertStringContainsString($this->sectionBody($specs[1]), $report);
    }

    // ── 抓页面与分节的交界 ───────────────────────────────────

    /**
     * 一次执行只抓一遍客户的站点。
     *
     * 接力每一步都是新进程、都会重走一遍准备流程；抓取结果不存进接力状态的话，
     * 一份七节的报告会把客户的站点抓九遍。
     */
    public function test_the_page_is_fetched_once_for_the_whole_relay(): void
    {
        $this->bootRelay($this->sectionResponses());
        $this->drive();

        $this->assertSame(1, $this->fetchCount, "一次执行抓了 {$this->fetchCount} 遍客户的站点");
    }

    /** 抓到的事实要进每一节的提示词，不能只进第一节。 */
    public function test_every_section_gets_the_fetched_facts(): void
    {
        $this->bootRelay($this->sectionResponses());
        $this->drive();

        foreach ($this->calls as $index => $call) {
            $this->assertStringContainsString(
                '孟德智达 — AI 搜索优化', $call['prompt'],
                "第 {$index} 节没拿到本次抓取的事实，它只能靠印象写",
            );
            $this->assertStringContainsString(
                '事实纪律', $call['prompt'],
                "第 {$index} 节没被告知「观察到的结论必须能追溯到本次抓取」",
            );
        }
    }

    /**
     * 抓不到页面就不开接力：让它回落到单进程那条路，由那里唯一的一处
     * 「抓不到就零扣费判失败」收场。失败处理只能有一份实现。
     */
    public function test_a_failed_fetch_does_not_start_a_relay(): void
    {
        $this->bootRelay(
            [],
            observation: PageObservation::failure(
                'https://example.com/gone', 'http_error', '目标页面返回 404，页面不存在或已经下线，请确认地址是否正确。',
            ),
        );

        $this->runStep(0);

        $this->execution->refresh();
        $this->assertSame('failed', $this->execution->status);
        $this->assertSame([], $this->calls, '抓不到页面还去调模型，就是让客户为我们拿不到原料买单');
        $this->assertSame(0, SkillExecutionRelay::count(), '不该留下接力状态行');
        Queue::assertNotPushed(ExecuteSkillJob::class);
    }

    /**
     * 同进程那条路装不下分节报告（七节十来分钟 vs set_time_limit(420)），
     * 所以它退回单次调用 —— 同样是失败，只收客户一次钱，不是跑两节再判失败。
     */
    public function test_the_in_process_path_does_not_try_to_write_sections(): void
    {
        $this->bootRelay([$this->response('# 一次写完的报告'.str_repeat('正文内容。', 10))]);
        $this->execution->update(['status' => 'running']);

        TenantContext::set($this->tenant);
        try {
            $result = app(SkillAgentRunner::class)->execute($this->brief());
        } finally {
            TenantContext::forget();
        }

        $this->assertCount(1, $this->calls, '同进程路不该按章节切：一个 PHP 进程装不下七节');
        $this->assertTrue($result->success);
    }

    // ── 装配 ─────────────────────────────────────────────────

    /** @return list<int> 每一步各发了几次模型请求 */
    private function drive(int $guard = 30): array
    {
        $callsPerStep = [];

        for ($step = 0; $step <= $guard; $step++) {
            $before = count($this->calls);
            $this->runStep($step);
            $callsPerStep[] = count($this->calls) - $before;

            $dispatched = Queue::pushed(
                ExecuteSkillJob::class,
                fn (ExecuteSkillJob $job): bool => $job->step === $step + 1,
            )->isNotEmpty();

            if (! $dispatched) {
                return $callsPerStep;
            }
        }

        $this->fail("接力步数超过 {$guard} 步仍未收尾，八成是自己派发自己停不下来了");
    }

    private function runStep(int $step): void
    {
        $job = new ExecuteSkillJob($this->execution->id, $this->brief()->toArray(), $this->tenant->id, $step);
        $this->app->call([$job, 'handle']);
    }

    private function brief(): SkillBrief
    {
        return new SkillBrief(
            self::SKILL, $this->project->id, $this->user->id,
            ['url' => 'http://example.test', 'brand_name' => '孟德智达'],
            executionId: $this->execution->id,
        );
    }

    /** @param list<AiProviderCallResult> $responses 按调用顺序返回 */
    private function bootRelay(
        array $responses,
        ?PageObservation $observation = null,
        bool $expectRender = false,
    ): void {
        Http::preventStrayRequests();
        Queue::fake();

        // 必须显式设：config/geo.php 里这个开关默认关着（跟着 env 走），
        // 不设的话整个文件都会静悄悄地走单次调用，测试全绿却什么都没守住。
        config(['geo.analysis_chunked' => true]);

        $this->tenant = Tenant::create([
            'subdomain' => 'page-sections', 'name' => 'Sections', 'plan' => 'basic', 'status' => 'active',
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        TenantContext::set($this->tenant);
        $this->project = Project::create([
            'owner_id' => $this->user->id, 'name' => '孟德智达',
            'brand_name' => '孟德智达', 'brand_url' => 'https://example.test',
        ]);
        $this->execution = SkillExecution::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $this->user->id,
            'skill_id' => self::SKILL, 'skill_name' => '页面审计', 'category' => SkillCategory::GeoPageTechnical->value,
            'stage_index' => 3, 'status' => 'pending', 'execution_mode' => 'async', 'input_data' => [],
        ]);
        TenantContext::forget();

        // 无渲染脚本、直出 Markdown —— 这正是分节谱的适用形状。
        $assets = new SkillAssets(self::SKILL, '# 页面审计方法论', [], null, null, true);

        $registry = $this->createMock(SkillRegistry::class);
        $registry->method('find')->willReturn(new SkillDefinition(
            id: self::SKILL, name: '页面审计', category: SkillCategory::GeoPageTechnical,
            maturity: 'beta', requiresWeb: true, stageIndex: 3, description: '',
        ));

        $assetLoader = $this->createMock(SkillAssetLoader::class);
        $assetLoader->method('loadAll')->willReturn($assets);

        $prompts = $this->createMock(PromptTemplateService::class);
        $prompts->method('applyOverride')->willReturn($assets);

        $renderBridge = $this->createMock(RenderBridgeService::class);
        $renderBridge->method('hasRenderScript')->willReturn(false);
        $renderBridge->expects($expectRender ? $this->atLeastOnce() : $this->never())->method('render');

        $fetch = $this->createMock(PageFetchService::class);
        $fetch->method('fetch')->willReturnCallback(function () use ($observation): PageObservation {
            $this->fetchCount++;

            return $observation ?? $this->observed();
        });

        $provider = $this->createMock(AiProvider::class);
        $provider->method('generate')->willReturnCallback(
            function ($prompt, $system, $options) use ($responses): AiProviderCallResult {
                $index = count($this->calls);
                $this->calls[] = compact('prompt', 'system', 'options');

                if (! isset($responses[$index])) {
                    $this->fail('模型被多调了一次：第 '.($index + 1).' 次调用没有对应的预置返回');
                }

                return $responses[$index];
            },
        );

        $this->app->instance(SkillAgentRunner::class, new SkillAgentRunnerService(
            $registry, $provider, $this->createMock(KnowledgeRetrieval::class),
            $assetLoader, $renderBridge, $prompts, app(ModelUsageRecorder::class),
            null, null, $fetch,
        ));
    }

    private function observed(): PageObservation
    {
        return PageObservation::success('http://example.test', 'https://example.test/', 200, [
            'head' => ['title' => '孟德智达 — AI 搜索优化', 'canonical' => 'https://example.test/'],
            'heading_outline' => ['h1_count' => 1, 'level_skips' => []],
            'structured_data' => ['json_ld_types' => ['Organization']],
        ]);
    }

    /** 分节谱从代码推导，不写死 —— 节数或标题改了，用例要跟着动而不是悄悄失真。 */
    private function specs(): array
    {
        $runner = app(SkillAgentRunnerService::class);

        return (new ReflectionMethod($runner, 'chunkSpecsFor'))->invoke($runner, self::SKILL, false);
    }

    private function sectionCount(): int
    {
        return count($this->specs());
    }

    /**
     * 每一节一段可辨认的正文，用来验顺序与拼接。
     *
     * ⚠️ 必须超过 40 字。短于这个数会被当成「模型交白卷」而触发升级重调，
     *    一节就变成两次模型请求 —— 「每个队列任务只发一次请求」那条断言会当场失真，
     *    而且是绿着失真（实测踩过）。
     */
    private function sectionBody(array $spec): string
    {
        return "## {$spec['label']}\n\n这一节的正文（{$spec['key']}）。"
            .str_repeat('这里是本节的具体内容。', 3);
    }

    /** @return list<AiProviderCallResult> 每节一次完整作答 */
    private function sectionResponses(): array
    {
        return array_map(
            fn (array $spec): AiProviderCallResult => $this->response($this->sectionBody($spec)),
            $this->specs(),
        );
    }

    private function response(string $content, string $finish = 'stop', int $tokens = 900): AiProviderCallResult
    {
        return new AiProviderCallResult(
            success: true, provider: 'gateway', model: 'text', content: $content,
            promptTokens: 100, completionTokens: max(0, $tokens - 100), totalTokens: $tokens, finishReason: $finish,
        );
    }
}
