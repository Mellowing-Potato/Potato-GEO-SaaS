<?php

namespace App\Services\AI;

use App\Models\Tenant;
use App\Services\Contracts\AiProvider as AiProviderContract;
use App\Services\DTOs\AiProviderCallResult;

/**
 * Mock AI Provider — 仅用于测试
 *
 * 只在测试环境或显式测试开关下启用。
 * 根据 prompt 内容中的 Skill ID 生成逼真的结构化输出，
 * 模拟真实 LLM 的延迟和 token 消耗。
 */
class MockAiProvider implements AiProviderContract
{
    /**
     * 按 Skill 类别生成逼真假数据
     */
    public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
    {
        // B3：带图调用（视觉誊抄）走固定转录文本，让 VisionPageExtractor 的
        // 非网络路径单测能跑通。截断等异常分支由测试文件里的专用 fake 承担，
        // 这里只保证「正常路径」可用。
        $images = $options['images'] ?? [];
        if (is_array($images) && $images !== []) {
            $content = "# 第 1 页\n\n| 项目 | 金额 |\n|------|------|\n| 合计 | ¥2,200 |\n\n联系电话：138-0000-0000\n\n[图标] [二维码]\n";
            $promptTokens = (int) (mb_strlen($prompt) / 3.5) + count($images) * 800;
            $completionTokens = (int) (mb_strlen($content) / 3.5);

            return new AiProviderCallResult(
                success: true,
                provider: 'mock',
                model: $options['model'] ?? 'mock-geo-v1',
                content: $content,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                totalTokens: $promptTokens + $completionTokens,
                durationSeconds: 0.01,
                finishReason: 'stop',
            );
        }

        // 模拟网络延迟 0.3-1.5s
        $delayMs = rand(300, 1500);
        usleep($delayMs * 1000);

        $content = $this->generateMockContent($prompt);
        $promptTokens = (int) (mb_strlen($prompt) / 3.5);
        $completionTokens = (int) (mb_strlen($content) / 3.5);

        return new AiProviderCallResult(
            success: true,
            provider: 'mock',
            model: 'mock-geo-v1',
            content: $content,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $promptTokens + $completionTokens,
            durationSeconds: round($delayMs / 1000, 2),
        );
    }

    public function generateStructured(string $prompt, string $schema, ?string $system = null, array $options = []): AiProviderCallResult
    {
        $result = $this->generate($prompt, $system);

        if ($result->success) {
            // 从生成的内容中提取 JSON
            $decoded = json_decode($result->content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // 如果内容不是 JSON，包装成结构化格式
                $decoded = ['analysis' => $result->content, 'confidence' => 0.85];
            }

            return new AiProviderCallResult(
                success: true,
                provider: $result->provider,
                model: $result->model,
                content: $result->content,
                structuredOutput: $decoded,
                promptTokens: $result->promptTokens,
                completionTokens: $result->completionTokens,
                totalTokens: $result->totalTokens,
                durationSeconds: $result->durationSeconds,
            );
        }

        return $result;
    }

    public function availableProviders(Tenant $tenant): array
    {
        return [
            'mock' => [
                'name' => 'Mock Provider (开发模式)',
                'models' => ['mock-geo-v1'],
            ],
        ];
    }

    public function pickBestModel(Tenant $tenant, ?string $preferred = null): array
    {
        return ['provider' => 'mock', 'model' => 'mock-geo-v1'];
    }

    public function getTenantApiKey(Tenant $tenant, string $provider): ?string
    {
        return null;
    }

    public function embed(string $text, string $model = 'embedding', array $options = []): array
    {
        // Generate a deterministic pseudo-random embedding based on input text
        $hash = md5($text);
        $embedding = [];
        for ($i = 0; $i < 1024; $i++) {
            $seed = hexdec(substr($hash, $i % 32, 2));
            $embedding[] = ($seed / 255.0) * 2 - 1; // range [-1, 1]
        }

        // Normalize to unit vector (approximate)
        $magnitude = sqrt(array_sum(array_map(fn($v) => $v * $v, $embedding)));
        if ($magnitude > 0) {
            $embedding = array_map(fn($v) => $v / $magnitude, $embedding);
        }

        return [
            'embedding' => $embedding,
            'tokens' => (int) (mb_strlen($text) / 3.5),
        ];
    }

    public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array
    {
        $embeddings = [];
        $totalTokens = 0;

        foreach ($texts as $text) {
            $result = $this->embed($text, $model);
            $embeddings[] = $result['embedding'];
            $totalTokens += $result['tokens'];
        }

        return [
            'embeddings' => $embeddings,
            'tokens' => $totalTokens,
            'error' => null,
        ];
    }

    /**
     * 根据 prompt 中的 Skill 标识生成对应的逼真分析内容
     */
    private function generateMockContent(string $prompt): string
    {
        $skillId = $this->extractSkillId($prompt);

        return match (true) {
            str_contains($skillId, 'intent-miner')       => $this->mockIntentMiner($prompt),
            str_contains($skillId, 'panorama-audit')     => $this->mockPanoramaAudit($prompt),
            str_contains($skillId, 'execution-roadmap')  => $this->mockExecutionRoadmap($prompt),
            str_contains($skillId, 'tracking')           => $this->mockTracking($prompt),
            str_contains($skillId, 'explainer-builder')  => $this->mockExplainerBuilder($prompt),
            str_contains($skillId, 'comparison-builder') => $this->mockComparisonBuilder($prompt),
            str_contains($skillId, 'ranking-article')    => $this->mockRankingArticle($prompt),
            str_contains($skillId, 'content-refiner')    => $this->mockContentRefiner($prompt),
            str_contains($skillId, 'title-optimizer')    => $this->mockTitleOptimizer($prompt),
            str_contains($skillId, 'page-blueprint')     => $this->mockPageBlueprint($prompt),
            str_contains($skillId, 'page-audit')         => $this->mockPageAudit($prompt),
            str_contains($skillId, 'effect-monitor')     => $this->mockEffectMonitor($prompt),
            str_contains($skillId, 'brand-graph')        => $this->mockBrandGraph($prompt),
            str_contains($skillId, 'knowledge-base')     => $this->mockKnowledgeBase($prompt),
            str_contains($skillId, 'geoflow')            => $this->mockGeoflow($prompt),
            default                                       => $this->mockGenericAnalysis($prompt),
        };
    }

    private function extractSkillId(string $prompt): string
    {
        $patterns = [
            '/##\s*Skill(?:\s*ID)?\s*[:：]\s*([A-Za-z0-9_.:-]+)/iu',
            '/##\s*Skill\s*ID\s*\R\s*([A-Za-z0-9_.:-]+)/iu',
            '/Skill\s*ID\s*[:：]\s*([A-Za-z0-9_.:-]+)/iu',
            '/skill_id["\']?\s*[:=]\s*["\']?([A-Za-z0-9_.:-]+)/i',
            '/\b(yao-(?:geo|geoflow)[A-Za-z0-9_.:-]*)\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $prompt, $matches)) {
                return strtolower(trim($matches[1]));
            }
        }

        return match (true) {
            str_contains($prompt, 'AI 搜索意图挖掘') => 'yao-geo-intent-miner',
            str_contains($prompt, '全景诊断') => 'yao-geo-panorama-audit',
            str_contains($prompt, '执行路线图') => 'yao-geo-execution-roadmap',
            str_contains($prompt, 'GEO 追踪方案') => 'yao-geo-tracking',
            str_contains($prompt, '科普解释型文章') => 'yao-geo-explainer-builder',
            str_contains($prompt, '品牌对比文章') => 'yao-geo-comparison-builder',
            str_contains($prompt, '榜单排名文章') => 'yao-geo-ranking-article-builder',
            str_contains($prompt, '标题优化器') => 'yao-geo-title-optimizer',
            str_contains($prompt, '翻新优化已有文章') => 'yao-geo-content-refiner',
            str_contains($prompt, '页面 GEO 合规性审计') => 'yao-geo-page-audit',
            str_contains($prompt, '效果监测报告') => 'yao-geo-effect-monitor',
            default => '',
        };
    }

    private function promptContext(string $prompt): array
    {
        $projectName = $this->extractPromptField($prompt, ['项目名称', 'project_name']);
        $brandName = $this->extractPromptField($prompt, ['品牌名称', 'brand_name', '品牌'])
            ?: $projectName
            ?: '当前品牌';
        $brandUrl = $this->extractPromptField($prompt, ['品牌官网', 'brand_url', '官网']);
        $productDescription = $this->extractPromptField($prompt, ['产品说明', 'product_description']);
        $targetMarket = $this->extractPromptField($prompt, ['目标市场', 'target_market', '业务领域', 'domain']);
        $goals = $this->extractPromptField($prompt, ['目标', 'goals']);

        $keywords = $this->businessKeywords(array_values(array_unique(array_filter(array_merge(
            $this->extractPromptList($prompt, ['目标关键词', 'target_keywords']),
            $this->extractPromptList($prompt, ['种子关键词', 'seed_keywords']),
            $this->splitTerms($this->extractPromptField($prompt, ['目标关键词', 'target_keyword'])),
        )))), [
            'brand_name' => $brandName,
            'product_description' => $productDescription,
            'target_market' => $targetMarket,
            'goals' => $goals,
        ]);

        if ($keywords === []) {
            $keywords = $this->defaultKeywords([
                'brand_name' => $brandName,
                'product_description' => $productDescription,
                'target_market' => $targetMarket,
                'goals' => $goals,
            ]);
        }

        return [
            'project_name' => $projectName ?: $brandName,
            'brand_name' => $brandName,
            'brand_url' => $brandUrl,
            'product_description' => $productDescription,
            'target_market' => $targetMarket,
            'topic' => $this->extractPromptField($prompt, ['主题', 'topic']),
            'target_keyword' => $keywords[0] ?? '',
            'keywords' => array_slice($keywords, 0, 8),
            'competitors' => array_slice($this->extractPromptList($prompt, ['主要竞品', 'competitors']), 0, 6),
            'goals' => $goals,
        ];
    }

    private function hasUsableBrandUrl(string $brandUrl): bool
    {
        $brandUrl = trim($brandUrl);
        if ($brandUrl === '' || $brandUrl === '(未提供)' || $brandUrl === '未提供真实官网') {
            return false;
        }

        $host = parse_url($brandUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return ! preg_match('/(^|\.)example\.(com|net|org|test)$|(^|\.)example$/i', $host);
    }

    private function brandEntryLabel(array $context): string
    {
        $url = $context['brand_url'] ?? '';

        return $this->hasUsableBrandUrl($url) ? $url : '品牌入口/未来官网首页';
    }

    private function extractPromptField(string $prompt, array $labels): string
    {
        foreach ($labels as $label) {
            $quoted = preg_quote($label, '/');
            if (preg_match('/^\s*[-*]?\s*(?:\*\*)?' . $quoted . '(?:\*\*)?\s*[:：]\s*(.+)$/um', $prompt, $matches)) {
                $value = trim($matches[1]);
                if ($value !== '') {
                    return $this->cleanPromptText($value);
                }
            }
        }

        return '';
    }

    private function extractPromptList(string $prompt, array $labels): array
    {
        foreach ($labels as $label) {
            $inline = $this->extractPromptField($prompt, [$label]);
            if ($inline !== '') {
                return $this->splitTerms($inline);
            }

            $quoted = preg_quote($label, '/');
            if (preg_match('/^\s*[-*]?\s*(?:\*\*)?' . $quoted . '(?:\*\*)?\s*[:：]\s*\R((?:\s+[-*]\s+.+\R?)+)/um', $prompt, $matches)) {
                $lines = preg_split('/\R/', trim($matches[1])) ?: [];
                return array_values(array_filter(array_map(
                    fn (string $line): string => $this->cleanPromptText(preg_replace('/^\s*[-*]\s+/', '', $line) ?? $line),
                    $lines,
                )));
            }
        }

        return [];
    }

    private function splitTerms(string $value): array
    {
        $value = $this->cleanPromptText($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,，、;；]+/u', $value) ?: [];
        $parts = array_values(array_filter(array_map(
            fn (string $item): string => $this->cleanPromptText($item),
            $parts,
        )));

        return $parts !== [] ? array_values(array_unique($parts)) : [$value];
    }

    private function businessKeywords(array $keywords, array $context): array
    {
        if (! $this->isRetailContext($context)) {
            return $keywords;
        }

        $clean = [];
        foreach ($keywords as $keyword) {
            foreach ($this->retailKeywordParts($keyword) as $part) {
                $clean[] = $part;
            }
        }

        return array_values(array_unique(array_merge($clean, $this->defaultKeywords($context))));
    }

    private function retailKeywordParts(string $keyword): array
    {
        $keyword = $this->stripToolKeyword($keyword);
        if ($keyword === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $part): string => $this->cleanPromptText($part),
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

    private function defaultKeywords(array $context): array
    {
        $brand = $context['brand_name'] ?? '当前品牌';

        if ($this->isRetailContext($context)) {
            return ['女装穿搭', '门店体验', '本地咖啡', "{$brand} 推荐", '用户评价'];
        }

        return [
            "{$brand} 是什么",
            "{$brand} GEO 优化",
            "{$brand} AI 搜索可见性",
        ];
    }

    private function isRetailContext(array $context): bool
    {
        $haystack = mb_strtolower(implode(' ', [
            $context['product_description'] ?? '',
            $context['target_market'] ?? '',
            $context['goals'] ?? '',
        ]));

        foreach (['女装', '服装', '穿搭', '咖啡', '门店', '店铺', '本地', '零售', '集合店', '到店', '小红书', '大众点评', '抖音'] as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function cleanPromptText(string $value): string
    {
        $value = preg_replace('/[*`#]+/', '', $value) ?? $value;
        $value = preg_replace('/^\s*(?:[-•]+|\d+[.)、])\s*/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function mockIntentMiner(string $prompt): string
    {
        $context = $this->promptContext($prompt);
        $brand = $context['brand_name'];
        $market = $context['target_market'] ?: '目标用户';
        $keywords = $context['keywords'];
        $entry = $this->brandEntryLabel($context);

        return json_encode([
            'project_name' => $brand,
            'analysis' => "{$brand} 的意图挖掘应围绕“品牌是什么、适合谁、为什么可信、和同类品牌有什么不同、如何被 AI 正确推荐”展开。当前目标市场是{$market}，建议先覆盖可直接回答的品牌定义和用户决策问题。",
            'intent_clusters' => [
                ['keyword' => "{$brand} 是什么", 'intent' => '品牌认知', 'priority' => 'P0', 'evidence' => "{$entry} 需要一句可被 AI 直接引用的品牌定义"],
                ['keyword' => $keywords[0] ?? "{$brand} GEO 优化", 'intent' => '问题解释', 'priority' => 'P0', 'evidence' => '目标关键词已在项目资料中出现，需要拆成问答页和 FAQ'],
                ['keyword' => "{$brand} 适合哪些用户或场景", 'intent' => '用户判断', 'priority' => 'P0', 'evidence' => "目标市场：{$market}"],
                ['keyword' => "{$brand} 与竞品区别", 'intent' => '对比评估', 'priority' => 'P1', 'evidence' => '竞品对比页和边界说明能降低被竞品替代的风险'],
                ['keyword' => "{$brand} 如何验证 GEO 效果", 'intent' => '效果验证', 'priority' => 'P1', 'evidence' => '需要建立 AI 引用、品牌提及、来源覆盖和页面审计指标'],
            ],
            'top_opportunities' => [
                "为 {$entry} 补一段 80-120 字品牌定义，并同步到 FAQ。",
                "把“{$keywords[0]}”拆成 5 个自然语言问题，形成可引用问答块。",
                "围绕目标用户补充适用场景、产品/服务边界、信任证据和不适用边界。",
            ],
            'recommendations' => [
                '先做品牌定义页和 FAQ，再做竞品对比与案例证据。',
                '每个关键词不要只写概念解释，要补“适合谁、怎么做、如何验证”。',
            ],
            'next_steps' => ['生成执行路线图', '撰写解释型文章', '做页面审计'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockPanoramaAudit(string $prompt): string
    {
        $context = $this->promptContext($prompt);
        $brand = $context['brand_name'];
        $hasUrl = $this->hasUsableBrandUrl($context['brand_url']);

        if (! $hasUrl) {
            return json_encode([
                'overall_score' => 46,
                'dimensions' => [
                    ['name' => 'AI 引用率', 'score' => 38, 'trend' => '待建立', 'status' => 'needs_improvement'],
                    ['name' => '内容覆盖度', 'score' => 48, 'trend' => '待补充', 'status' => 'moderate'],
                    ['name' => '品牌提及频次', 'score' => 35, 'trend' => '待监测', 'status' => 'needs_improvement'],
                    ['name' => '结构化数据', 'score' => 20, 'trend' => '未接入官网', 'status' => 'weak'],
                    ['name' => '内容时效性', 'score' => 40, 'trend' => '待发布', 'status' => 'needs_improvement'],
                ],
                'critical_issues' => [
                    "{$brand} 尚未提供真实官网或可审计品牌入口，页面结构化数据和可抓取性不能判定。",
                    "{$brand} 的品牌定义、产品边界、用户场景和信任证据需要先沉淀成可引用内容。",
                ],
                'quick_wins' => [
                    '先建立品牌入口或未来官网首页的 80-120 字品牌定义、FAQ 和证据区。',
                    '围绕核心关键词生成解释型文章，并标注来源、更新时间和适用场景。',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return json_encode([
            'overall_score' => 62,
            'dimensions' => [
                ['name' => 'AI 引用率', 'score' => 45, 'trend' => '+8%', 'status' => 'needs_improvement'],
                ['name' => '内容覆盖度', 'score' => 71, 'trend' => '+12%', 'status' => 'good'],
                ['name' => '品牌提及频次', 'score' => 58, 'trend' => '+3%', 'status' => 'moderate'],
                ['name' => '结构化数据', 'score' => 82, 'trend' => '+5%', 'status' => 'excellent'],
                ['name' => '内容时效性', 'score' => 55, 'trend' => '-2%', 'status' => 'needs_improvement'],
            ],
            'critical_issues' => [
                'AI 搜索引擎中品牌引用率偏低，需增加结构化 FAQ 内容',
                '3 篇核心文章超过 6 个月未更新，时效性下降',
            ],
            'quick_wins' => [
                '为首页添加 FAQ Schema，预计提升 AI 引用率 15%',
                '更新「AI 搜索优化指南」文章，补充 2026 年最新数据',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockExecutionRoadmap(string $prompt): string
    {
        $context = $this->promptContext($prompt);
        $brand = $context['brand_name'];
        $entry = $this->brandEntryLabel($context);

        return json_encode([
            'project_name' => $brand,
            'summary' => "{$brand} 的路线图应先把全景诊断中的品牌定义、FAQ、结构化数据和证据来源落到页面，再进入文章生产、页面审计和效果监测。",
            'phases' => [
                ['phase' => 'Week 1-2', 'focus' => '补齐品牌定义与核心 FAQ', 'priority' => 'P0', 'tasks' => ["在 {$entry} 加入 {$brand} 的一句话定义", '补 5-8 个可见 FAQ', '整理目标用户和不适用边界'], 'acceptance' => '页面能直接回答“是什么、适合谁、为什么可信、怎么验证”'],
                ['phase' => 'Week 3-4', 'focus' => '生成解释型文章和竞品对比资产', 'priority' => 'P0', 'tasks' => ['撰写解释型文章', '生成竞品对比页草稿', '把项目关键词转成问答标题'], 'acceptance' => '内容资产库中至少有一篇可编辑交付稿'],
                ['phase' => 'Week 5-6', 'focus' => '页面审计与结构化数据修复', 'priority' => 'P1', 'tasks' => ['检查 H1/H2、内链、FAQ Schema', '为案例和产品能力补来源台账', '修正页面摘要和元信息'], 'acceptance' => '页面审计问题形成修复清单'],
                ['phase' => 'Week 7-8', 'focus' => '效果监测与二次迭代', 'priority' => 'P1', 'tasks' => ['记录 AI 答案中的品牌提及', '追踪被引用页面和来源', '按反馈更新 FAQ 和文章'], 'acceptance' => '形成一次监测记录和下一轮优化清单'],
            ],
            'recommendations' => [
                '不要把路线图当总建议，先选一个 P0 动作落地。',
                '所有效果提升都以发布后的监测记录为准，不在测试数据中承诺固定涨幅。',
            ],
            'next_steps' => ['先写解释型文章', '再做页面审计', '最后配置效果监测'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockTracking(string $prompt): string
    {
        return json_encode([
            'ai_engines_tracked' => ['ChatGPT Search', 'Perplexity', 'Google AI Overviews', 'Bing Copilot'],
            'brand_mentions' => [
                ['engine' => 'ChatGPT Search', 'mentions' => 23, 'sentiment' => 'positive', 'change_30d' => '+5'],
                ['engine' => 'Perplexity', 'mentions' => 18, 'sentiment' => 'neutral', 'change_30d' => '+3'],
                ['engine' => 'Google AI Overviews', 'mentions' => 31, 'sentiment' => 'positive', 'change_30d' => '+12'],
                ['engine' => 'Bing Copilot', 'mentions' => 8, 'sentiment' => 'neutral', 'change_30d' => '-1'],
            ],
            'citation_rate' => 34.7,
            'citation_rate_trend' => '+8.2%',
            'top_cited_pages' => [
                ['url' => '/blog/ai-search-optimization-guide', 'citations' => 15],
                ['url' => '/blog/chatgpt-vs-claude', 'citations' => 9],
                ['url' => '/tools/ai-writing-tools-ranking', 'citations' => 7],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockExplainerBuilder(string $prompt): string
    {
        $context = $this->promptContext($prompt);
        $brand = $context['brand_name'];
        $topic = $context['topic'] ?: ($context['target_keyword'] ?: "{$brand} GEO 优化");
        $entry = $this->brandEntryLabel($context);
        $market = $context['target_market'] ?: '目标用户';
        $description = $context['product_description'] ?: "{$brand} 需要让公开内容更容易被 AI 搜索引擎理解、引用和复述。";

        return "# {$brand} GEO 内容优化草稿：围绕“{$topic}”建立可被 AI 引用的内容\n\n"
            . "> 本文基于项目资料生成，用于内容草稿和审稿，不替代真实页面抓取与人工核验。\n\n"
            . "## {$brand} 是什么？\n\n"
            . "{$description}\n\n"
            . "面向 AI 搜索场景时，{$brand} 的公开内容需要先回答四个问题：它是什么、面向谁、为什么可信、用户搜索相关需求时为什么应该推荐它。\n\n"
            . "## 目标用户会怎么问？\n\n"
            . "- {$brand} 是什么，主营产品或服务是什么？\n"
            . "- {$brand} 适合哪些用户、消费场景或到店需求？\n"
            . "- 用户搜索“{$topic}”时，为什么应该推荐 {$brand}？\n"
            . "- {$brand} 有哪些门店、产品、评价、案例或图片证据可以支撑？\n\n"
            . "## 公开内容应该补哪些信息？\n\n"
            . "1. 在 {$entry} 增加 80-120 字品牌定义，使用短句说明产品类别、核心能力和服务对象。\n"
            . "2. 增加 FAQ 模块，覆盖“是什么、适合谁、怎么验证、和竞品区别、需要哪些准备”。\n"
            . "3. 增加案例、评价或证据区，记录场景、问题、解决方式、效果和来源状态。\n"
            . "4. 用 H2/H3、表格、列表和 FAQ Schema 组织内容，让 AI 更容易抽取事实。\n\n"
            . "## 可直接发布的 FAQ 草稿\n\n"
            . "### {$brand} 适合哪些用户？\n"
            . "{$brand} 适合正在寻找{$market}相关产品或服务的用户。建议用真实产品、门店、服务流程、价格区间、用户评价或案例来说明适用场景。\n\n"
            . "### 为什么只写普通介绍还不够？\n"
            . "AI 搜索更依赖结构清晰、来源明确、能直接回答问题的内容。只有口号式介绍，容易被模型忽略或被竞品内容替代。\n\n"
            . "### 怎么判断 GEO 优化是否有效？\n"
            . "建议记录目标问题、AI 回答是否提及品牌、引用了哪些页面、回答是否准确，以及页面审计问题是否减少。\n\n"
            . "## 审稿清单\n\n"
            . "- [ ] 品牌定义是否一句话说清楚\n"
            . "- [ ] FAQ 是否覆盖适用、比较和行动问题\n"
            . "- [ ] 关键断言是否有公开入口、案例/评价或来源支撑\n"
            . "- [ ] 标题层级、内链和 Schema 是否便于抓取\n\n"
            . "## 下一步\n\n"
            . "先把本文中的品牌定义和 FAQ 拆到{$entry}，再执行页面审计；发布后用效果监测记录 AI 答案是否能准确引用 {$brand}。";
    }

    private function mockComparisonBuilder(string $prompt): string
    {
        return "# AI 搜索引擎横向对比\n\n## 概览\n\n| 维度 | ChatGPT Search | Perplexity | Google AI Overviews | Bing Copilot |\n|------|---------------|------------|---------------------|-------------|\n| 搜索速度 | ★★★★☆ | ★★★★★ | ★★★★☆ | ★★★☆☆ |\n| 答案准确性 | ★★★★☆ | ★★★★☆ | ★★★★★ | ★★★☆☆ |\n| 引用来源 | 有 | 详细 | 部分 | 有 |\n| 多轮对话 | 优秀 | 优秀 | 有限 | 一般 |\n| 免费额度 | 40次/3h | 5次/天 | 无限 | 无限 |\n\n## 详细分析\n\n### ChatGPT Search\n适合需要深度分析的场景，引用来源清晰...\n\n### Perplexity\n实时搜索能力最强，适合追踪最新趋势...\n\n### Google AI Overviews\n覆盖面最广，品牌曝光价值最大...\n\n## 结论\n\n根据使用场景选择合适的 AI 搜索引擎组合，是 GEO 策略的关键。";
    }

    private function mockRankingArticle(string $prompt): string
    {
        return "# 2026 年 AI 写作工具排行榜\n\n## 评判标准\n准确性(30%) | 易用性(25%) | 性价比(25%) | 中文支持(20%)\n\n## Top 5\n\n### 1. ChatGPT — 综合评分 92\n最强通用 AI 写作助手，GPT-4o 在逻辑推理和长文本方面表现卓越...\n\n### 2. Claude — 综合评分 89\n安全性最高，长上下文窗口(200K)适合处理大型文档...\n\n### 3. 通义千问 — 综合评分 85\n中文理解最强，免费额度慷慨，企业级 API 价格亲民...\n\n### 4. DeepSeek — 综合评分 82\n性价比之王，推理能力突出，开源生态活跃...\n\n### 5. 文心一言 — 综合评分 78\n百度生态整合好，中文创作自然流畅...\n\n## 选型建议\n根据团队规模和内容需求选择，中小团队推荐 ChatGPT + 通义千问组合。";
    }

    private function mockContentRefiner(string $prompt): string
    {
        return json_encode([
            'original_quality_score' => 72,
            'refined_quality_score' => 89,
            'improvements' => [
                ['type' => 'structure', 'description' => '添加了 3 个 H2 子标题，改善内容层次', 'impact' => 'high'],
                ['type' => 'faq', 'description' => '新增 5 个 FAQ 问答对，匹配 AI 搜索意图', 'impact' => 'high'],
                ['type' => 'data', 'description' => '补充了 4 个数据引用点', 'impact' => 'medium'],
                ['type' => 'meta', 'description' => '优化了摘要描述，增加直接回答性', 'impact' => 'medium'],
            ],
            'suggested_title' => '2026 完整 AI 搜索优化指南：5 步提升品牌 AI 可见性',
            'word_count_change' => '+420',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockTitleOptimizer(string $prompt): string
    {
        return json_encode([
            'candidates' => [
                ['title' => '2026 AI 搜索优化完全指南：5 个策略提升品牌可见性', 'score' => 94, 'reason' => '年份+数字+明确价值'],
                ['title' => 'GEO 实战手册：让 AI 主动推荐你的品牌', 'score' => 88, 'reason' => '行动导向+结果承诺'],
                ['title' => '从 SEO 到 GEO：AI 时代的内容优化转型', 'score' => 85, 'reason' => '对比框架+趋势感'],
                ['title' => '为什么你的内容不被 AI 引用？GEO 优化指南', 'score' => 82, 'reason' => '痛点切入+问题导向'],
                ['title' => 'AI 搜索引擎优化：2026 品牌方必读指南', 'score' => 79, 'reason' => '目标受众明确+紧迫感'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockPageBlueprint(string $prompt): string
    {
        return json_encode([
            'page_type' => 'pillar_content',
            'recommended_sections' => [
                ['section' => 'Hero', 'schema' => 'WebPage', 'elements' => ['h1 主标题', '摘要段落', 'CTA 按钮']],
                ['section' => 'Introduction', 'schema' => 'Article', 'elements' => ['问题引入', '数据佐证', '文章结构预告']],
                ['section' => 'Core Content', 'schema' => 'HowTo', 'elements' => ['H2 步骤标题', '有序列表', '配图说明']],
                ['section' => 'FAQ', 'schema' => 'FAQPage', 'elements' => ['5-8 个常见问题', '简洁直接的回答']],
                ['section' => 'Conclusion', 'schema' => 'Article', 'elements' => ['要点总结', '行动建议', '相关链接']],
            ],
            'schema_recommendations' => ['Article', 'FAQPage', 'HowTo', 'BreadcrumbList'],
            'estimated_word_count' => '3500-5000',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockPageAudit(string $prompt): string
    {
        return json_encode([
            'page_score' => 68,
            'issues' => [
                ['severity' => 'high', 'issue' => '缺少 FAQ Schema，AI 引擎无法提取问答结构', 'fix' => '添加 FAQPage JSON-LD'],
                ['severity' => 'medium', 'issue' => 'H2 标题缺少直接回答性', 'fix' => '改为问答格式标题'],
                ['severity' => 'medium', 'issue' => '图片缺少描述性 alt 文本', 'fix' => '为所有图片添加描述性 alt'],
                ['severity' => 'low', 'issue' => '内部链接数量偏少', 'fix' => '增加 3-5 个相关内容链接'],
            ],
            'ai_readability_score' => 72,
            'recommendation' => '修复高优先级 Schema 问题后，预计 AI 引用概率提升 20%',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockEffectMonitor(string $prompt): string
    {
        return json_encode([
            'monitoring_period' => '2026-06-01 to 2026-06-25',
            'ai_citation_rate' => ['current' => 34.7, 'previous' => 26.5, 'change' => '+8.2%'],
            'brand_mentions' => ['current' => 80, 'previous' => 57, 'change' => '+40%'],
            'top_performing_content' => [
                ['title' => 'AI 搜索优化指南', 'citations' => 15, 'impressions' => 2340],
                ['title' => 'ChatGPT vs Claude 对比', 'citations' => 9, 'impressions' => 1870],
                ['title' => 'AI 写作工具排行', 'citations' => 7, 'impressions' => 1520],
            ],
            'recommendations' => [
                '持续更新「AI 搜索优化指南」，保持引用优势',
                '新增 2-3 篇深度对比文章，覆盖更多 AI 工具',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockBrandGraph(string $prompt): string
    {
        return json_encode([
            'brand_entities' => [
                ['name' => '品牌名', 'type' => 'Organization', 'recognition_rate' => 0.45],
                ['name' => '核心产品', 'type' => 'Product', 'recognition_rate' => 0.38],
            ],
            'knowledge_gaps' => ['缺少品牌来源和关键人物信息', '产品线关联关系不清晰'],
            'suggested_entities' => ['品牌负责人/主理人', '核心差异化优势', '案例/评价证据'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockKnowledgeBase(string $prompt): string
    {
        return json_encode([
            'documents_indexed' => 24,
            'chunks_created' => 387,
            'embedding_model' => 'embedding',
            'quality_score' => 82,
            'recommendations' => ['增加 FAQ 类文档', '补充产品对比数据'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockGeoflow(string $prompt): string
    {
        return json_encode([
            'status' => 'success',
            'pipeline_stages' => ['research', 'strategy', 'production', 'distribution', 'monitoring'],
            'execution_time' => '12.4s',
            'artifacts_generated' => 3,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function mockGenericAnalysis(string $prompt): string
    {
        return json_encode([
            'analysis' => '基于输入数据的综合分析结果',
            'key_findings' => [
                'AI 搜索优化覆盖率有较大提升空间',
                '竞品在结构化数据方面表现更好',
                '建议优先完善 FAQ Schema 和 HowTo 内容',
            ],
            'confidence' => 0.82,
            'next_steps' => [
                '执行全景诊断获取详细评分',
                '制定内容生产计划',
                '设置效果监测基准',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
