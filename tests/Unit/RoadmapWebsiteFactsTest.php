<?php

namespace Tests\Unit;

use App\Services\DTOs\SkillBrief;
use App\Services\SkillAgent\PageFactExtractor;
use App\Services\SkillAgent\PageObservation;
use App\Services\SkillAgent\SkillAssets;
use App\Services\SkillAgent\SkillPromptBuilder;
use Tests\TestCase;

class RoadmapWebsiteFactsTest extends TestCase
{
    public function test_download_link_is_found_even_after_the_first_fifteen_anchors(): void
    {
        $links = str_repeat('<a href="/article">文章</a>', 20);
        $facts = (new PageFactExtractor)->extract(
            '<html><body>'.$links.'<a href="/download.html">获取客户端下载</a></body></html>',
            'https://brand.test/',
        );

        $this->assertCount(15, $facts['links']['internal_samples']);
        $this->assertSame('/download.html', $facts['links']['download_candidates'][0]['href']);
    }

    public function test_hidden_pending_controls_do_not_pollute_visible_download_status(): void
    {
        $facts = (new PageFactExtractor)->extract(
            '<html><body><p>Windows v0.1.0 现已提供</p>'
            .'<button hidden>安装包准备中</button>'
            .'<button style="display: none">macOS 开发中</button>'
            .'<span aria-hidden="true">旧版本未发布</span>'
            .'</body></html>',
            'https://brand.test/download.html',
        );

        $text = $facts['prose']['first_paint_sample'];
        $this->assertStringContainsString('现已提供', $text);
        $this->assertStringNotContainsString('安装包准备中', $text);
        $this->assertStringNotContainsString('macOS 开发中', $text);
        $this->assertStringNotContainsString('旧版本未发布', $text);
    }

    public function test_hidden_download_link_is_not_selected_as_the_live_entry(): void
    {
        $facts = (new PageFactExtractor)->extract(
            '<html><body><a hidden href="/old-download">下载旧版</a>'
            .'<a href="/download.html">下载客户端</a></body></html>',
            'https://brand.test/',
        );

        $this->assertSame('/download.html', $facts['links']['download_candidates'][0]['href']);
    }

    public function test_roadmap_json_chunk_receives_current_crawl_and_historical_context_with_priority(): void
    {
        $brief = new SkillBrief(
            skillId: 'yao-geo-execution-roadmap', projectId: 1, userId: 1,
            fields: ['brand_url' => 'https://brand.test/'],
            contextFromPreviousStage: ['diagnosis_text' => '旧报告：下载准备中'],
        );
        $observation = PageObservation::success('https://brand.test/', 'https://brand.test/', 200, [
            'related_download_page' => [
                'status' => 'fetched',
                'visible_text' => 'Windows 和 macOS 版本现已提供',
                'fetched_at' => '2026-10-16T10:00:00+08:00',
            ],
        ]);
        $assets = new SkillAssets(
            skillId: 'yao-geo-execution-roadmap', skillMd: '# 路线图', references: [],
            renderScript: null, exampleJsonSchema: null, hasAssets: true,
        );

        $prompt = (new SkillPromptBuilder)->chunkUserPrompt(
            $assets, $brief, '',
            ['key' => 'foundation', 'label' => '概览', 'format' => 'json', 'fields' => ['report', 'source_basis']],
            '', $observation,
        );

        $this->assertStringContainsString('Windows 和 macOS 版本现已提供', $prompt);
        $this->assertStringContainsString('旧报告：下载准备中', $prompt);
        $this->assertLessThan(strpos($prompt, '## 前置阶段输出'), strpos($prompt, '## 页面实测观察'));
        $this->assertStringContainsString('其 last_checked 才能使用对应的 fetched_at', $prompt);
    }
}
