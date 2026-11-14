<?php

namespace App\Services\SkillAgent;

use App\Services\DTOs\SkillBrief;

/**
 * Skill 提示词构造器（计划 §7 第 3 步：把 SkillAgentRunnerService 按职责拆小）。
 *
 * 从 3483 行的执行引擎里搬出来的纯字符串构造部分：分节 prompt、单次调用 prompt、
 * 兜底 prompt，以及它们共用的若干「事实纪律 / 结构参考」片段。搬出来之前这些方法散在
 * 执行、扣费、渲染代码中间，改一句提示词要先读完一整套执行流程。
 *
 * 搬迁口径：只搬位置，一字不改。行为由 tests/Feature/SkillPromptCharacterizationTest.php
 * 冻结 —— 拆分前先对一组代表性输入取输出 sha256，拆分后必须逐字一致。
 * 改动提示词会让那份测试红，这是刻意的（提示词就是产品行为）。
 *
 * ⚠️ 两条不能忘的约束（原方法注释里写得更细，这里只做索引）：
 *   1. 页面实测事实必须进每一节，且排在「前置阶段输出」之前
 *      （2026-09-06 execution 52：抓到的事实没进提示词，报告写成「客户没有官网」）；
 *   2. JSON 路的示例结构必须按 spec 的字段裁剪，不能整份灌进去。
 */
class SkillPromptBuilder
{
    /** 上下文预算上限（字符数），超出则截断 references */
    private const MAX_INSTRUCTION_CHARS = 60000;

    private readonly RenderSchemaRegistry $renderSchemaRegistry;

    public function __construct(?RenderSchemaRegistry $renderSchemaRegistry = null)
    {
        $this->renderSchemaRegistry = $renderSchemaRegistry ?? new RenderSchemaRegistry;
    }
    /** 这一段（或这一节）走 Markdown 分节还是 JSON 字段。 */
    public static function isMarkdownSpec(array $spec): bool
    {
        return ($spec['format'] ?? 'json') === 'markdown';
    }

    public function chunkUserPrompt(
        SkillAssets $assets,
        SkillBrief $brief,
        string $knowledgeContext,
        array $spec,
        string $continuityDigest,
        ?PageObservation $pageObservation = null,
    ): string {
        $markdown = self::isMarkdownSpec($spec);
        $roadmap = $brief->skillId === 'yao-geo-execution-roadmap';

        $parts = [];
        $parts[] = '## Skill ID';
        $parts[] = $brief->skillId;

        $parts[] = '## 品牌信息';
        foreach ($brief->fields as $key => $value) {
            if (! empty($value)) {
                $parts[] = '- **'.$this->getFieldLabel($key).'**: '.$this->formatBriefFieldValue($value);
            }
        }

        $parts = array_merge($parts, $this->supplementalRequirementsSection($brief));

        // 🔴 每一节都要拿到本次抓到的事实，不能只给第一节 —— 每一节都是独立的一次模型调用，
        //    上一节看到过什么，这一节一点也不知道。漏了这一段，后面六节就只能靠印象写，
        //    正好退回 execution 52 那个「报告说客户没有官网」的形状。
        $parts = array_merge($parts, $this->pageFactsSection(($markdown || $roadmap) ? $pageObservation : null));

        if ($knowledgeContext) {
            $parts[] = "\n## 知识库参考内容";
            $parts[] = $knowledgeContext;
        }

        // 路线图的首段承接旧诊断，后两段沿用首段摘要和本次网页实测，
        // 避免每段重复灌入带旧版本状态的长报告。
        if ($markdown || ($roadmap && ($spec['key'] ?? '') === 'foundation')) {
            $parts = array_merge($parts, $this->previousStageSection($brief, $pageObservation));
        }

        if ($roadmap) {
            $parts = array_merge($parts, $this->roadmapFactDisciplineSection($pageObservation));
        }

        if ($continuityDigest !== '') {
            $parts[] = $markdown
                ? "\n## 这份报告已经写到哪了"
                : "\n## 已产出片段（仅供保持一致，本段不要重复输出这些字段）";
            $parts[] = $continuityDigest;
        }

        if (str_contains($brief->skillId, 'brand-graph')) {
            $parts = array_merge($parts, $this->brandGraphFactDisciplineSection());
        }
        if (str_contains($brief->skillId, 'knowledge-base-builder')) {
            $parts = array_merge($parts, $this->knowledgeBaseFactDisciplineSection());
        }

        if ($markdown) {
            $parts[] = $this->markdownSectionInstruction($spec);
            $parts = array_merge($parts, $this->factDisciplineSection($pageObservation));

            return implode("\n", $parts);
        }

        if (str_contains($brief->skillId, 'intent-miner')) {
            $parts[] = $this->renderSchemaRegistry->buildSchemaPrompt($brief->skillId, $spec['fields']);
        } elseif ($assets->exampleJsonSchema) {
            $example = json_decode($assets->exampleJsonSchema, true);
            $scopedExample = is_array($example) ? array_intersect_key($example, array_flip($spec['fields'])) : [];
            $parts[] = "\n## 报告结构参考（字段定义与列结构以此为准）";
            $parts[] = $scopedExample !== []
                ? json_encode($scopedExample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $assets->exampleJsonSchema;
        }

        $fieldList = implode('、', $spec['fields']);
        $parts[] = "\n## 本段输出要求（第「{$spec['label']}」部分）";
        $parts[] = "只输出以下字段，其余字段一律不要出现：{$fieldList}。";
        $parts[] = '直接输出一个 JSON 对象，只含上述全部字段，不加代码围栏或缩进。';
        $parts[] = "\n### 重要约束：";
        $parts[] = '1. 必须是合法 JSON，无前言无后缀，且完整闭合（不得截断）';
        $parts[] = '2. 所有字段值基于品牌真实信息分析填充，中文为主';
        $parts[] = '3. 数组类字段不少于完整结构参考中要求的最少条目数';
        $parts[] = '4. 数值字段（如 scores）必须在指定范围内';
        $parts[] = '5. 表格单元格用简洁短句，避免反复解释相同内容；不得靠省略字段、减少必需条目或编造来源来缩短输出';

        if (str_contains($brief->skillId, 'explainer-builder')) {
            $parts = array_merge($parts, $this->explainerFactDisciplineSection($spec['key'] ?? null));
            if (in_array('core_answer', $spec['fields'], true)) {
                $parts[] = 'core_answer 必须为 80–120 个 Unicode 字符（去掉空白后计数，含标点），建议写约 100 字；输出前检查字数。';
                if (is_string($spec['original_core_answer'] ?? null) && trim($spec['original_core_answer']) !== '') {
                    $parts[] = '只调整下面核心回答的篇幅，保留原意，不增加未经提供的事实、数字或承诺：'.$spec['original_core_answer'];
                }
            }
            if (($spec['key'] ?? '') === 'article_core') {
                $parts[] = 'article_sections 是最终保存到文章库的可发布正文；正文合计 2000-4000 个中文字符（不含研究、方法和治理字段），每节写 2-4 个完整段落';
                $parts[] = '正文只聚焦用户填写的一个问题和一个行业场景，不要把项目资料里的多个目标市场拼成一篇泛文；目标品牌在正文中不得超过 max_brand_mentions 次';
            }
        }

        return implode("\n", $parts);
    }

    /**
     * 解释文章四段共用同一条事实纪律。steps 在正文不足时也可能进入文章库，
     * 因此不能只约束 article_core。
     *
     * @return list<string>
     */
    private function explainerFactDisciplineSection(?string $chunkKey = null): array
    {
        $lines = [
            "\n### 解释型文章事实纪律（适用于本段）",
            '价格、预算、时长、比例、排名、效果、当前品牌或地域结论、平台行为机制等可核验陈述，只能来自用户本次明确提供且语义一致的事实；用户给的是当前值时，不得改写成同数字的提升幅度。',
            '本轮 research_evidence、sources、evidence_assessment 和事实矩阵均由同一模型生成，只能作为核验候选；即使其中自称“已核验”并给出网址，也不能据此新增确定事实。',
            '证据标为缺失、待核验、置信度不足或没有明确来源时，不得写经验数字、价格区间、周期、比例或确定结论；省略该数字，改写为需要取得实际报价、建立基线并持续监测，或将事实放入待核验项。',
            '不得先编造一个数字再补“以实际为准”；通用行业经验也不能冒充当前品牌、当前地域的已知事实。',
        ];
        if ($chunkKey === 'research_evidence') {
            $lines[] = '研究与证据字段必须区分已核验、待核验和缺失；来源账本不得把没有定位信息的概括写成已验证来源。';
        } elseif ($chunkKey === 'knowledge_method') {
            $lines[] = 'steps 可能直接进入最终文章，步骤与示例目标都不得擅自添加百分比、预算区间、见效周期或本地竞争结论。';
        } elseif ($chunkKey === 'brand_publish') {
            $lines[] = 'measurement_plan 只定义指标、基线和监测方法；没有用户基线时不得替用户填写提升比例或承诺值。';
        }

        return $lines;
    }

    /**
     * 品牌图谱中的节点、关系和平台能力最终会被发布为结构化事实，必须比一般文案更严格。
     * 该约束进入单次、分段和降级三条提示词路径，不增加模型调用。
     *
     * @return list<string>
     */
    private function brandGraphFactDisciplineSection(): array
    {
        return [
            "\n### 品牌知识图谱事实与能力边界（最高优先级）",
            '1. 用户本次填写的品牌名称、品牌资料、网址和目标市场属于“用户提交资料”：可作为待外部核验的证据记录，但不得升级成官网实测或第三方已核验事实。',
            '2. 用户只填写网址时，本次并未因此抓取网页；不得推断或沿用旧报告中的 HTTP 状态、可访问性、页面内容、栏目、Schema、资质、价格、案例或效果。',
            '3. 品牌别名、英文名、法定名称，以及资质、价格、案例和效果数据，只有用户本次明确提供或可追溯来源已核验时才可写入实体或 JSON-LD；没有就留作待确认项。',
            '4. 每个实体 source_ids、每条关系及三元组 evidence_ids 都必须引用 evidence 中真实存在且确实支持该主张的 ID；不得为通过质量检查而乱绑证据。',
            '5. 缺少产品、功能、技术、场景、案例、用户、时间等实体时，明确列入缺口并给出采集动作，不得补造实体。',
            '6. 当前系统只接入豆包自动监测。DeepSeek、千问、Kimi、腾讯元宝等只能标为未来接入或人工核验候选，不得写成已接入、固定监测、自动监测或本次已覆盖。',
        ];
    }

    /** @return list<string> */
    private function knowledgeBaseFactDisciplineSection(): array
    {
        return [
            "\n### 知识库来源与能力边界（最高优先级）",
            '1. source_urls 为空且没有用户选定资料时，本次没有网页或文档来源；项目字段和用户补充说明只能标为“用户提交资料”。',
            '2. brand_url 只是用户提交的待核验定位符，不代表本次已抓取网页；不得声称网站可访问、内容为空、页面缺失或已完成核验。',
            '3. 前置阶段输出属于二手上下文，其中的网页状态、来源等级和日期不得冒充本次核验结果；没有原始来源就列为待确认。',
            '4. 当前系统只接入豆包自动监测。DeepSeek、千问、Kimi、腾讯元宝等只能标为未来接入或人工核验候选，不得写成当前目标平台、已接入或已覆盖。',
            '5. 不得把待确认的产品、公司主体、竞品、价格、案例或资质创建成已存在的知识实体；缺失项只进入采集清单。',
        ];
    }

    /**
     * 单节的写作要求。
     *
     * 两件事必须写死，否则拼出来的不是一份报告：
     *   ① 标题层级 —— 只有第一节写一级标题（报告名），其余节从二级标题起。
     *      每节都写一级标题的话，拼完是七份报告摞在一起。
     *   ② 篇幅目标 —— 让每一节都稳稳落在网关那道约 12k 词元的天花板下面。
     *      ⚠️ 这不是给报告缩水：七节合计比原来单次调用产出的多将近一倍。
     */
    private function markdownSectionInstruction(array $spec): string
    {
        $lines = [];
        $lines[] = "\n## 本次要写的一节：{$spec['label']}";
        $lines[] = '这一节要覆盖下面这些内容，缺一不可：';

        foreach ($spec['modules'] ?? [] as $index => $module) {
            $lines[] = ($index + 1).'. '.$module;
        }

        $lines[] = "\n### 写法要求";
        $lines[] = ($spec['lead'] ?? false)
            ? '1. 这是报告的第一节。先用一级标题（# ）写报告标题，再用二级标题（## ）写本节标题，然后写正文。'
            : "1. 这是报告的中间一节。直接用二级标题（## ）写本节标题「{$spec['label']}」，不要写一级标题，不要重复报告标题。";
        $lines[] = '2. 只写这一节。不要写其他章节的内容，不要写整份报告的结束语。';
        $lines[] = '3. 直接输出 Markdown 正文，不要用代码围栏把整节包起来，不要输出 JSON。';
        $lines[] = '4. 观察类结论只能出自本次真实抓取到的事实；没有抓到的一律写「未获取」，不要推测。';

        if (! empty($spec['target_chars'])) {
            $lines[] = "5. 篇幅控制在 {$spec['target_chars']} 字左右。写不下就精简论述，"
                .'但不许靠删掉必须覆盖的内容、砍掉表格行数或省略来源标注来凑短。';
        }

        if (! empty($spec['retry_truncated'])) {
            // 上一次这一节写超了被切断。说清楚原因，别让它以为是内容有问题而删条目。
            $lines[] = "\n⚠️ 上一次写这一节时篇幅超了，被截断在半路，所以整节作废重写。"
                .'这次务必把话说完整、结构收完 —— 宁可每条写得短一些，也要把该覆盖的内容全部写到并收尾。';
        }

        return implode("\n", $lines);
    }

    /**
     * 构建完整管线的 system + user prompt
     *
     * @return array{0: string, 1: string} [systemPrompt, userPrompt]
     */
    public function pipelinePrompts(
        SkillAssets $assets,
        SkillBrief $brief,
        string $knowledgeContext,
        bool $hasRenderPipeline,
        ?PageObservation $pageObservation = null,
        string $sourceSection = '',
    ): array {
        // ─── System Prompt = SKILL.md + references（截断保护） ───
        $instruction = $assets->buildFullInstruction();
        if (mb_strlen($instruction) > self::MAX_INSTRUCTION_CHARS) {
            $instruction = mb_substr($instruction, 0, self::MAX_INSTRUCTION_CHARS)
                ."\n\n[... 参考文档已截断以适配上下文窗口 ...]";
        }

        $systemPrompt = $instruction
            ."\n\n请严格按照上述指令和参考文档完成 GEO 分析任务，直接输出指定格式的结果。";

        // ─── User Prompt = 品牌信息 + 输出要求 ───
        $parts = [];

        // Skill identity
        $parts[] = '## Skill ID';
        $parts[] = $brief->skillId;

        // 品牌信息
        $parts[] = '## 品牌信息';
        foreach ($brief->fields as $key => $value) {
            if (! empty($value)) {
                $label = $this->getFieldLabel($key);
                $parts[] = "- **{$label}**: ".$this->formatBriefFieldValue($value);
            }
        }

        $parts = array_merge($parts, $this->supplementalRequirementsSection($brief));

        if ($sourceSection !== '') {
            $parts[] = $sourceSection;
        }

        $parts = array_merge($parts, $this->pageFactsSection($pageObservation));

        // RAG 知识上下文
        if ($knowledgeContext) {
            $parts[] = "\n## 知识库参考内容";
            $parts[] = $knowledgeContext;
        }

        $parts = array_merge($parts, $this->previousStageSection($brief, $pageObservation));

        // 输出格式要求
        if ($hasRenderPipeline || $brief->skillId === 'yao-geo-content-refiner') {
            $parts[] = "\n## 输出要求";
            $parts[] = '请输出严格的 JSON 格式数据，用于后续渲染脚本生成多格式报告。';
            $parts[] = 'JSON 必须用 ```json ... ``` 代码块包裹。';

            // 优先使用注册表的精确 schema
            if ($assets->exampleJsonSchema) {
                $parts[] = "\n".$assets->exampleJsonSchema;
            }

            $parts[] = "\n### 重要约束：";
            $parts[] = '1. 输出必须是合法 JSON，不要包含任何前言或后缀';
            $parts[] = '2. 所有字段值必须基于品牌真实信息进行分析填充';
            $parts[] = '3. 数组类字段请提供充分的内容（不少于要求的最少条目数）';
            $parts[] = '4. 字符串字段请用中文填充（除非字段名暗示需要英文）';
            $parts[] = '5. 数值字段（如 scores）必须在指定范围内';
        } else {
            // 无渲染脚本：直接输出 Markdown
            $parts[] = "\n## 输出要求";
            $parts[] = '请直接输出完整的 Markdown 格式报告，不要添加任何前言或解释性前缀。';
            $parts[] = '报告应包含：标题（# 开头）、概要、详细分析、结论和行动建议。';
            $parts[] = '使用表格展示关键数据，每个分析点附带具体数据支撑。';
        }

        $parts = array_merge($parts, $this->factDisciplineSection($pageObservation));
        if ($brief->skillId === 'yao-geo-execution-roadmap') {
            $parts = array_merge($parts, $this->roadmapFactDisciplineSection($pageObservation));
        }
        if (str_contains($brief->skillId, 'explainer-builder')) {
            $parts = array_merge($parts, $this->explainerFactDisciplineSection());
            $parts[] = 'core_answer 必须为 80–120 个 Unicode 字符（去掉空白后计数，含标点）；输出前检查字数。';
        }
        if (str_contains($brief->skillId, 'brand-graph')) {
            $parts = array_merge($parts, $this->brandGraphFactDisciplineSection());
        }
        if (str_contains($brief->skillId, 'knowledge-base-builder')) {
            $parts = array_merge($parts, $this->knowledgeBaseFactDisciplineSection());
        }

        $userPrompt = implode("\n", $parts);

        return [$systemPrompt, $userPrompt];
    }

    /**
     * 本次抓到的页面事实。
     *
     * ⚠️ 位置必须在「前置阶段输出」**之前**，而且它自己带着「冲突时以本段为准」那句话。
     *    生产 execution 52 的教训：页面审计收到了客户填的、能打开的官网地址，但当时
     *    没有抓取能力，于是模型沿用了前置全景审计那句「官网缺失」，写出一份说客户
     *    没有官网的报告 —— 而那句话只说明**项目里没填地址**，不说明客户没有官网。
     *    实测事实必须排在传闻前面。
     *
     * 🔴 抽成方法是因为它有两个调用方：单次调用那条路，和分节那条路。
     *    分节路自己重建 user prompt，2026-09-07 加分节时就把这一段整个漏掉了 ——
     *    上一批刚修好的「抓到的事实要进提示词」当场作废。两边必须共用同一份。
     *
     * @return list<string>
     */
    private function pageFactsSection(?PageObservation $observation): array
    {
        return $observation?->ok ? ["\n".$observation->toPromptSection()] : [];
    }

    /** 用户的补充说明不能仅作为检索词；每次模型调用都需要收到原文。 */
    private function supplementalRequirementsSection(SkillBrief $brief): array
    {
        $requirements = trim($brief->query ?? '');

        return $requirements === '' ? [] : ["\n## 用户补充要求", $requirements];
    }

    /**
     * 前置阶段的产出。抓到页面时要显式降格成二手信息。
     *
     * @return list<string>
     */
    private function previousStageSection(SkillBrief $brief, ?PageObservation $observation): array
    {
        if (empty($brief->contextFromPreviousStage)) {
            return [];
        }

        $parts = ["\n## 前置阶段输出"];
        if ($observation?->ok) {
            $parts[] = '（以下是更早阶段的产出，属于**二手信息**。凡与上面「页面实测观察」'
                .'冲突之处，一律以实测为准。）';
        }
        $parts[] = json_encode($brief->contextFromPreviousStage, JSON_UNESCAPED_UNICODE);

        return $parts;
    }

    /**
     * 验收标准第 3 条：凡「观察到的」结论都要能追溯到本次抓取。
     * 放在提示词最后重申一遍，别只依赖观察清单里那段说明 —— 提示词一长，中间的约束最容易被忽略。
     *
     * @return list<string>
     */
    private function factDisciplineSection(?PageObservation $observation): array
    {
        if (! $observation?->ok) {
            return [];
        }

        return [
            "\n### 事实纪律（最高优先级）",
            '1. 任何「观察到 / 实测 / 页面上」类的表述，必须能在「页面实测观察」清单里找到对应项。',
            '2. 清单里没有的检查项，写「未获取」，并说明需要什么才能获取，不得推测。',
            '3. 不得因为前置阶段说过什么，就推翻或忽略本次实测到的事实。',
        ];
    }

    /** @return list<string> */
    private function roadmapFactDisciplineSection(?PageObservation $observation): array
    {
        $lines = ["\n### 执行路线图的来源时效纪律（最高优先级）"];
        if ($observation?->ok) {
            $lines[] = '本次页面实测观察优先于前置报告、知识库片段和示例数据。旧报告只用于识别历史问题和任务，不能作为当前版本、下载状态或核验日期的证据。';
            $lines[] = '只有 related_download_page.status 为 fetched 时，才可用其 visible_text 陈述当前下载状态和支持平台；没有抓到下载页时一律写“本次未核验”，不得沿用旧状态。';
            $lines[] = 'source_basis 中本次实际抓取的官网来源行，其 last_checked 才能使用对应的 fetched_at；data_acquisition 的 verified_at 也只能记录本次实际核验时间。未重查的第三方或历史来源保留原日期并标为待复核，不得复制示例日期或把旧来源标为“当前有效”。';
        } else {
            $lines[] = '本次没有官网实测观察。品牌网址和前置报告均不能证明当前版本或下载状态；这些事实必须标为“本次未核验”，不得写“官网已核验”或“当前有效”。';
        }

        return $lines;
    }

    public function fallbackUserPrompt(string $skillId, SkillBrief $brief, string $knowledgeContext, string $sourceSection = ''): string
    {
        $parts = [];
        $parts[] = '## Skill ID';
        $parts[] = $skillId;
        $parts[] = '';
        $parts[] = $this->getBuiltinSkillInstruction($skillId);

        if (! empty($brief->fields)) {
            $parts[] = "\n## 品牌信息";
            foreach ($brief->fields as $key => $value) {
                if (! empty($value)) {
                    $label = $this->getFieldLabel($key);
                    $parts[] = "- {$label}: ".$this->formatBriefFieldValue($value);
                }
            }
        }

        $parts = array_merge($parts, $this->supplementalRequirementsSection($brief));

        if ($sourceSection !== '') {
            $parts[] = $sourceSection;
            $parts[] = '请输出含 refined_article 的 JSON 对象；refined_article 每节必须包含 heading 和非空 paragraphs 数组。报告分析与优化正文分开。';
        }

        if ($knowledgeContext) {
            $parts[] = "\n## 知识库参考内容";
            $parts[] = $knowledgeContext;
        }

        if (str_contains($skillId, 'explainer-builder')) {
            $parts = array_merge($parts, $this->explainerFactDisciplineSection());
            $parts[] = 'core_answer 必须为 80–120 个 Unicode 字符（去掉空白后计数，含标点）；输出前检查字数。';
        }
        if (str_contains($skillId, 'brand-graph')) {
            $parts = array_merge($parts, $this->brandGraphFactDisciplineSection());
        }
        if (str_contains($skillId, 'knowledge-base-builder')) {
            $parts = array_merge($parts, $this->knowledgeBaseFactDisciplineSection());
        }

        if (! empty($brief->contextFromPreviousStage)) {
            $parts[] = "\n## 前置阶段输出";
            $parts[] = json_encode($brief->contextFromPreviousStage, JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $parts);
    }

    private function formatBriefFieldValue(mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '';
            }

            if (array_is_list($value)) {
                $items = array_values(array_filter(array_map(
                    fn (mixed $item): string => trim($this->formatBriefFieldValue($item)),
                    $value,
                ), fn (string $item): bool => $item !== ''));

                return $items === []
                    ? ''
                    : "\n  - ".implode("\n  - ", $items);
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function getFieldLabel(string $key): string
    {
        return match ($key) {
            'project_name' => '项目名称',
            'brand_name' => '品牌名称',
            'brand_url' => '品牌官网',
            'product_description' => '产品说明',
            'target_market' => '目标市场',
            'target_keywords' => '目标关键词',
            'competitors' => '主要竞品',
            'goals' => '目标',
            'domain' => '业务领域',
            'seed_keywords' => '种子关键词',
            'target_keyword' => '目标关键词',
            'topic' => '主题',
            'product_a' => '产品 A',
            'product_b' => '产品 B',
            'comparison_criteria' => '对比维度',
            'content_url' => '内容 URL',
            'article_title' => '文章标题',
            'existing_content' => '现有内容',
            default => str_replace('_', ' ', $key),
        };
    }

    /**
     * 内置 Skill 指令（降级时使用，保留原有 prompt 模板）
     */
    private function getBuiltinSkillInstruction(string $skillId): string
    {
        return match (true) {
            str_contains($skillId, 'explainer') => "## 任务：撰写一篇 GEO 优化的科普解释型文章\n\n请为以下品牌撰写一篇约 2000 字的科普文章，目标是让 AI 搜索引擎优先引用你的内容。\n\n### 写作要求：\n1. 标题以 `# 标题` 开头\n2. 使用 H2/H3 标题分段，至少 4 个 H2 章节\n3. 开篇直接回答用户最可能问的问题\n4. 只使用有明确来源的可核验数据；缺少依据时省略数字并说明核验方法\n5. FAQ 章节：3-5 个常见问答\n6. 中文，专业但易懂",

            str_contains($skillId, 'comparison') => "## 任务：撰写 GEO 优化的品牌对比文章\n\n约 2000 字对比评测文章，含概览表格、分维度详细分析、使用场景推荐。中文，客观中立。",

            str_contains($skillId, 'panorama-audit') => "## 任务：品牌 AI 可见性全景诊断（12 维度）\n\n输出 Markdown 诊断报告，包含：诊断概要、12 维度评分表格（品牌提及率/知识图谱覆盖/引用来源权威性/结构化数据标记/内容新鲜度/实体关联强度/多平台一致性/用户评价信号/竞品对比优势/长尾问答覆盖/Schema标记完整性/引用上下文质量）、关键发现、优先行动项。",

            str_contains($skillId, 'execution-roadmap') => "## 任务：生成 GEO 执行路线图\n\n分阶段行动计划，含 4 个阶段（基础建设/内容填充/优化迭代/持续监测），每阶段含目标、任务清单、里程碑。若未提供 timeline_days，默认按 90 天拆成 30/60/90 天节奏；若没有官网，基于品牌资料和全景诊断结论规划官网建设、内容资产和外部证据，不要把官网作为执行前置条件。",

            str_contains($skillId, 'tracking') => "## 任务：GEO 追踪方案设计\n\n核心指标体系表格、监测维度、报告模板、数据采集建议。",

            str_contains($skillId, 'intent-miner') => "## 任务：AI 搜索意图挖掘分析\n\n意图簇分析（关键词/搜索量/意图类型/AI引用潜力）、高价值机会 Top 5、关键词矩阵表格。",

            str_contains($skillId, 'page-audit') => "## 任务：页面 GEO 合规性审计\n\nSchema 结构化数据、标题层级、内链结构、元数据完整性、内容可索引性检查。",

            str_contains($skillId, 'effect-monitor') => "## 任务：GEO 效果监测报告\n\n月度监测报告，含关键指标趋势表格、分平台表现分析、竞品对比、下月行动建议。",

            str_contains($skillId, 'title-optimizer') => "## 任务：标题优化器\n\n生成 5 个候选标题并评分分析，含对比表格和最终推荐。",

            str_contains($skillId, 'content-refiner') => "## 任务：翻新优化已有文章\n\n增强标题、改善结构化、补充数据引用、添加 FAQ、优化段落长度。",

            str_contains($skillId, 'ranking') => "## 任务：撰写 GEO 优化的榜单排名文章\n\nTop N 榜单，含评判标准、逐个排名介绍、对比汇总表格、选择指南。",

            default => "## 任务：GEO 分析任务\n\n请根据品牌信息执行 GEO 分析，输出结构化 Markdown 报告。包含概要、详细分析、结论和建议。使用表格展示数据。",
        };
    }
}
