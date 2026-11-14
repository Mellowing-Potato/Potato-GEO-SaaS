<?php

namespace Tests\Unit;

use App\Services\AI\MockAiProvider;
use PHPUnit\Framework\TestCase;

class MockAiProviderTest extends TestCase
{
    public function test_it_routes_fallback_prompt_by_skill_id_heading(): void
    {
        $provider = new MockAiProvider();

        $result = $provider->generate("## Skill ID\nyao-geo-intent-miner\n\n## 任务：AI 搜索意图挖掘分析");
        $data = json_decode((string) $result->content, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('intent_clusters', $data);
        $this->assertArrayHasKey('analysis', $data);
    }

    public function test_it_routes_legacy_skill_colon_prompt(): void
    {
        $provider = new MockAiProvider();

        $result = $provider->generate("## Skill: yao-geo-execution-roadmap\n\n请生成路线图");
        $data = json_decode((string) $result->content, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('phases', $data);
        $this->assertSame('Week 1-2', $data['phases'][0]['phase']);
    }

    public function test_geo_skill_mocks_use_project_context_instead_of_fixed_templates(): void
    {
        $provider = new MockAiProvider();
        $prompt = <<<'PROMPT'
## Skill ID
yao-geo-intent-miner

## 用户输入
- 项目名称: 孟德智达 GEO 测试
- 品牌名称: 孟德智达
- 品牌官网: https://example.test/
- 产品说明: 面向医学科研和少儿编程团队的 AI 工具平台
- 目标市场: 医学科研、少儿编程、AI 工具使用者
- 目标关键词:
  - 医学科研 AI
  - 少儿编程 AI
PROMPT;

        $result = $provider->generate($prompt);
        $content = (string) $result->content;
        $data = json_decode($content, true);

        $this->assertIsArray($data);
        $this->assertSame('孟德智达', $data['project_name']);
        $this->assertStringContainsString('孟德智达', $content);
        $this->assertStringContainsString('医学科研', $content);
        $this->assertStringNotContainsString('search_volume', $content);
        $this->assertStringNotContainsString('预计 8 周内', $content);
    }

    public function test_no_website_mock_outputs_are_project_aware(): void
    {
        $provider = new MockAiProvider();
        $prompt = <<<'PROMPT'
## Skill ID
yao-geo-panorama-audit

## 用户输入
- 项目名称: 米娜咖啡
- 品牌名称: 米娜咖啡
- 品牌官网: (未提供)
- 产品说明: 主营是女装，实体店咖啡是辅助女装服务
- 目标市场: 咖啡市场 女装 集合店铺
- 目标关键词: - ai搜索优化 豆包推荐 米娜咖啡 米娜女装
PROMPT;

        $panorama = $provider->generate($prompt);
        $panoramaData = json_decode((string) $panorama->content, true);

        $this->assertIsArray($panoramaData);
        $this->assertSame(20, $panoramaData['dimensions'][3]['score']);
        $this->assertStringContainsString('未提供真实官网', json_encode($panoramaData, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('结构化数据", "score": 82', json_encode($panoramaData, JSON_UNESCAPED_UNICODE));

        $roadmap = $provider->generate(str_replace('yao-geo-panorama-audit', 'yao-geo-execution-roadmap', $prompt));
        $roadmapContent = (string) $roadmap->content;
        $roadmapData = json_decode($roadmapContent, true);

        $this->assertIsArray($roadmapData);
        $this->assertStringContainsString('品牌入口/未来官网首页', implode(' ', $roadmapData['phases'][0]['tasks']));
        $this->assertStringNotContainsString('(未提供) 首页', $roadmapContent);

        $explainer = $provider->generate(str_replace('yao-geo-panorama-audit', 'yao-geo-explainer-builder', $prompt));
        $explainerContent = (string) $explainer->content;

        $this->assertStringContainsString('主营是女装', $explainerContent);
        $this->assertStringContainsString('品牌入口/未来官网首页', $explainerContent);
        $this->assertStringContainsString('豆包推荐', $explainerContent);
        $this->assertStringNotContainsString('ai搜索优化', $explainerContent);
        $this->assertStringNotContainsString('传统 SEO 工具', $explainerContent);
        $this->assertStringNotContainsString('适合希望提升 AI 搜索可见性', $explainerContent);
        $this->assertStringNotContainsString('采购和比较问题', $explainerContent);
        $this->assertStringNotContainsString('官网页面、案例或来源', $explainerContent);

        $intent = $provider->generate(str_replace('yao-geo-panorama-audit', 'yao-geo-intent-miner', $prompt));
        $this->assertStringContainsString('豆包推荐', (string) $intent->content);
        $this->assertStringNotContainsString('ai搜索优化', (string) $intent->content);
    }
}
