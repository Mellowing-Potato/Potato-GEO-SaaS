<?php

namespace App\Services\SkillAgent;

/**
 * 渲染脚本 JSON Schema 注册表
 *
 * 为每个 Skill 的渲染脚本定义精确的 JSON 输入要求：
 * - 必须存在的顶层字段（required）
 * - 嵌套字段约束（nested）
 * - 数组最少数目（minimums）
 * - 自动填充的默认值（defaults）
 *
 * 数据来源：分析各 render_*.py 脚本的 validate_report_input / MINIMUMS / setdefault 逻辑。
 *
 * 用途：
 *   1. 注入 LLM prompt → 要求输出严格匹配的 JSON
 *   2. RenderBridge 在调用 Python 前自动补齐缺失字段
 */
class RenderSchemaRegistry
{
    public const INTENT_MINER_SECTION_MINIMUM = 15;

    public const INTENT_MINER_QUESTION_MINIMUM = 18;

    public const INTENT_MINER_FOLLOW_UP_MINIMUM = 8;

    public const INTENT_MINER_REQUIRED_SECTION_KEYWORDS = [
        '执行摘要',
        '研究依据',
        '事实校准',
        '真实数据',
        '输入归一化',
        '用户角色',
        '意图地图',
        '问题簇',
        '查询重写',
        '国内 AI 平台',
        '内容资产',
        '监测 Prompt',
        '证据缺口',
        '合规',
        '落地路线',
    ];

    /**
     * 获取指定 Skill 的 schema 定义
     *
     * @return array{required: string[], nested: array, minimums: array, defaults: array, prompt_hint: string}|null
     */
    public function getSchema(string $skillId): ?array
    {
        return match (true) {
            str_contains($skillId, 'title-optimizer') => $this->titleOptimizer(),
            str_contains($skillId, 'intent-miner') => $this->intentMiner(),
            str_contains($skillId, 'panorama-audit') => $this->panoramaAudit(),
            str_contains($skillId, 'explainer') => $this->explainerBuilder(),
            str_contains($skillId, 'tracking') => $this->tracking(),
            str_contains($skillId, 'execution-roadmap') => $this->executionRoadmap(),
            str_contains($skillId, 'ranking') => $this->rankingArticle(),
            str_contains($skillId, 'content-refiner') => $this->contentRefiner(),
            str_contains($skillId, 'brand-graph') => $this->brandGraph(),
            str_contains($skillId, 'page-blueprint') => $this->pageBlueprint(),
            str_contains($skillId, 'knowledge-base') => $this->knowledgeBaseBuilder(),
            default => null,
        };
    }

    /**
     * 生成 LLM 可直接使用的 JSON Schema 描述文本
     */
    public function buildSchemaPrompt(string $skillId, ?array $fields = null): ?string
    {
        $schema = $this->getSchema($skillId);
        if (! $schema) {
            return null;
        }

        if ($fields !== null) {
            $selected = array_flip($fields);
            $itemFields = $this->itemFields($skillId);
            $schema['required'] = array_intersect_key($schema['required'] ?? [], $selected);
            foreach ($fields as $field) {
                $schema['required'][$field] ??= isset($itemFields[$field]) || is_array($schema['defaults'][$field] ?? null) ? 'array' : 'string';
            }
            foreach ($itemFields as $field => $columns) {
                $schema['nested'][$field.'[]'] ??= array_fill_keys($columns, '按字段含义填充');
            }
            $schema['nested'] = array_filter($schema['nested'] ?? [],
                fn (string $field): bool => isset($selected[explode('[', $field)[0]]), ARRAY_FILTER_USE_KEY);
            $schema['minimums'] = array_intersect_key($schema['minimums'] ?? [], $selected);
            // 全局提示含其他章节的最少行数，分段时由上面筛选后的约束表达。
            $schema['prompt_hint'] = '';
        }

        $lines = [];
        $lines[] = '### 输出 JSON Schema（严格遵守）';
        $lines[] = '';
        $lines[] = '以下是渲染脚本要求的 JSON 结构，你的输出必须严格匹配：';
        $lines[] = '';

        // Required fields
        if (! empty($schema['required'])) {
            $lines[] = '**必须包含的顶层字段：**';
            foreach ($schema['required'] as $field => $desc) {
                $lines[] = "- `{$field}`: {$desc}";
            }
            $lines[] = '';
        }

        // Nested constraints
        if (! empty($schema['nested'])) {
            $lines[] = '**嵌套字段约束：**';
            foreach ($schema['nested'] as $field => $constraints) {
                $lines[] = "- `{$field}` 中每个元素必须包含：";
                foreach ($constraints as $key => $desc) {
                    $lines[] = "  - `{$key}`: {$desc}";
                }
            }
            $lines[] = '';
        }

        // Minimums
        if (! empty($schema['minimums'])) {
            $lines[] = '**数组最少条目数：**';
            foreach ($schema['minimums'] as $field => $min) {
                $lines[] = "- `{$field}`: 至少 {$min} 条";
            }
            $lines[] = '';
        }

        // Prompt hint
        if (! empty($schema['prompt_hint'])) {
            $lines[] = '**重要提示：**';
            $lines[] = $schema['prompt_hint'];
        }

        // Named-table column definitions (EXEC-07): tell the model exactly what each column
        // means, and forbid it from emitting its own header row (the frontend no longer guesses).
        $tableHeaders = str_contains($skillId, 'title-optimizer') ? [] : $this->tableHeaders($skillId);
        if ($fields !== null) {
            $tableHeaders = array_intersect_key($tableHeaders, array_flip($fields));
        }
        if (! empty($tableHeaders)) {
            $lines[] = '';
            $lines[] = '**命名表格区域的列定义（每行必须按此列序输出，列数必须一致）：**';
            foreach ($tableHeaders as $sectionKey => $columns) {
                $lines[] = "- `{$sectionKey}`：".implode(' | ', $columns);
            }
            $lines[] = '';
            $lines[] = '注意：这些区域是**纯数据行**的二维数组，**不要**自己输出表头行。';
        }

        return implode("\n", $lines);
    }

    /**
     * 命名表格区域的列定义（表头唯一权威）。
     *
     * 这份数据与各 render_*.py 的 HEADERS / SECTION_SPECS 逐字节一致，
     * 由 EXEC-07 从 Python 搬入，作为「前端显示」与「提示词列义」的唯一来源：
     *   - buildSchemaPrompt() 注入提示词 → 模型知道每列写什么（数据质量）
     *   - SkillAgentRunnerService::prepareStructuredInput() 注入 payload 的 table_headers → 前端直接读（显示正确）
     * 前端不再猜测表头（猜测会把每张表第一行真数据吃掉当列标题）。
     *
     * 按技能分层返回整份映射：不同技能的同名键（如 sources / self_review）列义不同，
     * 拍平到全局 key→headers 会串台，故必须按技能取各自那份。
     */
    public function tableHeaders(string $skillId): array
    {
        return match (true) {
            str_contains($skillId, 'panorama-audit') => $this->panoramaAuditHeaders(),
            str_contains($skillId, 'content-refiner') => $this->contentRefinerHeaders(),
            str_contains($skillId, 'title-optimizer') => $this->titleOptimizerHeaders(),
            default => [],
        };
    }

    /**
     * 对 LLM 输出的 JSON 进行字段补齐，确保通过渲染脚本校验
     */
    public function normalizeInput(string $skillId, array $data): array
    {
        $schema = $this->getSchema($skillId);
        if (! $schema) {
            return $data;
        }

        $data = $this->normalizeProvidedInput($skillId, $data);

        // 第二步：补齐 defaults
        if (! empty($schema['defaults'])) {
            foreach ($schema['defaults'] as $key => $default) {
                if (! array_key_exists($key, $data)) {
                    $data[$key] = $default;
                }
            }
        }

        // 第三步：补齐 minimums（数组条目不足时复制最后一条填充）。
        // 意图挖掘的条目是可交付研究结果，复制最后一条会制造“数量达标”的假象；
        // 它的真实数量由分段完整性闸检查，不足时交给模型定向补写。
        if (! empty($schema['minimums']) && ! str_contains($skillId, 'intent-miner') && ! str_contains($skillId, 'title-optimizer')) {
            foreach ($schema['minimums'] as $field => $min) {
                if (isset($data[$field]) && is_array($data[$field]) && count($data[$field]) < $min && count($data[$field]) > 0) {
                    $template = end($data[$field]);
                    while (count($data[$field]) < $min) {
                        $data[$field][] = $template;
                    }
                }
            }
        }

        if (str_contains($skillId, 'page-blueprint')) {
            $data = $this->normalizePageBlueprintContract($data);
        }

        return $data;
    }

    /** 只归一已交付的内容，不补字段、不复制条目；供完整性闸在渲染前检查真实行数。 */
    public function normalizeProvidedInput(string $skillId, array $data): array
    {
        return $this->normalizeItemShapes($skillId, $this->applyKeyAliases($skillId, $data));
    }

    /** @return list<string> */
    public function requiredSectionKeywords(string $skillId): array
    {
        return str_contains($skillId, 'intent-miner')
            ? self::INTENT_MINER_REQUIRED_SECTION_KEYWORDS
            : [];
    }

    // ═══════════════════════════════════════════════════
    //  Key Alias Mapping & Data Format Normalization
    // ═══════════════════════════════════════════════════

    /**
     * 将 AI 输出的 key 映射为渲染脚本期望的 key，
     * 同时将数据格式统一为二维数组 (rows × cols)。
     */
    private function applyKeyAliases(string $skillId, array $data): array
    {
        return match (true) {
            str_contains($skillId, 'panorama-audit') => $this->applyPanoramaAliases($data),
            default => $data,
        };
    }

    /**
     * 全景诊断：AI 常见输出 key → 渲染脚本期望 key 的映射 + 格式归一化
     */
    private function applyPanoramaAliases(array $data): array
    {
        // AI 输出 key → 渲染脚本期望 key（按优先级排列，先匹配先用）
        $aliasMap = [
            'method_basis' => [
                'methodology_and_scoring',
                'method_basis',
                'methodology',
                'scoring_methodology',
                '方法依据',
            ],
            'authority_map' => [
                'authority_reference_mapping',
                'authority_map',
                'authority_references',
                '权威参考',
            ],
            'question_coverage' => [
                'user_query_coverage_matrix',
                'question_coverage',
                'query_coverage',
                'user_questions',
                '用户问题覆盖',
            ],
            'baseline' => [
                'official_site_crawl_and_verification',
                'baseline',
                'crawl_verification',
                'fact_verification',
                '官网验证',
            ],
            'crawl_inventory' => [
                'official_site_crawl_list',
                'crawl_inventory',
                'content_and_asset_inventory',
                'site_crawl',
                '抓取清单',
            ],
            'entity_profile' => [
                'brand_entity_profile',
                'entity_profile',
                'brand_profile',
                '品牌实体',
            ],
            'product_map' => [
                'product_service_map',
                'product_map',
                'products',
                '产品地图',
            ],
            'technical_audit' => [
                'technical_seo_structured_data',
                'technical_audit',
                'technical_seo',
                '技术诊断',
            ],
            'competitors' => [
                'external_signals_and_competitors',
                'competitors',
                'competitor_analysis',
                '竞品分析',
            ],
            'scores' => [
                'geo_opportunity_map',
                'scores',
                'geo_scores',
                'feature_scores',
                'GEO评分',
            ],
            'opportunities' => [
                'onsite_system_plan',
                'opportunities',
                'opportunity_map',
                'geo_opportunities',
                '机会地图',
            ],
            'repairs' => [
                'page_and_asset_fix_list',
                'repairs',
                'fix_list',
                'page_fixes',
                '修复清单',
            ],
            'offsite_plan' => [
                'offsite_evidence_plan',
                'offsite_plan',
                'offsite_strategy',
                '站外方案',
            ],
            'sources' => [
                'sources',
                'source_registry',
                'references',
                '来源台账',
            ],
            'risk_register' => [
                'risks_and_assumptions',
                'risk_register',
                'risks',
                '风险登记',
            ],
        ];

        // 对每个期望 key，检查数据中是否存在别名 key
        foreach ($aliasMap as $targetKey => $aliases) {
            // 如果期望 key 已存在且有数据，跳过
            if (isset($data[$targetKey]) && ! empty($data[$targetKey])) {
                continue;
            }
            // 尝试每个别名
            foreach ($aliases as $alias) {
                if ($alias === $targetKey) {
                    continue; // 跳过自身
                }
                if (isset($data[$alias]) && ! empty($data[$alias])) {
                    $data[$targetKey] = $this->ensureTableRows($data[$alias]);
                    unset($data[$alias]);
                    break;
                }
            }
        }

        // 归一化 summary（executive_summary → summary）
        if (empty($data['summary']) && ! empty($data['executive_summary'])) {
            $exec = $data['executive_summary'];
            if (is_array($exec)) {
                if (isset($exec[0]) && is_array($exec[0])) {
                    $data['summary'] = $exec;
                } else {
                    $pairs = [];
                    foreach ($exec as $k => $v) {
                        if (is_string($k) && ! is_int($k)) {
                            $pairs[] = [$k, is_array($v) ? implode(', ', $v) : (string) $v];
                        }
                    }
                    $data['summary'] = $pairs ?: $exec;
                }
            }
        }

        // 归一化 meta（report_meta → meta）
        if (empty($data['meta']) && ! empty($data['report_meta'])) {
            $rm = $data['report_meta'];
            if (is_array($rm)) {
                if (isset($rm[0]) && is_array($rm[0])) {
                    $data['meta'] = $rm;
                } else {
                    $pairs = [];
                    foreach ($rm as $k => $v) {
                        if (is_string($k) && ! is_int($k)) {
                            $pairs[] = [$k, is_array($v) ? implode(', ', $v) : (string) $v];
                        }
                    }
                    $data['meta'] = $pairs ?: $rm;
                }
            }
        }

        return $data;
    }

    /**
     * 确保数据是二维数组 (rows × cols) 格式，
     * 兼容 AI 输出的对象、关联数组、一维数组等各种格式。
     */
    private function ensureTableRows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        if (empty($data)) {
            return [];
        }

        // 关联数组（单个对象）→ 取其 values 作为一行
        if (! array_is_list($data)) {
            return [array_values($data)];
        }

        // 索引数组
        $first = $data[0];

        // 数组的数组 → 每行转为 indexed
        if (is_array($first)) {
            return array_map(function ($row) {
                if (! is_array($row)) {
                    return [$row];
                }

                return array_is_list($row) ? $row : array_values($row);
            }, $data);
        }

        // 扁平标量列表 → 包装为单行
        return [$data];
    }

    // ═══════════════════════════════════════════════════
    //  Item Shape Normalization（条目列表形状归一）
    // ═══════════════════════════════════════════════════

    /**
     * 条目列表字段的列定义：字段名 => 该字段每个条目应有的 key 列表。
     *
     * 与对应 render_*.py 的 APPENDICES fields 逐字段一致（顺序也一致：模型偶尔把条目
     * 写成二维数组，那时只能按位置还原成 key）。加新技能就在这里加一份；没登记的技能
     * 不做形状归一，行为与从前完全相同。
     *
     * @return array<string, list<string>>
     */
    private function itemFields(string $skillId): array
    {
        if (! str_contains($skillId, 'intent-miner')) {
            return [];
        }

        return [
            'summary_cards' => ['label', 'value', 'note'],
            'question_bank' => ['question_id', 'cluster', 'intent_type', 'question', 'standalone_rewrite', 'retrieval_rewrite', 'evidence_query', 'content_asset', 'priority', 'compliance_level'],
            'scoring_matrix' => ['question_id', 'cluster', 'business_value', 'ai_answer_probability', 'content_gap', 'brand_insert_space', 'evidence_availability', 'competition_difficulty', 'conversation_extension', 'decision_stage_value', 'platform_coverage', 'compliance_risk', 'weighted_score', 'priority'],
            'follow_up_chains' => ['chain_id', 'root_question_id', 'parent_question_id', 'follow_up_level', 'context_dependency', 'follow_up_question', 'standalone_rewrite', 'platform_fit'],
            'prompt_library' => ['prompt_id', 'platform', 'intent_type', 'prompt', 'usage', 'record_fields'],
            'data_sources' => ['source_id', 'source_type', 'provider', 'access_status', 'record_count', 'date_range', 'calibration_use', 'next_action'],
            'ai_sampling_plan' => ['sample_id', 'platform', 'prompt', 'status', 'brand_mentioned', 'cited_sources', 'risk_flags', 'next_action'],
            'calibration_actions' => ['action_id', 'signal', 'affected_dimensions', 'current_status', 'method', 'owner'],
            'content_topics' => ['topic_id', 'title', 'source_question', 'asset_type', 'evidence_need', 'priority'],
            'faq_bank' => ['faq_id', 'question', 'answer_boundary', 'evidence_need', 'target_asset', 'compliance_level'],
            'knowledge_base_entries' => ['entry_id', 'title', 'entry_type', 'covered_questions', 'required_materials', 'owner'],
            'evidence_sources' => ['source', 'level', 'use', 'url', 'status'],
            'action_roadmap' => ['phase', 'task', 'deliverable', 'owner', 'acceptance'],
        ];
    }

    /**
     * 把登记过的条目列表字段统一成 [{key: 值}, ...]；sections 另有自己的形状，单独处理。
     */
    private function normalizeItemShapes(string $skillId, array $data): array
    {
        foreach ($this->itemFields($skillId) as $key => $fields) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $data[$key] = $this->normalizeItemList($data[$key], $fields);
        }

        if (str_contains($skillId, 'intent-miner') && array_key_exists('sections', $data)) {
            $data['sections'] = $this->normalizeSections($data['sections']);
        }

        return $data;
    }

    /**
     * 单个条目列表的归一。认四种写法：
     *   [{...}, ...]        原样保留（缺的 key 由 Python 的 .get 兜底成空串）
     *   [[列1, 列2, ...]]   按位置还原成 key（列序即 itemFields 的顺序）
     *   ["文本", ...]       落到第一个字段，至少让内容出现在报告里而不是被吞掉
     *   {id: {...}, ...}    取 values
     *
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function normalizeItemList(mixed $value, array $fields): array
    {
        if ($fields === [] || $value === null || $value === '' || $value === []) {
            return [];
        }

        $firstField = $fields[0];

        if (! is_array($value)) {
            return [[$firstField => $value]];
        }

        if (! array_is_list($value)) {
            // 键里出现已知字段名 = 这本身就是一条记录；否则当作 {id: 记录} 映射
            $value = array_intersect(array_keys($value), $fields) !== []
                ? [$value]
                : array_values($value);
        }

        $items = [];
        foreach ($value as $item) {
            if ($item === null || $item === '' || $item === []) {
                continue;
            }
            if (! is_array($item)) {
                $items[] = [$firstField => $item];

                continue;
            }
            if (! array_is_list($item)) {
                $items[] = $item;

                continue;
            }
            $row = [];
            foreach ($fields as $index => $field) {
                $row[$field] = $item[$index] ?? '';
            }
            $items[] = $row;
        }

        return $items;
    }

    /**
     * 正文段落归一：每节必须是 {title, paragraphs: [文本], table?: {headers, rows}}。
     * 整节写成一句话时把它当正文（塞进 paragraphs），不当标题——脚本的质检按标题关键词
     * 判「必需章节是否齐全」，拿正文冒充标题会让质检结果失真。
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeSections(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (! is_array($value)) {
            return [['title' => '', 'paragraphs' => [$value]]];
        }

        if (! array_is_list($value)) {
            $value = array_intersect(array_keys($value), ['title', 'paragraphs', 'table']) !== []
                ? [$value]
                : array_values($value);
        }

        $sections = [];
        foreach ($value as $section) {
            if ($section === null || $section === '' || $section === []) {
                continue;
            }
            if (! is_array($section)) {
                $sections[] = ['title' => '', 'paragraphs' => [$section]];

                continue;
            }
            if (array_is_list($section)) {
                // [标题, 正文, 正文...] 这种位置写法
                $sections[] = [
                    'title' => $section[0] ?? '',
                    'paragraphs' => array_values(array_slice($section, 1)),
                ];

                continue;
            }

            $paragraphs = $section['paragraphs'] ?? [];
            if ($paragraphs === null || $paragraphs === '') {
                $paragraphs = [];
            } elseif (! is_array($paragraphs)) {
                $paragraphs = [$paragraphs];
            } else {
                $paragraphs = array_values($paragraphs);
            }
            $section['paragraphs'] = $paragraphs;

            if (array_key_exists('table', $section)) {
                $section['table'] = $this->normalizeSectionTable($section['table']);
                if ($section['table'] === null) {
                    unset($section['table']);
                }
            }

            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * 节内表格归一：headers 必须是一维列表，rows 必须是二维列表。
     * 形状不可救时返回 null（去掉这张表），好过让脚本崩在半路。
     */
    private function normalizeSectionTable(mixed $table): ?array
    {
        if (! is_array($table) || $table === []) {
            return null;
        }

        $headers = $table['headers'] ?? [];
        if (! is_array($headers)) {
            $headers = [$headers];
        }
        $headers = array_values(array_filter($headers, static fn ($header) => $header !== null && $header !== ''));

        // 这里不能用 ensureTableRows()：它见到非数组一律返回 []，会把「rows 写成一段
        // 文本」的内容整段丢掉。渲染脚本的 normalize_table 是把它当成一行，两边保持一致。
        $rawRows = $table['rows'] ?? [];
        if (is_array($rawRows) && ! array_is_list($rawRows)) {
            $rawRows = array_values($rawRows);
        } elseif (! is_array($rawRows)) {
            $rawRows = [$rawRows];
        }

        $rows = [];
        foreach ($rawRows as $row) {
            if (is_array($row)) {
                $rows[] = array_values($row);
            } elseif ($row !== null && $row !== '') {
                $rows[] = [$row];
            }
        }

        if ($headers === [] && $rows === []) {
            return null;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    // ═══════════════════════════════════════════════════
    //  Per-Skill Schema Definitions
    // ═══════════════════════════════════════════════════

    private function titleOptimizer(): array
    {
        return [
            'required' => [
                'output_stem' => 'string — 文件名基础（如 "yao-geo-title-optimizer-report-xxx"）',
                'report_title' => 'string — 报告标题',
                'generated_at' => 'string — ISO 日期时间',
                'project' => 'object — 包含 name, module, priority, project_date, region, audience, target_platforms(list), article_types(list)',
                'title_candidates' => 'array — 每个元素含 id, title, type, intent, scenario, platform_fit, why_it_works, rewrite_advice, scores(object)',
                'recommended_id' => 'string — 必须指向 title_candidates 中一个稳定 ID',
                'recommendation' => 'string — 推荐理由，不重复生成或改写候选标题',
                'usage_advice' => 'array — 1–3 条使用建议',
            ],
            'nested' => [
                'project' => [
                    'name' => 'string',
                    'module' => 'string',
                    'priority' => 'string（如 "P0"）',
                    'project_date' => 'string',
                    'region' => 'string',
                    'audience' => 'string',
                    'target_platforms' => 'array of strings',
                    'article_types' => 'array of strings',
                ],
                'title_candidates[].scores' => [
                    'intent_match' => 'number 0-5',
                    'entity_clarity' => 'number 0-5',
                    'differentiation' => 'number 0-5',
                    'citation_potential' => 'number 0-5',
                    'compliance' => 'number 0-5',
                    'freshness' => 'number 0-5',
                ],
            ],
            'minimums' => [
                'title_candidates' => 5,
            ],
            'defaults' => [
                'subtitle' => '',
                'prepared_by' => 'yao-geo-title-optimizer',
                'method_summary' => [],
                'scenario_selection' => null,
                'evidence_sources' => null,
                'platform_adaptation' => [],
                'data_source_audit' => null,
                'platform_sampling_plan' => null,
                'reference_frameworks' => null,
                'analysis_dimensions' => null,
                'entity_intent_matrix' => null,
                'title_pattern_library' => null,
                'coverage_gaps' => null,
                'publication_checklist' => null,
            ],
            'prompt_hint' => '默认 5 个候选，允许 5–8 个；ID 使用 T1、T2 等且不能重复。所有 scores 为 0–5 的建议评分，不能称作已验证点击率或排名。报告只聚焦候选标题、推荐标题和使用建议，正文约 1200 汉字以内。旧版研究表及审查字段可省略。',
        ];
    }

    private function intentMiner(): array
    {
        return [
            'required' => [
                'title' => 'string — 报告标题',
                'brand_name' => 'string — 品牌名称',
                'analysis_date' => 'string — 分析日期',
                'summary_cards' => 'array — 摘要卡片 [{label, value, note}]',
                'sections' => 'array — 正文段落 [{title, paragraphs, table?}]；至少 15 段，标题必须分别覆盖：'
                    .implode('、', self::INTENT_MINER_REQUIRED_SECTION_KEYWORDS),
            ],
            'nested' => [
                'summary_cards[]' => [
                    'label' => 'string',
                    'value' => 'string or number',
                    'note' => 'string',
                ],
                'sections[]' => [
                    'title' => 'string — 段落标题',
                    'paragraphs' => 'array of strings — 段落文本列表',
                    'table' => 'object (optional) — {headers: [str], rows: [[any]]}',
                ],
                'question_bank[]' => [
                    'question_id' => 'string',
                    'cluster' => 'string',
                    'intent_type' => 'string',
                    'question' => 'string',
                    'standalone_rewrite' => 'string',
                    'retrieval_rewrite' => 'string',
                    'evidence_query' => 'string',
                    'content_asset' => 'string',
                    'priority' => 'string',
                    'compliance_level' => 'string（以 "L" 开头）',
                ],
            ],
            'minimums' => [
                'sections' => self::INTENT_MINER_SECTION_MINIMUM,
                'summary_cards' => 2,
                'question_bank' => self::INTENT_MINER_QUESTION_MINIMUM,
                'follow_up_chains' => self::INTENT_MINER_FOLLOW_UP_MINIMUM,
            ],
            'defaults' => [
                'slug' => '',
                'subtitle' => '',
                'prepared_by' => 'yao-geo-intent-miner',
                'limitations' => '本报告基于公开数据和 AI 分析生成，仅供参考。',
                'scoring_matrix' => [],
                'follow_up_chains' => [],
                'prompt_library' => [],
                'data_sources' => [],
                'ai_sampling_plan' => [],
                'calibration_actions' => [],
                'content_topics' => [],
                'faq_bank' => [],
                'knowledge_base_entries' => [],
                'evidence_sources' => [],
                'action_roadmap' => [],
            ],
            'prompt_hint' => 'sections 是报告正文，至少 15 个段落，并完整覆盖规定的 15 类章节。'
                .'question_bank 是核心产出，至少 18 条真实且不重复的问题；follow_up_chains 至少 8 条。'
                .'不得在摘要中声称高于实际数组数量的条目数。每个 question_bank 条目的 compliance_level 必须以 "L" 开头（如 "L1-合规"）。',
        ];
    }

    private function panoramaAudit(): array
    {
        return [
            'required' => [
                'title' => 'string — 报告标题',
                'brand_name' => 'string — 品牌名称',
                'subtitle' => 'string — 报告副标题',
            ],
            'nested' => [],
            'minimums' => [
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
            ],
            'defaults' => [
                'slug' => '',
                'subtitle' => '',
                'prepared_by' => 'yao-geo-panorama-audit',
                'notice' => '本报告由 AI 生成，仅供参考。',
                'limitations' => '本报告基于公开数据和 AI 分析生成，不代表最终商业结论。',
                'summary' => [],
                'meta' => [],
                'summary_cards' => [],
                'method_basis' => [],
                'authority_map' => [],
                'question_coverage' => [],
                'baseline' => [],
                'crawl_inventory' => [],
                'entity_profile' => [],
                'product_map' => [],
                'technical_audit' => [],
                'competitors' => [],
                'scores' => [],
                'opportunities' => [],
                'repairs' => [],
                'offsite_plan' => [],
                'sources' => [],
                'risk_register' => [],
                'diagnostic_questions' => [],
                'section_meanings' => (object) [],
                'conclusion' => '',
                'sections' => [],
            ],
            'prompt_hint' => '全景诊断报告包含 15 个命名表格区域（method_basis, authority_map, question_coverage, baseline, crawl_inventory, entity_profile, product_map, technical_audit, competitors, scores, opportunities, repairs, offsite_plan, sources, risk_register），每个区域是一个二维数组（行 × 列）。section_meanings 是一个对象，key 为区域名，value 为该区域的判断含义说明。conclusion 是结论文本。',
        ];
    }

    private function explainerBuilder(): array
    {
        return [
            'required' => [
                'title' => 'string — 文章标题',
                'topic' => 'string — 主题',
                'target_brand' => 'string — 目标品牌',
                'target_audience' => 'string — 目标受众',
                'use_case' => 'string — 使用场景',
                'core_answer' => 'string — 80-120 字核心回答',
                'research_basis' => 'array — 至少 6 条研究依据 [{paper, finding, design_rule}]',
                'definitions' => 'array — 至少 2 条定义 [{term, definition}]',
                'principles' => 'array — 至少 2 条原理 [{title, explanation}]',
                'steps' => 'array — 至少 3 个步骤 [{title, action, check}]',
                'selection_criteria' => 'array — 至少 3 条选择标准 [{criterion, how_to_judge, suitable_for, risk}]',
                'misconceptions' => 'array — 至少 2 条常见误区 [{mistake, correction, risk}]',
                'faq' => 'array — 至少 3 条 FAQ [{question, answer}]',
                'glossary' => 'array — 至少 3 条术语 [{term, definition}]',
                'brand_insertions' => 'array — 品牌植入建议 [{position, suggestion, reason}]',
                'sources' => 'array — 至少 8 条来源 [{label, locator, usage}]',
            ],
            'nested' => [],
            'minimums' => [
                'article_sections' => 6,
                'research_basis' => 6,
                'evidence_assessment' => 3,
                'data_source_plan' => 3,
                'fact_verification_matrix' => 3,
                'entity_map' => 3,
                'definitions' => 2,
                'principles' => 2,
                'steps' => 3,
                'pre_publish_checks' => 3,
                'selection_criteria' => 3,
                'decision_paths' => 2,
                'misconceptions' => 2,
                'faq' => 3,
                'glossary' => 3,
                'schema_recommendations' => 2,
                'measurement_plan' => 2,
                'content_gap_opportunities' => 2,
                'sources' => 8,
            ],
            'defaults' => [
                'skill_id' => 'yao-geo-explainer-builder',
                'slug' => '',
                'analysis_date' => '',
                'max_brand_mentions' => 3,
                'brand_insertion_allowed' => true,
                'key_values' => [],
                'brand_scenarios' => [],
                'platform_notes' => [],
                'compliance_notes' => [],
                'pending_confirmations' => [],
                'article_sections' => [],
                'analysis_summary' => [],
                'problem_type_map' => [],
                'audience_scenarios' => [],
                'evidence_assessment' => [],
                'data_availability' => ['status' => 'limited', 'usable_data' => [], 'missing_data' => [], 'safe_conclusion' => '', 'next_action' => ''],
                'data_source_plan' => [],
                'fact_verification_matrix' => [],
                'entity_map' => [],
                'pre_publish_checks' => [],
                'decision_paths' => [],
                'schema_recommendations' => [],
                'measurement_plan' => [],
                'content_gap_opportunities' => [],
            ],
            'prompt_hint' => '这是一个非常复杂的报告结构，包含 16 个必须字段和 20+ 个数组字段。每个数组有最低条目数要求。article_sections 是文章正文主体，至少 6 个段落。sources 至少 8 条来源引用。',
        ];
    }

    private function tracking(): array
    {
        return [
            'required' => [
                'title' => 'string — 报告标题',
                'company_name' => 'string — 公司/品牌名称',
                'analysis_date' => 'string — 分析日期',
                'summary_cards' => 'array — 摘要卡片 [{label, value, note?}]',
                'sections' => 'array — 正文段落 [{title, paragraphs?, bullets?, table?, callout?, flow?, allocation?}]',
            ],
            'nested' => [
                'sections[]' => [
                    'title' => 'string',
                    'paragraphs' => 'array of strings (optional)',
                    'bullets' => 'array of strings (optional)',
                    'table' => 'object {headers: [str], rows: [[any]]} (optional)',
                ],
            ],
            'minimums' => [
                'summary_cards' => 2,
                'sections' => 3,
            ],
            'defaults' => [
                'subtitle' => '',
                'prepared_by' => 'yao-geo-tracking',
                'slug' => '',
            ],
            'prompt_hint' => '这是相对简单的 schema。sections 是报告正文，每个 section 包含 title + paragraphs/bullets/table。table.rows 中每行的列数必须与 table.headers 一致。',
        ];
    }

    private function executionRoadmap(): array
    {
        return [
            'required' => [
                'title' => 'string',
                'brand_name' => 'string',
                'analysis_date' => 'string',
                'summary_cards' => 'array',
                'sections' => 'array',
            ],
            'nested' => [],
            'minimums' => ['sections' => 3, 'summary_cards' => 2],
            'defaults' => [
                'subtitle' => '',
                'prepared_by' => 'yao-geo-execution-roadmap',
                'slug' => '',
            ],
            'prompt_hint' => '输出结构同 tracking：summary_cards + sections。sections 包含阶段规划内容。',
        ];
    }

    private function rankingArticle(): array
    {
        return [
            'required' => [
                'title' => 'string',
                'brand_name' => 'string',
                'analysis_date' => 'string',
                'summary_cards' => 'array',
                'sections' => 'array',
            ],
            'nested' => [],
            'minimums' => ['sections' => 3, 'summary_cards' => 2],
            'defaults' => [
                'subtitle' => '',
                'prepared_by' => 'yao-geo-ranking-article-builder',
                'slug' => '',
            ],
            'prompt_hint' => '输出结构同 tracking：summary_cards + sections。sections 包含榜单排名内容。',
        ];
    }

    private function contentRefiner(): array
    {
        return [
            'required' => [
                'title' => 'string — 报告标题',
                'subtitle' => 'string — 报告副标题',
                'notice' => 'string — 事实与来源边界提醒',
                'meta' => 'object — 报告元信息',
                'summary' => 'array of strings — 报告摘要',
                'refined_article' => 'array — 优化后的完整文章正文，每节含 heading 和 paragraphs 字符串数组；与分析报告分开',
                'scorecard' => 'array — 原文评分数据行',
                'diff_report' => 'array — 改造前后差异数据行',
                'fact_cards' => 'array — 原子事实卡数据行',
                'faq' => 'array — 问答数据行',
                'research_alignment' => 'array — 理论依据数据行',
                'sources' => 'array — 来源与证据边界数据行',
                'self_review' => 'array — 自查数据行',
                'cms_html_advice' => 'array of strings — CMS 使用建议',
            ],
            'nested' => [
                'refined_article[]' => [
                    'heading' => 'string — 正文标题或小节标题',
                    'paragraphs' => 'array of non-empty strings — 优化后的正文段落',
                ],
            ],
            'minimums' => ['sections' => 3, 'summary_cards' => 2, 'refined_article' => 1],
            'defaults' => [
                'subtitle' => '',
                'prepared_by' => 'yao-geo-content-refiner',
                'slug' => '',
            ],
            'prompt_hint' => '报告主体是 14 张命名数据表，每张都是**纯数据行**的二维数组（列定义见下方「命名表格区域的列定义」），不要输出表头行：'
                .'completeness_map、data_access_plan、source_access_log、scorecard、diff_report、fact_cards、faq、'
                .'semantic_entity_map、platform_matrix、research_alignment、sources、publishing_plan、measurement_plan、self_review。'
                .'这 14 张表都必须输出且非空：关键表 completeness_map / scorecard / diff_report / fact_cards / sources 至少 3 行，'
                .'其余至少 1 行。summary_cards 与 sections 不是本技能契约，可不输出。refined_article 必须是实际优化后的正文，不能放评分表、研究表或原始 JSON。',
        ];
    }

    private function brandGraph(): array
    {
        return [
            'required' => [
                'title' => 'string',
                'brand_name' => 'string',
                'scope' => 'string',
                'report_date' => 'string',
                'executive_summary' => 'array',
                'authority_references' => 'array',
                'analysis_dimensions' => 'array',
                'source_coverage_matrix' => 'array',
                'source_validation' => 'array',
                'entity_coverage_matrix' => 'array',
                'relation_audit_matrix' => 'array',
                'schema_alignment' => 'array',
                'content_alignment_plan' => 'array',
                'monitoring_plan' => 'array',
                'analysis_completeness_checklist' => 'array',
                'entities' => 'array',
                'relations' => 'array',
                'evidence' => 'array',
                'disambiguation' => 'array',
                'trust_table' => 'array',
                'mermaid' => 'string',
                'json_ld' => 'object',
                'triples' => 'array',
                'page_recommendations' => 'array',
                'open_issues' => 'array',
            ],
            'nested' => [
                'entities[]' => ['id' => '实体 ID', 'type' => '实体类型', 'name' => '名称', 'aliases' => '别名数组', 'description' => '说明', 'source_ids' => '来源 ID 数组', 'confidence' => '可信等级', 'privacy_review' => '隐私状态'],
                'relations[]' => ['subject' => '主体实体 ID', 'predicate' => '关系', 'object' => '客体实体 ID', 'direction' => '方向说明', 'evidence_ids' => '证据 ID 数组', 'confidence' => '可信等级', 'notes' => '说明'],
                'evidence[]' => ['id' => '证据 ID', 'source_type' => '来源类型', 'title' => '证据标题', 'locator' => '可追溯定位符', 'date' => '日期', 'claim' => '该证据支持的事实', 'verification_status' => '核验状态', 'how_used' => '用途'],
                'triples[]' => ['subject' => '主体实体 ID', 'predicate' => '关系', 'object' => '客体实体 ID', 'evidence_ids' => '证据 ID 数组'],
            ],
            'minimums' => [
                'executive_summary' => 1,
                'entities' => 1,
                'relations' => 1,
                'evidence' => 1,
                'disambiguation' => 1,
                'trust_table' => 1,
                'triples' => 1,
                'page_recommendations' => 1,
            ],
            'defaults' => [
                'subtitle' => '',
                'prepared_by' => 'yao-geo-brand-graph',
                'slug' => '',
            ],
            'prompt_hint' => '以上字段是品牌知识图谱渲染器的真实契约；不要使用 tracking 的 summary_cards + sections 结构。'
                .'每个实体的 source_ids、每个关系和三元组的 evidence_ids 都只能引用 evidence 中真实存在的 ID；'
                .'用户本次填写的品牌资料可以登记为“用户提交资料”证据，并注明待外部核验。'
                .'用户填写的 brand_url 只是待核验定位符，不代表本次已抓取网页，不得据此声称 HTTP 状态、页面内容或可访问性。'
                .'品牌别名、英文名、法定名称，以及资质、价格、案例和效果数据，只有用户明确提供或来源已核验时才可写入；缺失的实体类型保留为缺口。'
                .'当前系统只接入豆包自动监测；其他平台只能标为未来或人工核验候选，不得写成已接入、固定监测或自动监测能力。',
        ];
    }

    private function pageBlueprint(): array
    {
        return [
            'required' => [
                'title' => 'string',
                'brand_name' => 'string',
                'analysis_date' => 'string',
                'summary_cards' => 'array',
                'sections' => 'array',
            ],
            'nested' => [],
            'minimums' => ['sections' => 3, 'summary_cards' => 2],
            'defaults' => [
                'subtitle' => '',
                'prepared_by' => 'yao-geo-page-blueprint',
                'slug' => '',
            ],
            'prompt_hint' => '输出结构同 tracking：summary_cards + sections。报告必须出现「GEO 页面设计方案」；'
                .'sections 必须依次包含这些 title，一字不差：'
                .'「输入、假设与边界」「真实数据接入与核验计划」「查询意图与 Query Fan-out 覆盖」'
                .'「页面模块与信息架构图」「研究依据与页面设计原则」「实体关系与知识图谱字段」'
                .'「证据区与来源台账」「AI 可抽取模块设计」「用户转化模块设计」「HTML 结构样例」'
                .'「Schema 建议」「CMS 字段清单」「无障碍与页面体验要求」「实施验收与监测计划」'
                .'「桌面端、移动端和公众号版建议」。Schema 建议中必须明确写出「FAQPage 正文可见」。'
                .'当前产品仅接入豆包监测；不得把 DeepSeek、千问、Kimi、元宝等写成已接入或固定监测平台，'
                .'如需提及，只能标为未来接入后的候选。',
        ];
    }

    /**
     * 修正不改变语义的标题变体，并补入渲染器要求的固定规则说明。
     *
     * 页面蓝图上游脚本按逐字关键词做自检。模型已交付完整章节时，诸如
     * 「信息架构」与「信息架构图」的轻微差异不该让整份报告误判失败；这里
     * 只规范固定契约文案，不补业务事实，也不制造客户案例或平台能力。
     */
    private function normalizePageBlueprintContract(array $data): array
    {
        $subtitle = trim((string) ($data['subtitle'] ?? ''));
        if (! str_contains($subtitle, 'GEO 页面设计方案')) {
            $data['subtitle'] = $subtitle === ''
                ? 'GEO 页面设计方案'
                : 'GEO 页面设计方案｜'.$subtitle;
        }

        $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];
        foreach ($sections as &$section) {
            if (! is_array($section)) {
                continue;
            }

            $title = trim((string) ($section['title'] ?? ''));
            if ($title === '页面模块与信息架构') {
                $section['title'] = '页面模块与信息架构图';
            }

            if (($section['title'] ?? '') === 'Schema 建议') {
                $encoded = json_encode($section, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (! is_string($encoded) || ! str_contains($encoded, 'FAQPage 正文可见')) {
                    $bullets = is_array($section['bullets'] ?? null) ? $section['bullets'] : [];
                    $bullets[] = 'FAQPage 正文可见：只有页面正文真实展示相同问答时才使用 FAQPage，且不承诺富结果展示。';
                    $section['bullets'] = $bullets;
                }
            }
        }
        unset($section);
        $data['sections'] = $sections;

        return $data;
    }

    private function knowledgeBaseBuilder(): array
    {
        return [
            'required' => [
                'title' => 'string',
                'brand_name' => 'string',
                'analysis_date' => 'string',
                'summary_cards' => 'array',
                'sections' => 'array',
            ],
            'nested' => [],
            'minimums' => ['sections' => 7, 'summary_cards' => 2],
            'defaults' => [
                'subtitle' => '',
                'prepared_by' => 'yao-geo-knowledge-base-builder',
                'slug' => '',
            ],
            /*
             * 章节标题不是可选的排版偏好：render_four_format.py 的
             * REQUIRED_SECTION_GROUPS 会在正文里逐个找这几个标题，找不到质检就不过。
             * summary_cards 会被适配层渲染成「结构化摘要」，所以下面 7 个由 sections 出。
             *
             * 少一个就少一节 —— 适配层只做转换、不编造内容，这里靠提示词把内容要出来。
             */
            'prompt_hint' => '输出结构同 tracking：summary_cards + sections。'
                .'sections 必须依次包含这 7 个 title，一字不差（渲染脚本按标题校验，'
                .'缺一个交付物质检就不通过）：'
                .'「系统化品牌知识库」「完整实体清单」「品牌事实卡片库」「Prompt 输入包」'
                .'「真实数据获取与限制」「来源索引」「分析完整性自检」。'
                .'其中「真实数据获取与限制」要如实写明本次数据来源与未覆盖范围，不要写成套话。'
                .'source_urls 为空且没有文档时，只能把项目字段和用户补充说明标为“用户提交资料”；'
                .'brand_url 只是待核验定位符，不代表已抓取、可访问或内容为空。'
                .'当前系统只接入豆包自动监测，其他平台只能标为未来或人工核验候选。',
        ];
    }

    // ═══════════════════════════════════════════════════
    //  Per-Skill Table Header Maps (EXEC-07)
    //  逐字抄自各技能的 render_*.py，作为表头唯一权威。
    // ═══════════════════════════════════════════════════

    /**
     * 全景诊断表头 — 抄自 yao-geo-panorama-audit/scripts/render_panorama.py:30 HEADERS
     */
    private function panoramaAuditHeaders(): array
    {
        return [
            'method_basis' => ['方法环节', '执行方式', '关键字段', '控制风险'],
            'authority_map' => ['参考来源', '采用原则', '报告落点', '边界'],
            'question_coverage' => ['意图类型', '用户问题', '官网覆盖状态', '缺口判断', '站内用途'],
            'baseline' => ['事实域', '官网页面/入口', '官网可抽取事实', '交叉验证来源', '核验状态', '优化方向'],
            'crawl_inventory' => ['页面类型', 'URL/入口', '状态', '可抽取事实', '缺失字段', '下一步'],
            'entity_profile' => ['实体项', '当前口径', '来源', '核验状态', '处理建议'],
            'product_map' => ['产品/模块', '核心能力', '适用对象', '商业口径', '事实来源', '缺口'],
            'technical_audit' => ['技术项', '状态', '证据', '风险', '修复动作'],
            'competitors' => ['对标对象', '公开可见优势', '目标品牌差距', '可借鉴证据', '注意边界'],
            'scores' => ['特征', '评分', '证据判断', '优先动作'],
            'opportunities' => ['方案类型', '机会', '对应问题', '价值', '工作量', '优先级', '负责人建议', '验收标准'],
            'repairs' => ['页面或模块', '主要问题', '修复动作', '输出组件', '依赖来源', '验收口径'],
            'offsite_plan' => ['渠道/信源', '建设动作', '支撑断言', '优先级', '负责人建议', '验收口径'],
            'sources' => ['事实项', '来源类型', '核验状态', '用途', '备注'],
            'risk_register' => ['风险', '影响', '优先级', '负责人建议', '处理方式'],
        ];
    }

    /**
     * 内容优化表头 — 抄自 yao-geo-content-refiner/scripts/render_content_refiner_bundle.py:51 HEADERS
     * 注意：本技能的 sources / self_review 列义与 title-optimizer 不同，必须按技能分层。
     */
    private function contentRefinerHeaders(): array
    {
        return [
            'completeness_map' => ['分析维度', '覆盖内容', '对应输出', '缺失风险', '当前状态'],
            'data_access_plan' => ['数据项', '获取方式', '来源/接口', '权限与边界', '新鲜度', '失败处理'],
            'source_access_log' => ['来源', '访问方式', '访问时间', '提取字段', '核验状态', '备注'],
            'scorecard' => ['维度', '评分', '证据判断', '优先动作'],
            'diff_report' => ['位置', '原文问题', '改造动作', '改造后效果', '风险'],
            'fact_cards' => ['主体', '属性', '数值', '时间', '来源', '适用边界', '核验状态', '可引用句'],
            'faq' => ['问题', '答案', '类型'],
            'semantic_entity_map' => ['实体/术语', '全称或别名', '语义关系', '对应用户问题', '补强建议'],
            'platform_matrix' => ['平台/场景', '推荐结构', '适配重点', '风险控制'],
            'research_alignment' => ['理论', '研究启发', '落地改造'],
            'sources' => ['事实项', '来源', '来源类型', '证据强度', '证据状态', '备注'],
            'publishing_plan' => ['发布动作', '目的', '交付物', '检查点'],
            'measurement_plan' => ['观察指标', '观察方式', '建议周期', '解释边界'],
            'self_review' => ['检查项', '检查结果', '状态'],
        ];
    }

    /**
     * 标题优化表头 — 抄自 render_yao_geo_title_optimizer.py:35 SECTION_SPECS（前 8 条）
     *              + :198/:211/:215/:219 四处内联表头（后 4 条）
     */
    private function titleOptimizerHeaders(): array
    {
        return [
            'data_source_audit' => ['数据对象', '采集方式', '状态', '使用边界'],
            'platform_sampling_plan' => ['平台', '真实采样问题', '采样输出', '判定方法'],
            'reference_frameworks' => ['来源', '权威性', '可借鉴原则', '如何用于标题实验'],
            'analysis_dimensions' => ['维度', '核心问题', '标题信号', '补充要求'],
            'entity_intent_matrix' => ['主体/实体', '用户意图', '场景限定', '标题表达要求'],
            'title_pattern_library' => ['结构', '适用场景', '标题公式', '风险边界'],
            'coverage_gaps' => ['缺口', '影响', '补全动作', '优先级'],
            'publication_checklist' => ['检查项', '标准', '状态', '说明'],
            'platform_adaptation' => ['平台', '标题侧重点', '推荐表达'],
            'compliance_checks' => ['检查项', '对象', '结果', '说明'],
            'structure_map' => ['标题ID', '文章结构', '段落与证据模块', 'FAQ映射'],
            'self_review' => ['类型', '发现的问题', '已修复动作'],
        ];
    }
}
