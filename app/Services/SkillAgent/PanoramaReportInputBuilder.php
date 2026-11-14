<?php

namespace App\Services\SkillAgent;

use App\Services\DTOs\SkillBrief;

class PanoramaReportInputBuilder
{
    private const TABLE_MINIMUMS = [
        'method_basis' => 3,
        'authority_map' => 3,
        'question_coverage' => 5,
        'baseline' => 3,
        'crawl_inventory' => 3,
        'entity_profile' => 3,
        'product_map' => 3,
        'technical_audit' => 3,
        'competitors' => 3,
        'scores' => 8,
        'opportunities' => 3,
        'repairs' => 3,
        'offsite_plan' => 3,
        'sources' => 3,
        'risk_register' => 3,
    ];

    private const SECTION_MEANINGS = [
        'method_basis' => '说明本次诊断如何从项目资料、公开入口和 GEO 评分口径形成判断，避免只输出泛建议。',
        'authority_map' => '把 GEO 判断映射到可解释的参考原则，帮助后续审核建议是否有依据。',
        'question_coverage' => '检查目标用户在 AI 搜索中可能提出的问题，识别官网和内容资产尚未覆盖的答案。',
        'baseline' => '记录官网当前可抽取的品牌、产品和商业事实，以及需要进一步核验的来源。',
        'crawl_inventory' => '把需要抓取或补充的页面类型列出来，形成后续页面审计和内容建设清单。',
        'entity_profile' => '整理品牌实体、产品边界、适用对象和核心价值，方便 AI 引擎建立稳定认知。',
        'product_map' => '把产品能力、用户场景和商业口径拆开，减少 AI 答案误解或错配。',
        'technical_audit' => '检查结构化数据、标题层级、FAQ、内链和可抓取性等基础技术项。',
        'competitors' => '对比竞品在公开内容、技术文档、案例和外部证据上的可见优势。',
        'scores' => '用 8 个 GEO 特征维度给出当前就绪度和优先动作，帮助判断先做什么。',
        'opportunities' => '把发现转成可执行机会，标明价值、工作量、优先级和验收方式。',
        'repairs' => '列出站内页面、模块和内容组件的修复清单，便于进入技能执行落地。',
        'offsite_plan' => '规划媒体、案例、社区和第三方平台的站外证据建设，增强可引用性。',
        'sources' => '记录本次诊断使用或待补充的来源，方便复核和后续持续更新。',
        'risk_register' => '列出数据不足、外部依赖和表达边界等风险，避免过度承诺。',
    ];

    public function complete(array $data, SkillBrief $brief): array
    {
        $profile = $this->profile($brief);
        $copy = $this->contextCopy($profile);
        $overallScore = $this->overallScore($data);
        $summaryText = $this->firstText([
            $data['summary_text'] ?? null,
            $data['executive_summary'] ?? null,
            $data['summary'] ?? null,
            $data['conclusion'] ?? null,
        ]);
        $issues = $this->listFrom($data['critical_issues'] ?? $data['key_findings'] ?? []);
        $wins = $this->sanitizeWins($this->listFrom($data['quick_wins'] ?? $data['recommendations'] ?? []), $profile);
        $dimensionRows = $this->scoreRowsFromDimensions($data['dimensions'] ?? [], $profile);
        $data['critical_issues'] = $issues;
        $data['key_findings'] = $issues;
        $data['quick_wins'] = $wins;
        $data['recommendations'] = $wins;
        $data['dimensions'] = $dimensionRows;

        $data['title'] = $this->stringOrDefault($data['title'] ?? null, "{$profile['brand_name']} GEO 综合诊断报告");
        $data['brand_name'] = $this->stringOrDefault($data['brand_name'] ?? null, $profile['brand_name']);
        $data['subtitle'] = $this->stringOrDefault($data['subtitle'] ?? null, 'AI 搜索可见性、内容缺口与落地机会诊断');
        $data['notice'] = $this->stringOrDefault($data['notice'] ?? null, $this->noticeText($profile));
        $data['limitations'] = $this->stringOrDefault($data['limitations'] ?? null, $this->limitationsText($profile));
        $data['overall_score'] = $overallScore;
        $data['summary'] = $this->fillRows($data['summary'] ?? [], $this->summaryRows($profile, $summaryText, $issues, $wins), 5);
        $data['meta'] = $this->fillRows($data['meta'] ?? [], $this->metaRows($profile), 6);
        $data['diagnostic_questions'] = $this->diagnosticQuestions($data['diagnostic_questions'] ?? [], $profile);

        $data['method_basis'] = $this->fillRows($data['method_basis'] ?? [], $this->methodRows(), self::TABLE_MINIMUMS['method_basis']);
        $data['authority_map'] = $this->fillRows($data['authority_map'] ?? [], $this->authorityRows(), self::TABLE_MINIMUMS['authority_map']);
        $data['question_coverage'] = $this->fillRows($data['question_coverage'] ?? [], $this->questionRows($profile), self::TABLE_MINIMUMS['question_coverage']);
        $data['baseline'] = $this->fillRows($data['baseline'] ?? [], $this->baselineRows($profile), self::TABLE_MINIMUMS['baseline']);
        $data['crawl_inventory'] = $this->fillRows($data['crawl_inventory'] ?? [], $this->crawlRows($profile), self::TABLE_MINIMUMS['crawl_inventory']);
        $data['entity_profile'] = $this->fillRows($data['entity_profile'] ?? [], $this->entityRows($profile), self::TABLE_MINIMUMS['entity_profile']);
        $data['product_map'] = $this->fillRows($data['product_map'] ?? [], $this->productRows($profile), self::TABLE_MINIMUMS['product_map']);
        $data['technical_audit'] = $this->fillRows($data['technical_audit'] ?? [], $this->technicalRows($profile), self::TABLE_MINIMUMS['technical_audit']);
        $data['competitors'] = $this->fillRows($data['competitors'] ?? [], $this->competitorRows($profile), self::TABLE_MINIMUMS['competitors']);
        $data['scores'] = $this->fillRows($data['scores'] ?? $dimensionRows, $this->scoreRows($overallScore, $profile), self::TABLE_MINIMUMS['scores']);
        $data['opportunities'] = $this->fillRows($data['opportunities'] ?? [], $this->opportunityRows($profile, $wins), self::TABLE_MINIMUMS['opportunities']);
        $data['repairs'] = $this->fillRows($data['repairs'] ?? [], $this->repairRows($profile, $wins), self::TABLE_MINIMUMS['repairs']);
        $data['offsite_plan'] = $this->fillRows($data['offsite_plan'] ?? [], $this->offsiteRows($profile), self::TABLE_MINIMUMS['offsite_plan']);
        $data['sources'] = $this->fillRows($data['sources'] ?? [], $this->sourceRows($profile), self::TABLE_MINIMUMS['sources']);
        $data['risk_register'] = $this->fillRows($data['risk_register'] ?? [], $this->riskRows($profile, $issues), self::TABLE_MINIMUMS['risk_register']);

        $data['section_meanings'] = array_merge(self::SECTION_MEANINGS, $this->sectionMeanings($data['section_meanings'] ?? []));
        $data['conclusion'] = $this->stringOrDefault(
            $data['conclusion'] ?? null,
            "{$profile['brand_name']} 已形成第一版 GEO 综合诊断。建议先补齐可被 AI 直接引用的品牌定义、FAQ、{$copy['proof_short']}和结构化数据，再进入技能执行生成路线、文章、页面审计或效果监测资产。"
        );

        return $data;
    }

    private function profile(SkillBrief $brief): array
    {
        $brandName = $this->stringOrDefault($brief->field('brand_name'), '当前品牌');
        $projectName = $this->stringOrDefault($brief->field('project_name'), "{$brandName} GEO 项目");
        $brandUrl = $this->stringOrDefault($brief->field('brand_url'), '(未提供)');
        $productDescription = $this->stringOrDefault($brief->field('product_description'), '暂未填写产品说明');
        $category = $this->inferCategory($brief->field('category'), $productDescription);

        $profile = [
            'project_name' => $projectName,
            'brand_name' => $brandName,
            'brand_url' => $brandUrl,
            'brand_url_label' => $this->brandUrlLabel($brandUrl),
            'has_usable_brand_url' => $this->hasUsableBrandUrl($brandUrl),
            'product_description' => $productDescription,
            'target_market' => $this->stringOrDefault($brief->field('target_market'), '目标用户或行业暂未明确'),
            'target_keywords' => $this->listFrom($brief->field('target_keywords')),
            'competitors' => $this->listFrom($brief->field('competitors')),
            'goals' => $this->stringOrDefault($brief->field('goals') ?? $brief->field('primary_goal'), '提升品牌在 AI 搜索场景中的可见度和引用率'),
            'notes' => $this->stringOrDefault($brief->field('notes'), ''),
            'category' => $category,
        ];

        $profile['target_keywords'] = $this->businessKeywords($profile, $profile['target_keywords']);

        return $profile;
    }

    private function noticeText(array $profile): string
    {
        if (! $profile['has_usable_brand_url']) {
            return '本报告由 GEO 工作流根据项目资料和 AI 分析生成；当前未接入真实官网抓取，页面事实需在后续页面审计中确认。';
        }

        return '本报告由 GEO 工作流根据项目资料、官网入口和 AI 分析生成，用于内部诊断和后续执行。';
    }

    private function limitationsText(array $profile): string
    {
        if (! $profile['has_usable_brand_url']) {
            return '当前官网为测试/占位地址或未提供，报告不会把页面抓取结果当作已验证事实；建议补充真实官网后重新执行综合诊断。';
        }

        return '如未接入第三方数据，部分事实仍需在页面审计和人工复核中确认。';
    }

    private function summaryRows(array $profile, string $summaryText, array $issues, array $wins): array
    {
        return [
            ['诊断结论', $summaryText ?: "{$profile['brand_name']} 已完成 GEO 综合诊断，当前需要把项目资料转成 AI 可引用的结构化内容。"],
            ['品牌与产品', "{$profile['brand_name']}：{$profile['product_description']}"],
            ['目标市场', $profile['target_market']],
            ['主要缺口', $issues[0] ?? '需要补齐品牌定义、FAQ、案例证据、结构化数据和外部可信来源。'],
            ['优先机会', $wins[0] ?? '优先建设可直接回答用户问题的内容资产，并为核心页面补充 Schema 和来源台账。'],
        ];
    }

    private function metaRows(array $profile): array
    {
        return [
            ['项目', $profile['project_name']],
            ['品牌', $profile['brand_name']],
            ['官网', $profile['brand_url']],
            ['数据边界', $profile['has_usable_brand_url'] ? '已提供官网入口，页面事实仍需审计确认' : '未接入真实官网抓取，当前基于项目资料生成'],
            ['目标市场', $profile['target_market']],
            ['关键词', $this->joinList($profile['target_keywords'], '暂未填写')],
            ['竞品', $this->joinList($profile['competitors'], '暂未填写')],
            ['诊断目标', $profile['goals']],
            ['生成时间', now()->format('Y-m-d H:i:s')],
        ];
    }

    private function methodRows(): array
    {
        return [
            ['项目资料归集', '读取项目名称、品牌、官网、产品说明、关键词、竞品和目标', 'brand_name、brand_url、target_keywords、competitors', '避免空报告或泛化建议'],
            ['AI 可见性诊断', '按用户问题、实体事实、页面结构、外部证据和技术可抓取性拆分', '问题覆盖、实体档案、技术审计、来源台账', '避免只看单一 SEO 指标'],
            ['机会优先级', '按影响、工作量、可验证性和依赖关系排序', 'scores、opportunities、repairs、risk_register', '避免无法落地的总建议'],
            ['后续执行衔接', '把诊断结论转成技能执行所需的路线、文章、页面和监测任务', 'repairs、offsite_plan、diagnostic_questions', '避免诊断和执行脱节'],
        ];
    }

    private function authorityRows(): array
    {
        return [
            ['GEO 内容可见性原则', '内容需要被 AI 搜索正确理解、引用和复述', '用于设定可引用性、语义密度和结构规范性评分', '不承诺平台排名结果'],
            ['生成式搜索可验证性', '关键断言应有来源、案例或页面证据支撑', '用于来源台账、案例证据和外部信号建议', '缺来源时标记待核验'],
            ['结构化数据规范', '页面可见内容应与 Schema、FAQ、标题层级保持一致', '用于技术审计和页面修复清单', '不对不可见内容做标记'],
            ['用户问题覆盖', '围绕推荐、比较、替代、价格、风险、真实性和教程生成答案资产', '用于问题覆盖矩阵和内容机会地图', '不为搜索引擎堆砌低质内容'],
        ];
    }

    private function questionRows(array $profile): array
    {
        $copy = $this->contextCopy($profile);
        $keywords = $profile['target_keywords'] ?: $this->defaultKeywords($profile);
        $rows = [];
        foreach (array_slice($keywords, 0, 5) as $keyword) {
            $rows[] = ['核心问题', "用户搜索「{$keyword}」时，{$profile['brand_name']} 能否被准确推荐？", '待验证', '缺少可直接引用的答案和证据页', '新增 FAQ/场景页'];
        }

        return array_merge($rows, [
            ['比较问题', "{$profile['brand_name']} 与主要竞品有什么区别？", '弱覆盖', '缺少对比边界、适用场景和证据', '新增竞品对比页'],
            ['适用问题', "{$profile['brand_name']} {$copy['fit_question']}？", '部分覆盖', '缺少人群、场景和不适用边界', '补适用场景 FAQ'],
            ['信任问题', "{$profile['brand_name']} 有哪些{$copy['proof_items']}？", '待验证', '缺少可核验的证据列表', '补证据区和来源台账'],
            ['行动问题', "用户下一步如何了解或购买 {$profile['brand_name']}？", '待补充', '缺少清晰入口和行动路径', '补品牌入口和转化说明'],
        ]);
    }

    private function baselineRows(array $profile): array
    {
        $copy = $this->contextCopy($profile);

        return [
            ['品牌定义', $profile['brand_url_label'], $profile['product_description'], '项目资料', '待页面核验', "在{$copy['entry_name']}增加一句可引用的品牌定义"],
            ['适用对象', $profile['brand_url_label'], $profile['target_market'], '项目资料', '待页面核验', '补充适用/不适用场景'],
            ['目标关键词', $profile['brand_url_label'], $this->joinList($profile['target_keywords'], '暂未填写'), '项目设置', '待扩展', '建设关键词对应的问题答案页'],
            ['商业目标', $profile['brand_url_label'], $profile['goals'], '项目设置', '待量化', '补充可衡量的转化和引用指标'],
        ];
    }

    private function crawlRows(array $profile): array
    {
        $copy = $this->contextCopy($profile);
        $url = $profile['brand_url_label'];
        $homepageStatus = $profile['has_usable_brand_url'] ? '待抓取' : '未接入真实官网';

        return [
            [$copy['entry_name'], $url, $homepageStatus, "品牌定位、核心能力、{$copy['audience']}", '结构化摘要、FAQ、证据链接', '补真实官网或品牌入口后执行页面审计'],
            ['产品/功能页', $url, '待补充', '能力边界和使用场景', '模块化产品事实', '补产品能力表'],
            [$copy['proof_page'], $url, '待补充', $copy['proof_structure'], $copy['proof_status'], $copy['case_action']],
            ['帮助/知识库', $url, '待补充', '教程、迁移、配置、风险处理', '长尾问题答案', '补 FAQ 和教程索引'],
        ];
    }

    private function entityRows(array $profile): array
    {
        $copy = $this->contextCopy($profile);

        return [
            ['品牌实体', $profile['brand_name'], '项目资料', '待官网核验', '统一品牌名称、简称和英文/中文写法'],
            ['产品类别', $profile['category'], '项目资料', '待扩展', '明确所属品类和边界'],
            [$copy['audience_label'], $profile['target_market'], '项目资料', '待页面核验', '写成可被 AI 抽取的人群描述'],
            ['核心价值', $profile['goals'], '项目资料', '待量化', "补充指标、{$copy['proof_short']}和结果证据"],
        ];
    }

    private function productRows(array $profile): array
    {
        $copy = $this->contextCopy($profile);

        return [
            ['核心产品', $profile['product_description'], $profile['target_market'], '项目资料', $copy['entry_source'], '补功能边界和不适用场景'],
            ['问题答案资产', '围绕关键词生成 FAQ、解释型文章和对比内容', $profile['target_market'], '内容资产', '内容资产库', '补关键词到页面映射'],
            ['信任证据', $copy['proof_items'], $copy['audience'], '站外与站内', $copy['proof_page'], $copy['case_action']],
            ['技术表达', 'Schema、FAQ、标题层级、内链和可抓取文本', 'AI 搜索引擎', '页面技术', '页面审计', '补结构化数据'],
        ];
    }

    private function technicalRows(array $profile): array
    {
        return [
            ['Schema.org 标记', '待审计', "{$profile['brand_url_label']} 需要检查 Organization、Product/Service、FAQ、Breadcrumb", 'AI 难以理解实体关系', '执行页面审计并补 Schema'],
            ['标题层级', '待审计', '核心页面应有清晰 H1/H2/H3 和摘要段', '答案抽取不稳定', '重构标题和摘要'],
            ['FAQ 与问答块', '待补充', '围绕目标关键词生成可直接回答的问题', '长尾问题覆盖不足', '补 FAQ 模块'],
            ['内链与来源台账', '待补充', '从产品页链接到案例、帮助、对比和证据来源', '引用路径断裂', '补内链和来源说明'],
        ];
    }

    private function competitorRows(array $profile): array
    {
        $competitors = $profile['competitors'];
        foreach (['同类品牌/门店', '小红书或本地生活同类内容', '相近品类集合店'] as $fallback) {
            if (count($competitors) >= self::TABLE_MINIMUMS['competitors']) {
                break;
            }
            if (! in_array($fallback, $competitors, true)) {
                $competitors[] = $fallback;
            }
        }

        $rows = [];
        foreach (array_slice($competitors, 0, 4) as $competitor) {
            $rows[] = [$competitor, '公开内容、案例、评价或社媒内容可能更充分', "{$profile['brand_name']} 需要补齐可引用证据和差异化说明", '竞品官网、社媒账号、案例和第三方评价', '仅作为内容结构参考，不直接复制口径'];
        }

        return $rows;
    }

    private function scoreRows(int $overallScore, array $profile): array
    {
        $base = max(1.0, min(5.0, round($overallScore / 20, 1)));
        $copy = $this->contextCopy($profile);

        return [
            ['语义密度', "{$base}/5", '项目资料已有基础描述，但需要更多场景化同义表达和问题答案', '补行业场景页和 FAQ'],
            ['结构规范性', max(1.0, $base - 0.3).'/5', '核心信息需要拆成摘要、表格、定义、步骤和证据', '重构核心页面结构'],
            ['可引用性', max(1.0, $base - 0.5).'/5', "缺少短句、数据、{$copy['proof_short']}和来源台账时 AI 引用概率偏低", '新增事实卡和来源表'],
            ['权威信号', max(1.0, $base - 0.6).'/5', "外部媒体、{$copy['proof_short']}和第三方评价仍需补强", '建设站外证据'],
            ['可读性', min(5.0, $base + 0.4).'/5', '项目表达可读，但需要更稳定的信息层级', '压缩长段落并增加列表'],
            ['鲁棒性', max(1.0, $base - 0.4).'/5', '不同 AI 平台的答案一致性尚未验证', '建立监测问题集'],
            ['新颖性', "{$base}/5", '需要明确与竞品不同的使用场景和价值主张', "补差异化{$copy['proof_short']}和对比页"],
            ['跨域贡献', max(1.0, $base - 0.2).'/5', '需要把产品经验沉淀为行业方法、指南和模板', '发布方法论内容资产'],
        ];
    }

    private function scoreRowsFromDimensions(mixed $dimensions, array $profile): array
    {
        $rows = [];
        foreach ($this->normalizeRows($dimensions) as $row) {
            $name = $this->stringOrDefault($row[0] ?? null, 'GEO 维度');
            $score = $this->scoreLabel($row[1] ?? null);
            $status = $this->statusLabel($row[3] ?? null);
            $evidence = "{$profile['brand_name']} 当前状态：{$status}";
            $action = '结合综合诊断继续补证据和内容';
            if (! $profile['has_usable_brand_url'] && $this->isWebsiteTechnicalDimension($name)) {
                $score = '20/100';
                $evidence = '未提供真实官网，结构化数据、页面可抓取性和标题层级不能判定';
                $action = '补真实官网或品牌入口后再执行页面审计';
            }
            $rows[] = [$name, $score, $evidence, $action];
        }

        return $rows;
    }

    private function isWebsiteTechnicalDimension(string $name): bool
    {
        return preg_match('/结构化|Schema|可抓取|标题|官网|页面|技术/i', $name) === 1;
    }

    private function opportunityRows(array $profile, array $wins): array
    {
        $copy = $this->contextCopy($profile);

        return [
            ['内容资产', $wins[0] ?? '建设核心 FAQ 和解释型文章', '用户无法获得清晰答案', '提升 AI 引用和转化解释力', '中', 'P0', '内容负责人', '发布后可在内容资产库查看'],
            ['页面结构', $wins[1] ?? "重构{$copy['entry_name']}/产品页摘要和证据块", '公开事实不够原子化', '提升可抓取性和可引用性', '中', 'P0', '产品/运营', '页面审计通过'],
            ['竞品对比', '新增与主要竞品的边界和适用场景说明', '比较类问题弱覆盖', '降低 AI 答案被竞品替代的风险', '低', 'P1', '市场负责人', '生成对比文章或对比页'],
            ['效果监测', '建立 10-20 个 AI 搜索问题的定期监测', '缺少持续反馈', '形成迭代闭环', '低', 'P1', '增长负责人', '每周记录引用和排名变化'],
        ];
    }

    private function repairRows(array $profile, array $wins): array
    {
        $copy = $this->contextCopy($profile);

        return [
            ['摘要区', '缺少可被直接引用的品牌定义', '增加 80-120 字品牌定义和适用对象', '品牌定义卡', "项目资料/{$copy['entry_name']}", 'AI 可直接复述品牌定位'],
            ['FAQ 模块', $wins[0] ?? '目标关键词缺少问题答案', "围绕关键词生成 FAQ 并链接到产品/{$copy['proof_page']}", 'FAQ 列表', '关键词清单', '覆盖 5 个以上核心问题'],
            ['证据区', "缺少{$copy['proof_structure']}字段", $copy['case_action'], '证据表格', $copy['case_source'], '每条证据包含来源和结果'],
            ['结构化数据', 'Schema 和页面可见内容关系待确认', '补 Organization、Product/Service、FAQ、Breadcrumb', 'Schema 清单', '页面审计', '通过页面审计检查'],
        ];
    }

    private function offsiteRows(array $profile): array
    {
        $copy = $this->contextCopy($profile);

        return [
            ['媒体/内容平台', $copy['media_plan'], "{$profile['brand_name']} 的专业性与真实证据", 'P1', '市场负责人', '形成可公开引用链接'],
            ['第三方评价', "整理{$copy['third_party_channels']}评价入口", $copy['proof_subject'], 'P1', '运营负责人', '记录来源和授权状态'],
            [$copy['case_label'], $copy['case_plan'], $copy['case_metric'], 'P0', $copy['proof_owner'], '证据页可交叉验证'],
            [$copy['support_label'], $copy['support_plan'], '可理解性和落地能力', 'P2', '产品/运营', '文档可被搜索和引用'],
        ];
    }

    private function sourceRows(array $profile): array
    {
        $copy = $this->contextCopy($profile);

        return [
            ['项目资料', '工作台输入', '已读取', '生成初始诊断', '需保持最新'],
            [$copy['entry_name'], $profile['brand_url_label'], $profile['has_usable_brand_url'] ? '待抓取' : '未接入真实官网', '核验品牌和产品事实', '建议补真实官网或品牌入口后执行页面审计'],
            ['竞品资料', $this->joinList($profile['competitors'], '暂未填写'), '待收集', '对比内容结构和外部证据', '仅作公开信息参考'],
            [$copy['source_proof_label'], '站内外证据', '待补充', '增强权威信号和可引用性', '需确认授权和来源'],
        ];
    }

    private function riskRows(array $profile, array $issues): array
    {
        $copy = $this->contextCopy($profile);

        return [
            [$issues[0] ?? '公开事实不足', 'AI 可能无法稳定理解品牌定位和产品边界', '高', '产品/运营', '补品牌定义、产品页和 FAQ'],
            [$issues[1] ?? '外部证据不足', 'AI 引用时更容易选择竞品或第三方解释', '高', '市场负责人', "补{$copy['proof_short']}、媒体和评价来源"],
            [$profile['has_usable_brand_url'] ? '数据未完全抓取' : '未接入真实官网抓取', '当前诊断可能依赖项目输入，页面事实需要复核', '中', '运营负责人', '执行页面审计和来源台账'],
            ['目标关键词过宽', '内容容易泛化，难以形成可执行产物', '中', '内容负责人', '按行业、场景和问题拆分关键词'],
        ];
    }

    private function diagnosticQuestions(mixed $existing, array $profile): array
    {
        $questions = $this->listFrom($existing);
        if ($questions !== []) {
            return $questions;
        }

        $copy = $this->contextCopy($profile);
        $keywords = $profile['target_keywords'] ?: array_slice($this->defaultKeywords($profile), 0, 3);

        return [
            "{$profile['brand_name']} 是什么，{$copy['fit_question']}？",
            "{$profile['brand_name']} 与 ".($profile['competitors'][0] ?? '主要竞品').' 有什么区别？',
            "用户搜索「{$keywords[0]}」时，{$copy['entry_name']}能否提供直接答案？",
            "{$profile['brand_name']} 有哪些公开{$copy['proof_short']}、评价或第三方证据？",
            "核心页面是否具备 FAQ、Schema、{$copy['proof_short']}和内链支撑？",
        ];
    }

    private function defaultKeywords(array $profile): array
    {
        if ($this->isRetailProfile($profile)) {
            return ['女装穿搭', '门店体验', '本地咖啡', '品牌推荐', '用户评价'];
        }

        if ($this->isB2BProfile($profile)) {
            return ['AI 搜索优化', '品牌可见性', '多语言客服机器人', '工单自动分流', '售后服务自动化'];
        }

        return ['AI 搜索优化', '品牌可见性', '核心产品', '用户评价', '品牌推荐'];
    }

    private function businessKeywords(array $profile, array $keywords): array
    {
        if (! $this->isRetailProfile($profile)) {
            return $keywords;
        }

        $clean = [];
        foreach ($keywords as $keyword) {
            foreach ($this->retailKeywordParts($keyword) as $part) {
                $clean[] = $part;
            }
        }

        return array_values(array_unique(array_merge($clean, $this->defaultKeywords($profile))));
    }

    private function retailKeywordParts(string $keyword): array
    {
        $keyword = $this->stripToolKeyword($keyword);
        if ($keyword === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $part): string => $this->cleanListItem($part),
            preg_split('/[\r\n,，、;；\s]+/u', $keyword) ?: [],
        )));
    }

    private function stripToolKeyword(string $keyword): string
    {
        $keyword = preg_replace('/(?:AI|GEO|SEO)\s*(?:搜索)?\s*优化/iu', '', $keyword) ?? $keyword;
        $keyword = preg_replace('/(?:生成式引擎优化|生成式搜索优化|搜索引擎优化)/iu', '', $keyword) ?? $keyword;
        $keyword = preg_replace('/\s+/u', ' ', $keyword) ?? $keyword;

        return trim($keyword);
    }

    private function contextCopy(array $profile): array
    {
        $hasUrl = (bool) ($profile['has_usable_brand_url'] ?? false);
        $entryName = $hasUrl ? '官网首页' : '品牌入口/未来官网首页';
        $entrySource = $hasUrl ? '官网/产品页' : '品牌入口/未来官网';

        if ($this->isRetailProfile($profile)) {
            return [
                'entry_name' => $entryName,
                'entry_source' => $entrySource,
                'audience' => '顾客/用户',
                'audience_label' => '目标顾客',
                'fit_question' => '适合哪些顾客和消费场景',
                'proof_short' => '门店/商品/评价证据',
                'proof_items' => '门店信息、商品图文、用户评价、社媒/本地生活平台内容',
                'proof_page' => '评价/门店内容页',
                'proof_structure' => '场景、商品、体验、价格区间和来源',
                'proof_status' => '图片、评价和平台来源',
                'proof_subject' => '用户口碑、门店体验和商品证据',
                'proof_owner' => '运营/门店负责人',
                'case_label' => '门店与用户证据',
                'case_action' => '补真实评价、门店图片、商品说明和来源链接',
                'case_source' => '用户评价、门店图片和平台来源',
                'case_plan' => '沉淀门店、商品、穿搭或用户评价内容并标注来源',
                'case_metric' => '场景、商品、体验、价格区间和来源',
                'media_plan' => '发布品牌故事、商品搭配、门店体验和本地消费指南',
                'third_party_channels' => '小红书、大众点评、抖音、地图/本地生活平台或行业社区',
                'support_label' => '服务/门店说明',
                'support_plan' => '公开营业信息、服务流程、尺码/售后、到店或购买说明',
                'source_proof_label' => '门店/商品/评价/媒体',
            ];
        }

        if ($this->isB2BProfile($profile)) {
            return [
                'entry_name' => $entryName,
                'entry_source' => $entrySource,
                'audience' => '目标团队/采购决策人',
                'audience_label' => '目标团队',
                'fit_question' => '适合哪些团队和业务场景',
                'proof_short' => '客户案例证据',
                'proof_items' => '客户案例、评价、资质、媒体报道和第三方平台资料',
                'proof_page' => '案例页/媒体页',
                'proof_structure' => '行业、问题、方案、结果',
                'proof_status' => '授权状态和量化结果',
                'proof_subject' => '用户口碑和真实使用反馈',
                'proof_owner' => '销售/客户成功',
                'case_label' => '客户案例',
                'case_action' => '补授权和量化结果',
                'case_source' => '客户授权材料',
                'case_plan' => '沉淀行业案例并获得授权',
                'case_metric' => '问题、方案、结果和 ROI',
                'media_plan' => '发布品牌方法论、案例复盘和行业指南',
                'third_party_channels' => 'G2、Capterra、知乎、小红书或行业社区',
                'support_label' => '开发者/帮助内容',
                'support_plan' => '公开 API、集成、迁移或配置文档',
                'source_proof_label' => '客户/案例/媒体',
            ];
        }

        return [
            'entry_name' => $entryName,
            'entry_source' => $entrySource,
            'audience' => '目标用户',
            'audience_label' => '目标用户',
            'fit_question' => '适合哪些用户或场景',
            'proof_short' => '案例/评价证据',
            'proof_items' => '案例、评价、资质、媒体报道和公开来源',
            'proof_page' => '证据页/媒体页',
            'proof_structure' => '场景、问题、做法、结果和来源',
            'proof_status' => '来源状态和可验证结果',
            'proof_subject' => '用户口碑和真实反馈',
            'proof_owner' => '运营负责人',
            'case_label' => '案例与证据',
            'case_action' => '补公开证据、结果说明和来源链接',
            'case_source' => '公开授权材料',
            'case_plan' => '沉淀场景案例、评价内容并标注来源',
            'case_metric' => '问题、做法、结果和来源',
            'media_plan' => '发布品牌方法论、案例复盘和行业指南',
            'third_party_channels' => '知乎、小红书、行业社区、媒体或第三方评价平台',
            'support_label' => '帮助/说明内容',
            'support_plan' => '公开使用说明、服务流程、配置或购买说明',
            'source_proof_label' => '案例/评价/媒体',
        ];
    }

    private function isRetailProfile(array $profile): bool
    {
        return $this->containsAny($this->profileSearchText($profile), [
            '女装',
            '服装',
            '穿搭',
            '咖啡',
            '门店',
            '店铺',
            '本地',
            '零售',
            '集合店',
            '到店',
            '小红书',
            '大众点评',
            '抖音',
        ]);
    }

    private function isB2BProfile(array $profile): bool
    {
        return $this->containsAny($this->profileSearchText($profile), [
            'saas',
            'b2b',
            '企业',
            '团队',
            '客服',
            '工单',
            'api',
            '集成',
            '跨境电商',
            '采购',
            '客户成功',
            '销售',
        ]);
    }

    private function profileSearchText(array $profile): string
    {
        return mb_strtolower(implode(' ', [
            $profile['category'] ?? '',
            $profile['product_description'] ?? '',
            $profile['target_market'] ?? '',
            $this->joinList($profile['target_keywords'] ?? [], ''),
            $this->joinList($profile['competitors'] ?? [], ''),
            $profile['goals'] ?? '',
        ]));
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function overallScore(array $data): int
    {
        $score = $data['overall_score'] ?? $data['score'] ?? null;
        if (is_numeric($score)) {
            return max(0, min(100, (int) round((float) $score)));
        }

        return 62;
    }

    private function sanitizeWins(array $wins, array $profile): array
    {
        $clean = [];
        foreach ($wins as $win) {
            $text = $this->sanitizeWinText($win, $profile);
            if ($text !== '') {
                $clean[] = $text;
            }
        }

        return array_values(array_unique($clean));
    }

    private function sanitizeWinText(string $win, array $profile): string
    {
        $text = trim($win);
        if ($text === '') {
            return '';
        }

        if (str_contains($text, 'FAQ Schema')) {
            return '为核心页面补充 FAQ Schema 和可见问答块，发布后再监测 AI 引用变化';
        }

        if (str_contains($text, 'AI 搜索优化指南')) {
            return '围绕核心关键词补充解释型文章，并标注来源、更新时间和适用场景';
        }

        $text = preg_replace('/[，,；;]?\s*预计提升[^，。；;]*/u', '', $text) ?? $text;
        $text = preg_replace('/[，,；;]?\s*提升\s*\d+\s*%[^，。；;]*/u', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B，,。；;");

        if ($text === '') {
            return "围绕{$profile['brand_name']}的核心问题补充可验证内容，并上线后监测引用变化";
        }

        return $text;
    }

    private function scoreLabel(mixed $score): string
    {
        if (is_numeric($score)) {
            $number = (float) $score;
            if ($number <= 5) {
                return rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.').'/5';
            }

            return ((int) round($number)).'/100';
        }

        return $this->stringOrDefault($score, '待评分');
    }

    private function statusLabel(mixed $status): string
    {
        $text = $this->stringOrDefault($status, '待优化');

        return match ($text) {
            'needs_improvement', 'need_improvement', 'needs-improvement' => '需改进',
            'poor', 'weak' => '薄弱',
            'moderate', 'medium' => '中等',
            'good' => '良好',
            'excellent', 'great' => '优秀',
            default => $text,
        };
    }

    private function hasUsableBrandUrl(string $brandUrl): bool
    {
        if ($brandUrl === '(未提供)') {
            return false;
        }

        $host = parse_url($brandUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return ! preg_match('/(^|\.)example\.(com|net|org|test)$|(^|\.)example$/i', $host);
    }

    private function brandUrlLabel(string $brandUrl): string
    {
        if ($this->hasUsableBrandUrl($brandUrl)) {
            return $brandUrl;
        }

        if ($brandUrl === '(未提供)') {
            return '未提供真实官网';
        }

        return "{$brandUrl}（测试/占位地址，未作为真实官网抓取）";
    }

    private function inferCategory(mixed $category, string $productDescription): string
    {
        $explicit = $this->stringItem($category);
        if ($explicit !== '') {
            return $explicit;
        }

        if (str_contains($productDescription, '女装') && str_contains($productDescription, '咖啡')) {
            return '女装与咖啡复合零售';
        }

        if (str_contains($productDescription, '女装') || str_contains($productDescription, '服装') || str_contains($productDescription, '穿搭')) {
            return '女装与生活方式零售';
        }

        if (str_contains($productDescription, '咖啡')) {
            return '咖啡与线下复合空间';
        }

        if (str_contains($productDescription, '客服') || str_contains($productDescription, '工单')) {
            return 'AI 客服与工单自动化';
        }

        if (str_contains($productDescription, '跨境电商')) {
            return '跨境电商服务工具';
        }

        return 'GEO 诊断';
    }

    private function fillRows(mixed $existing, array $fallbackRows, int $minimum): array
    {
        $rows = $this->deduplicateRows($this->normalizeRows($existing));
        $fallbackRows = $this->deduplicateRows($fallbackRows);
        foreach ($fallbackRows as $row) {
            if (count($rows) >= $minimum && $rows !== []) {
                break;
            }
            $rows = $this->appendUniqueRow($rows, $row);
        }

        if ($rows === []) {
            $rows = $fallbackRows;
        }

        $index = 0;
        while (count($rows) < $minimum && $fallbackRows !== []) {
            $before = count($rows);
            $rows = $this->appendUniqueRow($rows, $fallbackRows[$index % count($fallbackRows)]);
            if (count($rows) === $before && $index >= count($fallbackRows)) {
                break;
            }
            $index++;
        }

        return $rows;
    }

    private function appendUniqueRow(array $rows, array $row): array
    {
        $signature = $this->rowSignature($row);
        foreach ($rows as $existing) {
            if ($this->rowSignature($existing) === $signature) {
                return $rows;
            }
        }

        $rows[] = $row;

        return $rows;
    }

    private function deduplicateRows(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $unique = $this->appendUniqueRow($unique, $row);
        }

        return $unique;
    }

    private function rowSignature(array $row): string
    {
        return mb_strtolower(implode('|', array_map(
            fn (mixed $cell): string => preg_replace('/\s+/u', ' ', $this->stringItem($cell)) ?? '',
            $row,
        )));
    }

    private function normalizeRows(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        if (! array_is_list($value)) {
            return [array_map(fn (mixed $item): string => $this->stringItem($item), array_values($value))];
        }

        $rows = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $rows[] = array_map(fn (mixed $cell): string => $this->stringItem($cell), array_is_list($item) ? $item : array_values($item));
            } else {
                $text = $this->stringItem($item);
                if ($text !== '') {
                    $rows[] = [$text];
                }
            }
        }

        return $rows;
    }

    private function listFrom(mixed $value): array
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $text = $this->firstText($item);
                } else {
                    $text = $this->stringItem($item);
                }
                $text = $this->cleanListItem($text);
                if ($text !== '') {
                    $items[] = $text;
                }
            }

            return array_values(array_unique($items));
        }

        $text = $this->stringItem($value);
        if ($text === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $item): string => $this->cleanListItem($item),
            preg_split('/[\r\n,，、;；]+/u', $text) ?: [],
        )));
    }

    private function cleanListItem(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\s*(?:[-•]+|\d+[.)、])\s*/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function sectionMeanings(mixed $value): array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return [];
        }

        $meanings = [];
        foreach ($value as $key => $meaning) {
            if (is_string($key) && $this->stringItem($meaning) !== '') {
                $meanings[$key] = $this->stringItem($meaning);
            }
        }

        return $meanings;
    }

    private function firstText(mixed $value): string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $text = $this->firstText($item);
                if ($text !== '') {
                    return $text;
                }
            }

            return '';
        }

        return $this->stringItem($value);
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        $text = $this->stringItem($value);

        return $text !== '' ? $text : $default;
    }

    private function stringItem(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        if ($value === null) {
            return '';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return trim((string) $value);
    }

    private function joinList(array $items, string $emptyText): string
    {
        return $items === [] ? $emptyText : implode('、', $items);
    }
}
