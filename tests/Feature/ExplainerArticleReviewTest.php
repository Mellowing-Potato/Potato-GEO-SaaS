<?php

namespace Tests\Feature;

use App\Services\DTOs\SkillBrief;
use App\Services\SkillAgent\SkillAgentRunnerService;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExplainerArticleReviewTest extends TestCase
{
    public function test_appended_steps_are_included_in_the_actual_python_brand_review(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $data = $this->prepare($runner, [
            'title' => '如何选择服务',
            'target_brand' => '品牌甲',
            'max_brand_mentions' => 3,
            'core_answer' => '品牌甲是一种可选方案。',
            'article_sections' => [['heading' => '判断标准', 'paragraphs' => [str_repeat('字', 1800)]]],
            'steps' => [['title' => '核对', 'action' => str_repeat('品牌甲可供比较。', 30), 'check' => '查看资料。']],
            // 不能相信模型另填的成品字段，必须由实际正文结构重建。
            'publishable_article_markdown' => '未经检查的模型替代正文',
        ]);
        $this->assertStringContainsString('## 实施检查清单', $data['publishable_article_markdown']);
        $reviewed = $this->pythonReview($data);
        $check = array_column($reviewed['report']['checks'], null, 'name')['brand_mentions_within_limit'];
        $this->assertSame(31, $check['mentions']);
        $this->assertFalse($check['passed']);
        $this->assertFalse($this->finalReview($runner, $reviewed['report'], $reviewed['article'])['passed']);
    }

    public function test_research_mentions_are_excluded_and_normalized_final_article_is_checked(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $data = $this->prepare($runner, [
            'title' => '服务选择指南',
            'target_brand' => '品牌甲',
            'max_brand_mentions' => 3,
            'core_answer' => '品牌甲可供选择。',
            'article_sections' => [['heading' => '选择标准', 'paragraphs' => [str_repeat('字', 2100).'保证行业第一。']]],
            'steps' => [],
            'research_basis' => [str_repeat('品牌甲', 30)],
        ]);
        $reviewed = $this->pythonReview($data);
        $this->assertStringNotContainsString('保证行业第一', $reviewed['article']);
        $this->assertStringContainsString('可作为较有代表性的候选方案', $reviewed['article']);
        $this->assertSame(hash('sha256', $reviewed['article']), $reviewed['report']['publishable_article_sha256']);
        $checks = array_column($reviewed['report']['checks'], null, 'name');
        $this->assertSame(1, $checks['brand_mentions_within_limit']['mentions']);
        $this->assertTrue($this->finalReview($runner, $reviewed['report'], $reviewed['article'])['passed']);
        // 即使渲染器质检通过，也不能换回渲染前正文或在检查后追加内容。
        foreach ([$data['publishable_article_markdown'], $reviewed['article'].'品牌甲'] as $changedArticle) {
            $report = $this->finalReview($runner, $reviewed['report'], $changedArticle);
            $this->assertFalse($report['passed']);
            $this->assertContains('publishable_article_matches_review', $report['issues']);
            $this->assertNotSame('可交付', $report['next_action']);
        }
    }

    public function test_an_old_or_missing_review_cannot_certify_the_final_article(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $body = str_repeat('字', 2100);
        $report = $this->finalReview($runner, [
            'passed' => true,
            'checks' => [['name' => 'brand_mentions_within_limit', 'passed' => true, 'mentions' => 1, 'limit' => 3]],
        ], $body);
        $this->assertFalse($report['passed']);
        $this->assertContains('publishable_article_matches_review', $report['issues']);
        $report = $this->finalReview($runner, [
            'passed' => false, 'checks' => [], 'publishable_article_sha256' => hash('sha256', $body),
        ], $body);
        $this->assertFalse($report['passed'], '不能仅凭正文长度和散列覆盖其他未列明的失败原因');
    }

    public function test_explicit_warnings_survive_normalization_without_exempting_later_claims(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        foreach ([
            ['警惕那些保证“100%收录”或“第一名”的服务商。', '警惕那些保证“100%收录”或“第一名”的服务商。'],
            ['避免承诺100%效果。', '避免承诺100%效果。'],
            ['不应承诺100%效果。', '不应承诺100%效果。'],
            ['不能承诺100%效果。', '不能承诺100%效果。'],
            ['警惕承诺100%效果。', '警惕承诺100%效果。'],
            ['选择服务商时，应避免所谓的保证100%效果。', '选择服务商时，应避免所谓的保证100%效果。'],
            ['不应保证100%收录。', '不应保证100%收录。'],
            ['我们不保证100%收录。', '我们不保证100%收录。'],
            ['我们保证100%收录。', '我们无法承诺百分之百收录。'],
            ['我们的服务保证“100%效果”。', '我们的服务无法承诺“百分之百效果”。'],
            ['警惕夸大宣传，但我们的服务保证100%收录。', '警惕夸大宣传，但我们的服务无法承诺百分之百收录。'],
            ['我们不能保证收录，而且我们保证行业第一。', '我们不能保证收录，而且我们可作为较有代表性的候选方案。'],
            ['不是保证100%就一定有效。', '不是保证100%就一定有效。'],
            ['没有任何公司能保证100%收录。', '没有任何公司能保证100%收录。'],
            ['不应声称自己是行业第一。', '不应声称自己是行业第一。'],
            ['没有夸大宣传就能保证100%收录。', '不夸大宣传也不能承诺百分之百收录。'],
            ['覆盖100%用户。', '用户覆盖范围需以核验结果为准。'],
        ] as [$sentence, $expected]) {
            $data = $this->prepare($runner, [
                'title' => '服务选择指南', 'target_brand' => '品牌甲',
                'core_answer' => '品牌甲可供选择。',
                'article_sections' => [['heading' => '核验', 'paragraphs' => [str_repeat('字', 2100).'。'.$sentence]]],
            ]);
            $reviewed = $this->pythonReview($data);
            $this->assertStringContainsString($expected, $reviewed['article']);
            $checks = array_column($reviewed['report']['checks'], null, 'name');
            $this->assertTrue($checks['risky_claims_neutralized']['passed']);
            $this->assertTrue($this->finalReview($runner, $reviewed['report'], $reviewed['article'])['passed']);
        }
    }

    public function test_production_unverified_claims_are_softened_in_the_delivered_article_without_a_model_retry(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $data = $this->prepare($runner, [
            'title' => '四川 GEO 服务说明',
            'target_brand' => '品牌甲',
            'core_answer' => '品牌甲可供企业比较。',
            'article_sections' => [[
                'heading' => '常见问题',
                'paragraphs' => [
                    str_repeat('字', 2050).'。',
                    '如果外包，通常每月数千到数万元不等。',
                    '答：一般需要3-6个月，因为AI搜索需要时间抓取。',
                    'AI搜索会结合用户地理位置提供答案。',
                    '示例本地竞争相对较小，更容易获得引用。',
                    '执行后咨询量提升20%。',
                ],
            ]],
        ]);

        $reviewed = $this->pythonReview($data);
        foreach ([
            '每月数千到数万元', '一般需要3-6个月', 'AI搜索会结合用户地理位置提供答案',
            '本地竞争相对较小', '咨询量提升20%',
        ] as $unsupportedClaim) {
            $this->assertStringNotContainsString($unsupportedClaim, $reviewed['article']);
        }
        foreach ([
            '费用需根据服务范围和实际报价确定',
            '所需时间取决于执行范围，并应以持续监测结果为准',
            'AI 搜索是否使用地理位置信息取决于具体平台、权限和查询场景',
            '本地竞争程度需要结合公开内容和实测结果核验',
            '咨询量的变化应以基线和持续监测结果衡量',
        ] as $safeExpression) {
            $this->assertStringContainsString($safeExpression, $reviewed['article']);
        }

        $checks = array_column($reviewed['report']['checks'], null, 'name');
        $this->assertTrue($checks['unsupported_factual_claims_softened']['passed']);
        $this->assertSame(hash('sha256', $reviewed['article']), $reviewed['report']['publishable_article_sha256']);
        $this->assertTrue($this->finalReview($runner, $reviewed['report'], $reviewed['article'])['passed']);
    }

    public function test_only_user_facts_preserve_claims_and_model_evidence_cannot_self_certify(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $data = $this->prepare($runner, [
            'title' => '服务事实说明',
            'target_brand' => '品牌甲',
            'core_answer' => '以下信息按证据状态呈现。',
            'article_sections' => [[
                'heading' => '事实',
                'paragraphs' => [
                    str_repeat('字', 2050).'。',
                    '实测咨询量提升20%。',
                    '本项目预算为10000元。',
                    '一般需要3-6个月见效。',
                    'AI搜索会结合用户地理位置提供答案。',
                    '示例本地竞争相对较小。',
                    '线索量提升30%。',
                ],
            ]],
            'sources' => [
                ['label' => '周期来源', 'locator' => '待补充', 'usage' => '周期'],
                ['label' => '平台文档', 'locator' => 'https://example.test/geo-location', 'usage' => '位置行为'],
                ['label' => '竞争来源', 'locator' => 'https://example.test/competition', 'usage' => '竞争'],
            ],
            'evidence_assessment' => [
                ['claim' => '一般需要3-6个月见效', 'source' => '周期来源', 'verification_status' => '已核验'],
                [
                    'claim' => 'AI搜索会结合用户地理位置提供答案', 'source' => '平台文档',
                    'verification_status' => '已核验', 'raw_note' => '来源原文写保证100%收录',
                ],
                ['claim' => '线索量提升30%', 'source' => '平台文档', 'verification_status' => '已核验'],
            ],
            'fact_verification_matrix' => [[
                'fact' => '示例本地竞争相对较小', 'current_status' => '待核验', 'source' => '竞争来源',
            ]],
        ], [
            'budget' => '10000元',
            'audit_note' => '客户原文：我们保证100%收录。',
        ], '用户已提供实测结果：咨询量提升20%。');

        $reviewed = $this->pythonReview($data);
        $this->assertStringContainsString('实测咨询量提升20%', $reviewed['article']);
        $this->assertStringContainsString('本项目预算为10000元', $reviewed['article']);
        $this->assertStringNotContainsString('AI搜索会结合用户地理位置提供答案', $reviewed['article']);
        $this->assertStringNotContainsString('一般需要3-6个月见效', $reviewed['article']);
        $this->assertStringNotContainsString('示例本地竞争相对较小', $reviewed['article']);
        $this->assertStringNotContainsString('线索量提升30%', $reviewed['article']);
        $this->assertStringContainsString('所需时间取决于执行范围', $reviewed['article']);
        $this->assertStringContainsString('AI 搜索是否使用地理位置信息取决于具体平台、权限和查询场景', $reviewed['article']);
        $this->assertStringContainsString('本地竞争程度需要结合公开内容和实测结果核验', $reviewed['article']);
        $this->assertStringContainsString('线索量的变化应以基线和持续监测结果衡量', $reviewed['article']);
        $checks = array_column($reviewed['report']['checks'], null, 'name');
        $this->assertTrue($checks['unsupported_factual_claims_softened']['passed']);
        $this->assertSame($data['_user_provided_facts'], $reviewed['normalized']['_user_provided_facts']);
        $this->assertSame($data['sources'], $reviewed['normalized']['sources']);
        $this->assertSame($data['evidence_assessment'], $reviewed['normalized']['evidence_assessment']);
    }

    public function test_unresolved_sources_keep_a_valid_article_in_fact_review_state(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $data = $this->prepare($runner, [
            'title' => '示例文旅说明',
            'target_brand' => '示例文旅',
            'core_answer' => '文章结构完整，但正式发布前仍需确认景区运营信息。',
            'article_sections' => [[
                'heading' => '游览提示',
                'paragraphs' => [str_repeat('正式发布前应核对官方资料。', 180)],
            ]],
            'sources' => [[
                'label' => '景区官方资料',
                'locator' => '待甲方提供官网链接',
                'date' => '待核验',
                'usage' => '核对开放时间',
            ]],
            'evidence_assessment' => [[
                'claim' => '景区开放时间',
                'evidence_level' => '待核验',
                'verification_needed' => '待甲方确认',
            ]],
            'fact_verification_matrix' => [[
                'fact' => '活动日程',
                'current_status' => '待确认',
                'source_required' => '官方公告',
            ]],
            'pending_confirmations' => ['票价和活动时间需甲方确认'],
        ]);

        $reviewed = $this->pythonReview($data);

        $this->assertFalse($reviewed['readiness']['publication_ready']);
        $this->assertTrue($reviewed['readiness']['fact_review_required']);
        $this->assertContains('景区开放时间', $reviewed['readiness']['fact_review_items']);
        $this->assertContains('活动日程', $reviewed['readiness']['fact_review_items']);
        $this->assertContains('来源待补充：景区官方资料', $reviewed['readiness']['fact_review_items']);
    }

    public function test_cross_quote_and_modifier_rewrites_remain_readable(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $data = $this->prepare($runner, [
            'title' => '教培机构 AI 推广说明',
            'target_brand' => '品牌甲',
            'core_answer' => '需要先核验事实和平台表现。',
            'article_sections' => [[
                'heading' => '核验说明',
                'paragraphs' => [
                    str_repeat('字', 1900).'。',
                    '现在，越来越多的人会直接问Kimi、豆包或DeepSeek：“示例哪家数学补习班好？”如果机构没有在AI能抓取和引用的内容中建立权威，就可能失去这些潜在客户。',
                    'AI推广（GEO，Generative Engine Optimization）就是让机构的内容更容易被AI搜索引用和推荐。',
                    '这些素材能转化为AI搜索偏好的问答内容，例如本地选课问题。',
                    'AI搜索会结合用户地理位置提供答案，如果没有本地信息就可能被排除。',
                    '任何宣传都需遵守广告法，不得使用“最好”“第一”等绝对化用语。',
                ],
            ]],
        ]);

        $reviewed = $this->pythonReview($data);
        foreach ([
            '用户可以使用Kimi、豆包或DeepSeek等工具查询“示例哪家数学补习班好？”',
            '这类查询是否影响品牌曝光或客户咨询，需要结合实际监测和线索来源数据判断',
            '这里所说的 AI 推广（GEO），是围绕内容的可发现性、可理解性和引用表现开展优化',
            '这些素材可以整理成清晰、可核验的问答内容',
            'AI 搜索是否使用地理位置信息取决于具体平台、权限和查询场景，相关信息是否影响 AI 引用或展示，需要补齐后通过实际查询验证',
            '相关宣传用语应由合规人员结合具体文案和最新法规核验',
        ] as $readableSentence) {
            $this->assertStringContainsString($readableSentence, $reviewed['article']);
        }
        foreach ([
            '好？相关信息是否影响',
            '内容实际引用表现需要通过持续监测验证和推荐',
            '转化为不同 AI 平台的排序和引用规则需以',
            '需要查阅平台说明并实测，需要补齐信息',
            '需遵守广告及宣传用语的适用规则应由',
        ] as $brokenPhrase) {
            $this->assertStringNotContainsString($brokenPhrase, $reviewed['article']);
        }
        $checks = array_column($reviewed['report']['checks'], null, 'name');
        $this->assertTrue($checks['unsupported_factual_claims_softened']['passed']);
        $this->assertTrue($this->finalReview($runner, $reviewed['report'], $reviewed['article'])['passed']);
    }

    public function test_a_user_provided_baseline_does_not_support_an_uplift_claim_with_the_same_number(): void
    {
        $runner = app(SkillAgentRunnerService::class);
        $data = $this->prepare($runner, [
            'title' => '转化率说明',
            'target_brand' => '品牌甲',
            'core_answer' => '需要区分基线和提升幅度。',
            'article_sections' => [[
                'heading' => '基线',
                'paragraphs' => [str_repeat('字', 2050).'。转化率提升20%。通常需要3年。每月10000元。'],
            ]],
        ], [
            'baseline_conversion_rate' => '20%',
            'company_age' => '3年',
            'budget' => '10000元',
        ], '不要写成转化率提升20%。');

        $reviewed = $this->pythonReview($data);
        $this->assertStringNotContainsString('转化率提升20%', $reviewed['article']);
        $this->assertStringNotContainsString('通常需要3年', $reviewed['article']);
        $this->assertStringNotContainsString('每月10000元', $reviewed['article']);
        $this->assertStringContainsString('转化率的变化应以基线和持续监测结果衡量', $reviewed['article']);
        $this->assertStringContainsString('所需时间取决于执行范围', $reviewed['article']);
        $this->assertStringContainsString('费用需根据服务范围和实际报价确定', $reviewed['article']);
    }

    private function prepare(
        SkillAgentRunnerService $runner,
        array $data,
        array $briefFields = [],
        ?string $query = null,
    ): array
    {
        return (new ReflectionMethod($runner, 'prepareStructuredInput'))->invoke(
            $runner, $data, new SkillBrief('yao-geo-explainer-builder', 1, 1, $briefFields, $query),
        );
    }

    private function finalReview(SkillAgentRunnerService $runner, array $report, string $article): array
    {
        return (new ReflectionMethod($runner, 'withPublishableArticleChecks'))->invoke(
            $runner, $report, 'yao-geo-explainer-builder', $article,
        );
    }

    private function pythonReview(array $data): array
    {
        // 调用真实规范化与文章检查函数，不渲染 PDF、不联网、不调用模型。
        $process = new Process([
            // This review uses Python's standard library only. PYTHON_BIN may
            // point to a Docker/.bat render bridge, which cannot pass a
            // multiline -c program through Windows cmd.exe unchanged.
            env('GEO_TEST_REVIEW_PYTHON', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'), '-B', '-c', <<<'PY'
import importlib.util, json, sys
spec = importlib.util.spec_from_file_location('explainer', sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
data, fixes = module.normalize(json.load(sys.stdin))
report = module.publishable_article_review(data)
readiness = module.publication_readiness(data)
report['passed'] = all(item['passed'] for item in report['checks'])
report['issues'] = [item['name'] for item in report['checks'] if not item['passed']]
print(json.dumps({'article': data['publishable_article_markdown'], 'report': report, 'readiness': readiness, 'normalized': data, 'fixes': fixes}, ensure_ascii=True))
PY,
            base_path('skill-overlays/yao-geo-explainer-builder/scripts/render_yao_geo_explainer_builder.py'),
        ]);
        $process->setInput(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $process->setEnv(['PYTHONIOENCODING' => 'utf-8']);
        $process->setTimeout(15);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
