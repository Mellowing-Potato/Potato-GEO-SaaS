<?php

namespace App\Services\SkillAgent;

// ⚠️ 这个 import 是必须的：`markdownChunkData(AiProviderCallResult $llm)` 的类型提示
//    在本命名空间下解析 —— 漏了这一行，PHP 会去找 App\Services\SkillAgent\AiProviderCallResult，
//    而这个类在 App\Services\DTOs 下（实测踩到：四条断言当场红）。
use App\Services\DTOs\AiProviderCallResult;

/**
 * 分节谱与初始状态（计划 §7 第 3 步：把 SkillAgentRunnerService 按职责拆小，「准备」里的分段谱）。
 *
 * 四个技能的分段谱（全景审计 4 段 / 执行路线图 3 段 / 解释型文章 4 段 / 意图挖掘 7 段）+
 * 页面审计的 markdown 分节谱，以及"这次执行按哪份谱跑"的判定与初始状态。
 *
 * 为什么谱要集中在一处：它是报告结构的**唯一事实源** —— 字段漏一个、顺序错一步，
 * 合并出来的报告就少一节或串位，而且不报错。搬出来之前它夹在 3000 行执行引擎的常量区里，
 * 改谱得先翻过一堆执行代码。
 *
 * 行为由 tests/Feature/SkillChunkPlanTest.php 的指纹冻结（谱内容、初始状态、markdown 分节包装）。
 */
class SkillChunkPlan
{
    /** 低于这个字符数认为这次模型输出没用（交白卷和交半句话在客户眼里没区别） */
    public const MIN_USABLE_OUTPUT_CHARS = 40;
    /**
     * 全景 audit 分段生成的分组。把 15 张命名表 + 标量字段拆成 4 段，每段独立一次 LLM 调用，
     * 降低单次输出体量；实际是否写完由结束原因和完整性闸判断。
     * 各段字段互不重叠，合并时每段只认领自己的字段，杜绝跨段污染。
     */
    public const PANORAMA_CHUNKS = [
        [
            'key' => 'foundation',
            'label' => '品牌叙事与方法论',
            'fields' => ['title', 'brand_name', 'subtitle', 'notice', 'limitations', 'meta', 'summary', 'method_basis', 'authority_map', 'question_coverage'],
        ],
        [
            'key' => 'evidence',
            'label' => '事实基线与实体画像',
            'fields' => ['baseline', 'crawl_inventory', 'entity_profile', 'product_map'],
        ],
        [
            'key' => 'assessment',
            'label' => '技术审计与竞争评分',
            'fields' => ['technical_audit', 'competitors', 'scores'],
        ],
        [
            'key' => 'action',
            'label' => '机会、修复与行动方案',
            'fields' => ['opportunities', 'repairs', 'offsite_plan', 'sources', 'risk_register', 'section_meanings', 'diagnostic_questions', 'conclusion'],
        ],
    ];

    // 执行路线图分段谱：17 个顶层字段按体量均衡分 3 段（project_packages 最重故独占一段）。
    // report 为 header 容器（title/company/website/scenario/language/date）。字段以 render
    // 脚本消费的顶层键为准（examples/*/report_input.json 实测）。
    public const ROADMAP_CHUNKS = [
        [
            'key' => 'foundation',
            'label' => '概览、诊断桥接与分析维度',
            'fields' => ['report', 'executive_summary', 'diagnosis_bridge', 'north_star_metrics', 'target_platforms', 'analysis_dimensions', 'evidence_maturity', 'source_basis'],
        ],
        [
            'key' => 'packages_actions',
            'label' => '项目包、平台化行动与路线图',
            'fields' => ['project_packages', 'platform_actions', 'roadmap'],
        ],
        [
            'key' => 'governance_resource',
            'label' => '治理、监测、风险与资源',
            'fields' => ['governance_plan', 'monitoring_plan', 'risk_plan', 'resource_budget', 'real_data_plan', 'data_acquisition'],
        ],
    ];

    /**
     * 解释型文章分段谱（yao-geo-explainer-builder）。
     * 该技能单次输出 42 个顶层字段（正文 9 段 + 研究/证据/方法/品牌治理），实测撞
     * 8192 provider 上限被截断。按 report_input.json 体量拆 4 段；分组不能保证不触顶。
     * 四段 fields 并集 = 全部 42 个顶层键、无重叠（PanoramaChunkedAnalysisTest
     * 里有完备性断言守护，改动后须保持覆盖）。
     */
    public const EXPLAINER_CHUNKS = [
        [
            'key' => 'research_evidence',
            'label' => '研究依据与证据链',
            'fields' => ['research_basis', 'sources', 'evidence_assessment', 'data_availability', 'data_source_plan', 'fact_verification_matrix'],
        ],
        [
            'key' => 'article_core',
            'label' => '文章主体与核心答案',
            'fields' => ['skill_id', 'slug', 'title', 'topic', 'target_brand', 'target_audience', 'use_case', 'sensitive_domain', 'brand_insertion_allowed', 'max_brand_mentions', 'analysis_date', 'core_answer', 'article_sections', 'analysis_summary'],
        ],
        [
            'key' => 'knowledge_method',
            'label' => '知识框架与方法',
            'fields' => ['key_values', 'entity_map', 'definitions', 'principles', 'steps', 'problem_type_map', 'audience_scenarios', 'selection_criteria', 'decision_paths', 'misconceptions', 'example'],
        ],
        [
            'key' => 'brand_publish',
            'label' => '品牌植入与发布治理',
            'fields' => ['faq', 'glossary', 'brand_scenarios', 'brand_insertions', 'platform_notes', 'schema_recommendations', 'measurement_plan', 'content_gap_opportunities', 'pre_publish_checks', 'compliance_notes', 'pending_confirmations'],
        ],
    ];

    /**
     * 意图挖掘分段谱（yao-geo-intent-miner）：21 个顶层字段切 7 段。
     *
     * 为什么是 7 段而不是原来的 4 段（2026-09-06 重切，别再合回去）——
     * 单次调用有一堵和总时长无关的墙：网关按「这次请求还剩多少秒」反算 max_tokens
     * （computeEffectiveMaxTokens 的 timeBudget 项），GEO_GATEWAY_HTTP_TIMEOUT=280 秒
     * 配上 deepseek-v4-pro 实测 16.4 秒/千词元，单次上限就落在 12k 词元上下。
     * 生产实证（skill_executions id=44）：
     *     report_body       完成 11268 词元  finish=stop     ← 已经贴着天花板
     *     question_bank     完成  8209 词元  finish=stop
     *     scoring_sampling  完成 12246 词元  finish=length   ← 明明要了 16384，被钳死在这
     * 所以哪一段想写超过约 12k 词元，它就一定会被截断，跟接力不接力无关。
     *
     * 切分口径：每段的目标完成量压到 7k 词元以内（约 115 秒），留足余量给慢的时候。
     * sections 与 question_bank 是两个最重的字段，各自独占一段。
     * 七段 fields 并集 = 全部 21 个顶层键、无重叠（PanoramaChunkedAnalysisTest 有完备性断言守护）。
     *
     * 代价是每多一段就多重发一次系统提示词（实测约 6500 prompt 词元 ≈ 2.7 分钱），
     * 4 段 → 7 段一份报告约多 8 分钱。相对于原来 100% 失败，这个钱该花。
     */
    public const INTENT_MINER_CHUNKS = [
        [
            'key' => 'report_head',
            'label' => '报告头部与摘要卡片',
            'fields' => ['slug', 'title', 'subtitle', 'brand_name', 'analysis_date', 'prepared_by', 'limitations', 'summary_cards'],
        ],
        [
            'key' => 'report_sections',
            'label' => '报告正文段落',
            'fields' => ['sections'],
        ],
        [
            'key' => 'question_bank',
            'label' => '问题库、追问链与提示词库',
            'fields' => ['question_bank', 'follow_up_chains', 'prompt_library'],
        ],
        [
            'key' => 'scoring_matrix',
            'label' => '评分矩阵与数据来源',
            'fields' => ['scoring_matrix', 'data_sources'],
        ],
        [
            'key' => 'sampling_calibration',
            'label' => '取样计划与校准动作',
            'fields' => ['ai_sampling_plan', 'calibration_actions'],
        ],
        [
            'key' => 'content_topics',
            'label' => '内容选题与常见问答',
            'fields' => ['content_topics', 'faq_bank'],
        ],
        [
            'key' => 'evidence_roadmap',
            'label' => '知识库条目、证据资产与行动路线',
            'fields' => ['knowledge_base_entries', 'evidence_sources', 'action_roadmap'],
        ],
    ];

    /**
     * 页面审计的分节谱（yao-geo-page-audit）。
     *
     * 和上面四份谱不是一回事：那四个技能出 JSON、交给渲染脚本排版，所以按**字段**切；
     * 页面审计没有渲染脚本、直出 Markdown，所以按**章节**切。两种谱共用同一套执行机器，
     * 靠 format 区分（见 isMarkdownSpec）。Markdown 的一节在机器里伪装成「一个字段」，
     * fields 里那个名字就是本节在 merged 里的存放键。
     *
     * 为什么必须切 —— 这份报告一次调用根本产不完：
     * 网关按「这次请求还剩多少秒」反算 max_tokens（GEO_GATEWAY_HTTP_TIMEOUT=280 秒，
     * 实测约 17.9 毫秒/词元），单次天花板落在 12k 词元上下。我们请求 16384 永远拿不到。
     * 2026-09-07 生产 execution 53 实证：请求 16384、实得 12810、耗时 228.9 秒、
     * finish_reason=length，报告写到第 3 节就断了，客户被扣 ¥0.16 拿到半份东西。
     *
     * 章节划分照 references/report-module-taxonomy.md 的 16 个必备模块归并，不是我编的：
     *   1 执行摘要 / 2 输入与范围 / 3 页面组合            → overview
     *   4 公开答案素材问题集 / 5 权威证据台账             → evidence
     *   6 抓取与渲染 / 7 移动端与性能                     → crawlability
     *   8 结构规范性 / 9 内容证据                         → structure
     *   10 AI 可抽取性 / 11 Schema 一致性 / 12 来源权威   → extractability
     *   13 代码层修复清单 / 14 内容结构改造               → fixes
     *   15 优先级与路线图 / 16 自检与质量报告             → roadmap
     *
     * ⚠️ target_chars 是**每节**的篇幅目标，不是给整份报告缩水。
     *    七节合计约 22,000 字，比原来单次调用那 12,403 字**多**了将近一倍 ——
     *    限每节篇幅正是为了让每一节都稳稳落在天花板下面，从而把总量做大。
     *    别把它当成「限制客户买到的东西」而调小：调小节数才是缩水。
     */
    public const PAGE_AUDIT_SECTIONS = [
        [
            'key' => 'overview',
            'label' => '执行摘要与审计范围',
            'format' => 'markdown',
            'fields' => ['overview'],
            // 只有第一节写一级标题（报告名），其余节从二级标题起，拼起来才是一份正常文档。
            'lead' => true,
            'target_chars' => 2200,
            'modules' => [
                '执行摘要：总体结论、P0/P1 风险、预期收益、本次审计的边界',
                '输入与范围：目标 URL、品牌、关键词、目标问题、可用资料与输入缺口',
                '页面组合：首页 / 一级页 / 二级页的选择依据；本次实际抓到了哪些页面、哪些没抓到要写明',
            ],
        ],
        [
            'key' => 'evidence',
            'label' => '公开答案素材与权威证据台账',
            'format' => 'markdown',
            'fields' => ['evidence'],
            'target_chars' => 3000,
            'modules' => [
                '公开答案素材问题集：按用户高意图问题列出页面应该提供的官方素材；没有平台采样数据时不写任何 AI 平台召回或引用份额',
                '权威证据台账：每条重要结论标注来源类型（观察 / 官方 / 标准 / 研究 / 输入缺口）',
            ],
        ],
        [
            'key' => 'crawlability',
            'label' => '抓取、渲染与移动性能',
            'format' => 'markdown',
            'fields' => ['crawlability'],
            'target_chars' => 3200,
            'modules' => [
                '抓取与渲染：状态码、robots、sitemap、canonical、meta robots、初始 HTML 里有没有正文、JavaScript 依赖',
                '移动端与性能：移动优先内容一致性、首屏稳定、核心内容是否懒加载、LCP/INP/CLS 复测建议（没有实测数据的一律标输入缺口，不要给数字）',
            ],
        ],
        [
            'key' => 'structure',
            'label' => '结构规范性与内容证据',
            'format' => 'markdown',
            'fields' => ['structure'],
            'target_chars' => 3500,
            'modules' => [
                '结构规范性：H1-H3 层级、main/article、目录、摘要、FAQ、表格、列表、面包屑、内链与锚文本',
                '内容证据：实体全称、数据、案例、引用、作者与来源、更新时间、价格、服务边界、地域与合规约束',
            ],
        ],
        [
            'key' => 'extractability',
            'label' => 'AI 可抽取性、Schema 与来源权威',
            'format' => 'markdown',
            'fields' => ['extractability'],
            'target_chars' => 3500,
            'modules' => [
                'AI 可抽取性：原子事实、键值对、对比表、步骤、Q&A、段落独立性、上下文无关摘要、chunk 引用准备度',
                'Schema 一致性：schema 类型、字段来源、能否回溯到正文、缺失字段、冗余字段、校验工具',
                '来源权威与可信度：账号与作者责任、官方出处、编辑原则、sameAs、百科与媒体误引风险',
            ],
        ],
        [
            'key' => 'fixes',
            'label' => '代码层修复清单与内容结构改造',
            'format' => 'markdown',
            'fields' => ['fixes'],
            'target_chars' => 4000,
            'modules' => [
                '代码层修复清单：字段、HTML、schema、meta、robots、sitemap、SSR/SSG，每条都要给验收命令',
                '内容结构改造：页面模块、FAQ、事实表、案例、来源标注、中文适配、竞品替代表达的边界',
            ],
        ],
        [
            'key' => 'roadmap',
            'label' => '优先级路线图与完整性自检',
            'format' => 'markdown',
            'fields' => ['roadmap'],
            'target_chars' => 2800,
            'modules' => [
                '优先级与路线图：P0/P1/P2、负责人、成本、依赖、验收方式、发布批次',
                '自检与质量报告：范围 / 证据 / 技术 / 内容 / AI 五项完整性自检，并把全文的输入缺口和未验证风险汇总成一张表',
            ],
        ],
    ];

    /**
     * 分段执行的初始状态。
     *
     * 这个数组就是接力的全部家当：段与段之间换进程，它会被整个 json 存进
     * skill_execution_relays.state 再读回来。所以里面只许放能 json 化的东西——
     * 别塞对象（AiProviderCallResult 这类），塞了在下一个进程里就变回裸数组了。
     */
    public function newChunkState(
        array $specs,
        string $knowledgeContext,
        float $startTime,
        ?PageObservation $pageObservation = null,
    ): array {
        $specs = array_values($specs);

        return [
            // 冻结本次用的分段谱：常量哪天改了，跑到一半的接力仍按开跑时那份走完。
            'specs' => $specs,
            // RAG 结果存下来，接力后续步骤直接复用，不重新检索（那是要花钱的向量调用）。
            'knowledge_context' => $knowledgeContext,
            // 页面抓取结果同理：接力每一步都是新进程、都会走一遍 prepareRun，
            // 不存下来就等于同一次执行把客户的站点连抓好几遍。
            'page_observation' => $pageObservation?->toArray(),
            'merged' => [],
            'chunk_outputs' => [],
            'chunk_meta' => [],
            // 分节谱专用：被截断的那一节写出来的半截正文（见 runChunkTask 里的说明）。
            'chunk_partials' => [],
            'raw_parts' => [],
            'token_sum' => 0,
            'call_count' => 0,
            'recovery_calls_left' => max(0, (int) config('geo.chunk_recovery_max_calls', 3)),
            'pending' => array_map(
                fn (array $spec): array => ['spec' => $spec, 'parent' => $spec, 'recovery' => false],
                $specs,
            ),
            'cancelled' => false,
            'started_at' => $startTime,
        ];
    }

    /**
     * 构建单个分段的 user prompt：复用完整 schema，但把输出范围收窄到本段字段。
     */
    /**
     * 分段谱查询：命中的 skill 走分段执行，未命中返回 null（走单次调用）。
     *
     * 按有没有渲染脚本分流到两种谱，因为它们切的东西根本不是一回事：
     *   有渲染脚本 → 出 JSON 交给 Python 排版 → 按**字段**切（*_CHUNKS）
     *   没渲染脚本 → 直出 Markdown 正文       → 按**章节**切（*_SECTIONS）
     *
     * 拿 JSON 谱去切 Markdown 技能会毁掉输出（那条路会强制 json_object），
     * 反过来也一样，所以这里必须先分流再查表，不能两张表混着找。
     */
    public function specsFor(string $skillId, bool $hasRenderPipeline): ?array
    {
        if (! $hasRenderPipeline) {
            return $this->markdownSectionSpecsFor($skillId);
        }

        if (str_contains($skillId, 'panorama-audit')) {
            return self::PANORAMA_CHUNKS;
        }
        if (str_contains($skillId, 'execution-roadmap')) {
            return self::ROADMAP_CHUNKS;
        }
        if (str_contains($skillId, 'explainer')) {
            return self::EXPLAINER_CHUNKS;
        }
        if (str_contains($skillId, 'intent-miner')) {
            return self::INTENT_MINER_CHUNKS;
        }

        return null;
    }

    /**
     * 直出 Markdown 的长报告技能的分节谱。新增时在此登记其 *_SECTIONS 常量。
     *
     * ⚠️ 匹配串别写成 'audit'：'yao-geo-panorama-audit' 也含这两个字，
     *    会把全景审计从字段谱抢到分节谱上来。这里用 'page-audit' 是唯一的。
     */
    private function markdownSectionSpecsFor(string $skillId): ?array
    {
        if (str_contains($skillId, 'page-audit')) {
            return self::PAGE_AUDIT_SECTIONS;
        }

        return null;
    }

    /** 整份谱是不是分节谱。谱内不允许混用，取第一条判即可。 */
    public static function specsAreMarkdown(array $specs): bool
    {
        $first = array_values($specs)[0] ?? [];

        return is_array($first) && SkillPromptBuilder::isMarkdownSpec($first);
    }

    /**
     * 一节 Markdown 正文包装成机器认识的形状：['<本节键>' => '正文']。
     *
     * 返回 null＝这一节没交出可用内容，调用方会照「这段没成」处理（补跑 / 完整性闸）。
     * 交白卷和交半句话在客户眼里没区别，所以长度不够的一律不认。
     */
    public function markdownChunkData(array $spec, AiProviderCallResult $llm): ?array
    {
        if (! $llm->success) {
            return null;
        }

        $text = trim((string) ($llm->content ?? ''));
        $field = $spec['fields'][0] ?? null;

        if ($field === null || mb_strlen($text) < self::MIN_USABLE_OUTPUT_CHARS) {
            return null;
        }

        return [$field => $text];
    }
}
