<?php

namespace App\Services\SkillAgent;

use App\Services\Contracts\SkillRegistry as SkillRegistryContract;
use App\Services\DTOs\SkillDefinition;
use App\Services\Enums\SkillCategory;
use App\Services\Exceptions\SkillRegistryException;

/**
 * Skill 注册表实现。
 *
 * 开放范围由已审定清单固定；默认注册表随应用发布。
 * 外部注册表只能提供已审定技能的元数据，不能自动开放新能力。
 */
class SkillRegistryService implements SkillRegistryContract
{
    /** Local business entries have no upstream model or rendering assets. */
    public const LOCAL_ACTION_IDS = ['yao-geo-channel-publishing'];

    /*
     * 停用位（SkillDefinition::$unavailableReason）目前一个都没用上，这是有意的。
     *
     * 2026-08-07 曾有两个技能因「RenderBridge 传 --input，脚本要 --source」而停用；
     * 适配层（RenderBridgeService::MARKDOWN_SOURCE_SCRIPTS + StructuredMarkdownBuilder）
     * 做出来后，知识库构建当场恢复（质检 20/20 全过、四种格式齐全），排名型文章则因为
     * 缺 pandoc 出 Word 又多停了 13 天 —— 2026-08-20 查明那次停用是误伤，见下面它自己
     * 的注释。要再停用某个技能，写清「客户为什么点不了」并同步
     * tests/Feature/SkillAvailabilityGateTest.php 的 DISABLED。
     */

    /** @var SkillDefinition[]|null 缓存 */
    private ?array $cache = null;

    public function all(): array
    {
        return $this->cache ??= $this->buildDefinitions();
    }

    public function find(string $skillId): SkillDefinition
    {
        return $this->findOrNull($skillId)
            ?? throw new \InvalidArgumentException("Skill not found: {$skillId}");
    }

    public function findOrNull(string $skillId): ?SkillDefinition
    {
        foreach ($this->all() as $skill) {
            if ($skill->id === $skillId) {
                return $skill;
            }
        }

        return null;
    }

    public function findByCategory(SkillCategory $category): array
    {
        return array_values(
            array_filter($this->all(), fn (SkillDefinition $s) => $s->category === $category)
        );
    }

    public function findByStage(int $stageIndex): array
    {
        return array_values(
            array_filter($this->all(), fn (SkillDefinition $s) => $s->stageIndex === $stageIndex)
        );
    }

    public function categories(): array
    {
        return SkillCategory::cases();
    }

    public function exists(string $skillId): bool
    {
        return $this->findOrNull($skillId) !== null;
    }

    public function productionReady(): array
    {
        return array_values(
            array_filter($this->all(), fn (SkillDefinition $s) => $s->isProductionReady())
        );
    }

    private function buildDefinitions(): array
    {
        $curatedById = $this->curatedDefinitionsById();
        $sourceSkills = $this->loadSourceSkills();

        return array_values(array_map(
            fn (array $metadata) => $this->definitionFromRegistry($metadata, $curatedById[(string) $metadata['id']] ?? null),
            $sourceSkills,
        ));
    }

    /**
     * @return array<string, SkillDefinition>
     */
    private function curatedDefinitionsById(): array
    {
        $definitions = [];

        foreach ($this->curatedDefinitions() as $definition) {
            $definitions[$definition->id] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadSourceSkills(): array
    {
        $path = config('geo.skill_registry_path');
        if (! is_string($path) || $path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new SkillRegistryException('技能注册表缺失或不可读；请检查 GEO_SKILL_REGISTRY_PATH。');
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! is_array($decoded['skills'] ?? null) || ! array_is_list($decoded['skills'])) {
            throw new SkillRegistryException('技能注册表必须是包含 skills 数组的有效 JSON。');
        }
        $allowed = config('geo.skill_allowed_ids');
        if (! is_array($allowed) || ! array_is_list($allowed) || $allowed === []) {
            throw new SkillRegistryException('已审定技能清单缺失或为空。');
        }
        $validId = fn ($id) => is_string($id) && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) === 1;
        foreach ($allowed as $id) {
            if (! $validId($id)) {
                throw new SkillRegistryException('已审定技能清单包含无效 ID。');
            }
        }
        if (count(array_unique($allowed)) !== count($allowed)) {
            throw new SkillRegistryException('已审定技能清单包含重复 ID。');
        }
        $byId = [];
        foreach ($decoded['skills'] as $skill) {
            if (! is_array($skill) || ! $validId($skill['id'] ?? null)) {
                throw new SkillRegistryException('技能注册表包含无效条目或 ID。');
            }
            $id = $skill['id'];
            if (isset($byId[$id])) {
                throw new SkillRegistryException('技能注册表包含重复 ID：'.$id);
            }
            $byId[$id] = $skill;
        }
        foreach ($allowed as $id) {
            if (in_array($id, self::LOCAL_ACTION_IDS, true)) {
                $byId[$id] = ['id' => $id, 'status' => 'active'];
            }
            if (($byId[$id]['status'] ?? null) !== 'active') {
                throw new SkillRegistryException('已审定技能在注册表中缺失或未激活：'.$id);
            }
        }

        return array_map(fn ($id) => $byId[$id], $allowed);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function definitionFromRegistry(array $metadata, ?SkillDefinition $curated): SkillDefinition
    {
        $id = (string) $metadata['id'];
        $category = $this->categoryFromFamily((string) ($metadata['family'] ?? ''), $curated);

        return new SkillDefinition(
            id: $id,
            name: $curated?->name ?: $this->displayNameFromId($id),
            category: $curated?->category ?: $category,
            maturity: $curated?->maturity ?: $this->maturityFromMetadata($metadata),
            requiresWeb: $curated?->requiresWeb ?? (bool) ($metadata['requires_web'] ?? false),
            stageIndex: $curated?->stageIndex ?? $this->stageIndexForCategory($category),
            description: $curated?->description ?: (string) ($metadata['summary'] ?? $id),
            sceneDescription: $curated?->sceneDescription ?: (string) ($metadata['summary'] ?? ''),
            effectDescription: $curated?->effectDescription ?: $this->effectDescriptionFromOutputs($metadata),
            briefFields: $curated?->briefFields ?? $this->defaultBriefFieldsForCategory($category),
            renderScriptPath: $curated?->renderScriptPath,
            referencePaths: $curated?->referencePaths ?? $this->referencePathsFromMetadata($metadata),
            actionPath: $curated?->actionPath,
            actionLabel: $curated?->actionLabel,
            unavailableReason: $curated?->unavailableReason,
        );
    }

    private function categoryFromFamily(string $family, ?SkillDefinition $curated): SkillCategory
    {
        return SkillCategory::tryFrom($family)
            ?? $curated?->category
            ?? SkillCategory::GeoOperations;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function maturityFromMetadata(array $metadata): string
    {
        $maturity = (string) ($metadata['maturity'] ?? 'draft');

        return in_array($maturity, ['scaffold', 'draft', 'beta', 'stable'], true)
            ? $maturity
            : 'draft';
    }

    private function stageIndexForCategory(SkillCategory $category): int
    {
        return match ($category) {
            SkillCategory::GeoResearch => 0,
            SkillCategory::GeoStrategy => 1,
            SkillCategory::GeoContentProduction,
            SkillCategory::GeoKnowledgeAssets => 2,
            SkillCategory::GeoPageTechnical => 3,
            SkillCategory::GeoMeasurement => 4,
            SkillCategory::GeoOperations => -1,
        };
    }

    private function displayNameFromId(string $id): string
    {
        $name = preg_replace('/^yao-/', '', $id) ?: $id;
        $name = preg_replace('/^geo-/', 'GEO ', $name) ?: $name;

        return str_replace('Geo ', 'GEO ', str($name)->replace('-', ' ')->headline()->toString());
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function effectDescriptionFromOutputs(array $metadata): string
    {
        $outputs = $metadata['primary_outputs'] ?? [];
        if (! is_array($outputs) || $outputs === []) {
            return '生成可保存、可追踪的 GEO 工作流结果。';
        }

        return '主要产出：'.implode('、', array_slice(array_map('strval', $outputs), 0, 4));
    }

    private function defaultBriefFieldsForCategory(SkillCategory $category): array
    {
        $fields = [
            ['key' => 'brand_name', 'label' => '品牌名称', 'type' => 'text', 'required' => true],
            ['key' => 'brand_url', 'label' => '品牌官网', 'type' => 'url', 'required' => false],
        ];

        if ($category === SkillCategory::GeoContentProduction) {
            $fields[] = ['key' => 'topic', 'label' => '内容主题', 'type' => 'text', 'required' => true];
        }

        $fields[] = ['key' => 'notes', 'label' => '补充要求', 'type' => 'textarea', 'required' => false];

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function referencePathsFromMetadata(array $metadata): array
    {
        return array_values(array_unique(array_filter([
            $metadata['path'] ?? null,
            $metadata['public_repo_path'] ?? null,
        ], 'is_string')));
    }

    /**
     * 本地业务化覆盖层。保留原有客户侧名称、阶段、brief 字段和说明。
     *
     * @return SkillDefinition[]
     */
    private function curatedDefinitions(): array
    {
        return [
            // ─── 阶段一：研究拓词 ───
            new SkillDefinition(
                id: 'yao-geo-intent-miner',
                name: '意图挖掘',
                category: SkillCategory::GeoResearch,
                maturity: 'draft',
                requiresWeb: false,
                stageIndex: 0,
                description: '挖掘目标领域的用户搜索意图和长尾关键词',
                sceneDescription: '输入你的品牌或产品，系统自动分析用户在 AI 搜索中会怎么提问',
                effectDescription: '获得一份完整的关键词清单，包含搜索量预估和意图分类',
                briefFields: [
                    ['key' => 'domain', 'label' => '业务领域', 'type' => 'text', 'required' => true],
                    ['key' => 'seed_keywords', 'label' => '种子关键词', 'type' => 'textarea', 'required' => false],
                ],
            ),

            // ─── 阶段二：策略诊断 ───
            new SkillDefinition(
                id: 'yao-geo-panorama-audit',
                name: '综合诊断',
                category: SkillCategory::GeoStrategy,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 1,
                description: '12 个诊断域的全面 GEO 审计',
                sceneDescription: '从品牌、官网、内容与竞争环境等维度做一次全面 GEO 体检',
                effectDescription: '获得覆盖内容质量、引用频率、竞品对比等 12 个维度的诊断报告',
                briefFields: [
                    ['key' => 'brand_name', 'label' => '品牌名称', 'type' => 'text', 'required' => true],
                    ['key' => 'brand_url', 'label' => '品牌官网（可选，没有官网可留空）', 'type' => 'url', 'required' => false],
                    ['key' => 'competitors', 'label' => '主要竞品（每行一个）', 'type' => 'textarea', 'required' => false],
                ],
            ),
            new SkillDefinition(
                id: 'yao-geo-execution-roadmap',
                name: '执行路线图',
                category: SkillCategory::GeoStrategy,
                maturity: 'beta',
                requiresWeb: false,
                stageIndex: 1,
                description: '基于诊断结果生成 30/60/90 天执行计划',
                sceneDescription: '根据体检结果，自动生成一份分阶段的优化行动路线图',
                effectDescription: '获得按优先级排序的任务清单，附带时间线和预期效果',
                briefFields: [
                    ['key' => 'brand_name', 'label' => '品牌名称', 'type' => 'text', 'required' => true],
                    ['key' => 'brand_url', 'label' => '品牌官网（可选，没有官网可留空）', 'type' => 'url', 'required' => false],
                    ['key' => 'timeline_days', 'label' => '计划执行周期（天，可选）', 'type' => 'number', 'required' => false, 'default' => 90, 'min' => 1, 'max' => 365, 'step' => 1],
                ],
            ),
            new SkillDefinition(
                id: 'yao-geo-tracking',
                name: '效果追踪方案',
                category: SkillCategory::GeoStrategy,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 1,
                description: '设计效果追踪的指标体系和采集方案',
                sceneDescription: '设定关键指标，配置自动追踪方案，后续监测阶段直接使用',
                effectDescription: '获得一套包含指标定义、采集频率、预警阈值的追踪配置',
                briefFields: [
                    ['key' => 'brand_name', 'label' => '品牌名称', 'type' => 'text', 'required' => true],
                    ['key' => 'tracking_keywords', 'label' => '追踪关键词', 'type' => 'textarea', 'required' => true],
                ],
            ),

            // ─── 阶段三：内容生产 ───
            new SkillDefinition(
                id: 'yao-geo-explainer-builder',
                name: '解释型文章',
                category: SkillCategory::GeoContentProduction,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 2,
                description: '生成"什么是 X"类解释型长文',
                sceneDescription: '写一篇权威的解释型文章，让 AI 搜索在回答"什么是"类问题时引用你',
                effectDescription: '产出 2000-4000 字的 SEO/GEO 优化编辑稿，附研究参考与待核验项',
                briefFields: [
                    ['key' => 'topic', 'label' => '文章要解释的问题或主题', 'type' => 'text', 'required' => true],
                    ['key' => 'target_keyword', 'label' => '希望覆盖的搜索词', 'type' => 'text', 'required' => true],
                ],
            ),
            new SkillDefinition(
                id: 'yao-geo-comparison-builder',
                name: '对比型文章',
                category: SkillCategory::GeoContentProduction,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 2,
                description: '生成"X vs Y"类对比评测文章',
                sceneDescription: '写一篇产品对比文章，在 AI 回答"A 和 B 哪个好"时被引用',
                effectDescription: '产出包含对比表格、优劣势分析的评测文章',
                briefFields: [
                    ['key' => 'product_a', 'label' => '产品 A', 'type' => 'text', 'required' => true],
                    ['key' => 'product_b', 'label' => '产品 B', 'type' => 'text', 'required' => true],
                    ['key' => 'comparison_criteria', 'label' => '对比维度', 'type' => 'textarea', 'required' => false],
                ],
            ),
            new SkillDefinition(
                id: 'yao-geo-ranking-article-builder',
                name: '排名型文章',
                category: SkillCategory::GeoContentProduction,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 2,
                description: '生成"最佳 X 推荐"类排名列表文章',
                sceneDescription: '写一篇排行榜文章，让 AI 在推荐"最好的 X"时提到你的品牌',
                effectDescription: '产出结构化的排名列表文章，品牌自然植入推荐位置',
                briefFields: [
                    ['key' => 'category', 'label' => '产品类别', 'type' => 'text', 'required' => true],
                    ['key' => 'brand_position', 'label' => '品牌期望排名位置', 'type' => 'number', 'required' => false],
                ],
                /*
                 * 交付格式：Markdown / 网页 / PDF 三种，没有 Word —— 这不是缺陷，
                 * 产品要求本来就是这三种。
                 *
                 * 2026-08-07 曾因「缺 Word」把整个技能停掉，2026-08-20 查明是误伤：
                 * render_ranking_article_pack.py 的 main() 是顺序写四个文件，
                 * .md → .html → .pdf 都已落盘，最后一步 write_docx() 才因为
                 * `shutil.which("pandoc")` 找不到而抛 RuntimeError、脚本非零退出；
                 * 而 RenderBridge::render() 本来就是「先收产物再看退出码」，收到任一
                 * 渲染文件即判部分成功。所以三种格式一直是拿得到的，停用等于为了客户
                 * 不要的第四种格式，把他要的三种一起关掉了。
                 *
                 * 实测（生产镜像 geo-saas:git-d77d9c198588，不含 pandoc，喂技能自带的
                 * examples/synthetic-demo/source-article.md）：退出码 1，输出目录里
                 * .md 6.4KB / .html 13.7KB / .pdf 478KB，中文 PDF 正常。
                 *
                 * 副作用只有一个：verify_outputs() 排在 write_docx() 之后，所以那几项
                 * HTML 样式校验不会跑。三种格式本身照常产出。
                 *
                 * 哪天客户真要 Word，两条路都要单独拍板：
                 *   1) Dockerfile 加 pandoc（Alpine 上 +221 MiB，镜像约 745MB → 970MB）
                 *   2) 在 skill-overlays 放一份改用 python-docx 出 Word 的脚本（不加体积，
                 *      但要自己满足 verify_outputs 的表格宽度/固定布局/边框三项校验）
                 */
            ),
            new SkillDefinition(
                id: 'yao-geo-content-refiner',
                name: '内容优化',
                category: SkillCategory::GeoContentProduction,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 2,
                description: '基于监测数据优化已有内容',
                sceneDescription: '根据 AI 搜索的引用数据，针对性优化文章内容和引用率',
                effectDescription: '获得优化后的文章版本，标注具体修改点和预期提升',
                briefFields: [
                    ['key' => 'article_id', 'label' => '文章 ID', 'type' => 'text', 'required' => true],
                    ['key' => 'optimization_goals', 'label' => '优化目标', 'type' => 'textarea', 'required' => false],
                ],
            ),
            new SkillDefinition(
                id: 'yao-geo-title-optimizer',
                name: '标题优化',
                category: SkillCategory::GeoContentProduction,
                maturity: 'beta',
                requiresWeb: false,
                stageIndex: 2,
                description: '为文章生成 SEO/GEO 优化的标题',
                sceneDescription: '输入文章主题，生成多个候选标题供选择',
                effectDescription: '获得 5-8 个标题候选，附带搜索意图匹配度评分',
                briefFields: [
                    ['key' => 'topic', 'label' => '文章主题', 'type' => 'text', 'required' => true],
                    ['key' => 'target_keyword', 'label' => '目标关键词', 'type' => 'text', 'required' => true],
                ],
            ),
            new SkillDefinition(
                id: 'yao-geo-page-blueprint',
                name: '页面蓝图',
                category: SkillCategory::GeoPageTechnical,
                maturity: 'beta',
                requiresWeb: false,
                stageIndex: 2,
                description: '定义页面的结构化数据和技术规范',
                sceneDescription: '在写文章前，先规划页面结构和技术 SEO 规范',
                effectDescription: '获得 Schema.org 标注方案、H 标签层级、内链策略',
                briefFields: [
                    [
                        'key' => 'page_type',
                        'label' => '页面类型',
                        'type' => 'select',
                        'required' => true,
                        'options' => ['服务页', '产品页', '专题页', '文章页', '榜单页', '对比页', 'FAQ 页', '知识库页', '案例页'],
                    ],
                    ['key' => 'url_pattern', 'label' => 'URL 模式', 'type' => 'text', 'required' => false],
                ],
            ),
            new SkillDefinition(
                id: 'yao-geo-brand-graph',
                name: '品牌知识图谱',
                category: SkillCategory::GeoKnowledgeAssets,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 2,
                description: '构建品牌的结构化知识图谱',
                sceneDescription: '把你的品牌信息整理成 AI 容易理解和引用的结构化数据',
                effectDescription: '获得品牌实体关系图，注入知识库后提升 AI 引用准确率',
                briefFields: [
                    ['key' => 'brand_name', 'label' => '品牌名称', 'type' => 'text', 'required' => true],
                    ['key' => 'brand_info', 'label' => '品牌资料（简介、产品线等）', 'type' => 'textarea', 'required' => true],
                ],
            ),
            new SkillDefinition(
                id: 'yao-geo-knowledge-base-builder',
                name: '知识库构建',
                category: SkillCategory::GeoKnowledgeAssets,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 2,
                description: '创建真实 RAG 知识库并导入品牌资料',
                sceneDescription: '进入企业知识库，创建知识库并导入品牌文档或 URL',
                effectDescription: '资料解析、切片和向量化完成后，后续内容生产可以检索引用',
                briefFields: [],
                actionPath: '/#knowledge',
                actionLabel: '进入企业知识库',
                /*
                 * 2026-09-08 生产走查确认：这张卡承诺的是创建、切片和向量化，
                 * 通用模型执行器却只生成一份“知识库报告”。空来源也能扣点完成，
                 * 实际 knowledge_bases / documents / chunks 一条都没有新增。
                 * 因此和效果监测一样，卡片只作为真实业务能力入口；旧客户端即使
                 * 直接请求通用执行接口，控制器也会在建执行记录和模型调用前拦下。
                 * RenderBridge 的旧适配仍保留，供历史产物修复和离线渲染使用。
                 */
            ),

            // ─── 阶段四：渠道发布 ───
            new SkillDefinition(
                id: 'yao-geo-channel-publishing',
                name: '渠道发布',
                category: SkillCategory::GeoOperations,
                maturity: 'stable',
                requiresWeb: false,
                stageIndex: 3,
                description: '绑定本项目的发布账号或网站接口，发布已审核文章并记录公开链接',
                sceneDescription: '没有官网也可绑定知乎等平台账号，准备发布单、复制内容并回填发布链接；已有网站可连接发布接口',
                effectDescription: '在本项目保留文章版本、目标账号、执行状态和公开链接，发布后继续效果监测',
                briefFields: [],
                actionPath: '/#publishing',
                actionLabel: '绑定渠道并发布',
            ),
            new SkillDefinition(
                id: 'yao-geo-page-audit',
                name: '页面审计',
                category: SkillCategory::GeoPageTechnical,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 3,
                description: '发布前的页面结构合规校验',
                // 文案要说清「会真的抓」。2026-09-06 之前这个技能不发任何请求，
                // 客户以为我们查了页面，其实报告是模型凭对域名的印象写的。
                sceneDescription: '抓取你指定的页面，逐项核对 GEO 规范（Schema、H 标签、内链、robots 等）',
                effectDescription: '基于页面实际内容给出逐项检查结果和修复建议',
                briefFields: [
                    ['key' => 'url', 'label' => '页面 URL', 'type' => 'url', 'required' => true],
                ],
            ),

            // ─── 阶段五：效果监测 ───
            new SkillDefinition(
                id: 'yao-geo-effect-monitor',
                name: '效果监测',
                category: SkillCategory::GeoMeasurement,
                maturity: 'beta',
                requiresWeb: true,
                stageIndex: 4,
                description: '豆包 AI 回答中的品牌曝光与信源监测',
                sceneDescription: '按真实问题发起豆包联网检索，查看回答、搜索词、信源和本次用量',
                effectDescription: '获得问题级监测结果；当前接入平台：豆包',
                briefFields: [],
                actionPath: '/visibility',
                actionLabel: '进入效果监测',
                unavailableReason: config('geo.visibility_panel_enabled', false)
                    ? null
                    : '效果监测当前未开放。',
            ),

            // ─── 阶段六：迭代优化 ───
            // content-refiner 已在阶段三定义，此处不重复

            // ─── 运营操作（贯穿全流程） ───
            new SkillDefinition(
                id: 'yao-geoflow-cli',
                name: 'GEOFlow CLI',
                category: SkillCategory::GeoOperations,
                maturity: 'stable',
                requiresWeb: false,
                stageIndex: -1, // 贯穿全流程
                description: 'GEOFlow 命令行管理工具',
                sceneDescription: '用命令行批量管理文章、分发任务、知识库操作',
                effectDescription: '提高批量操作效率，支持脚本自动化',
            ),
        ];
    }
}
