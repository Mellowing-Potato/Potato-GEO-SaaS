<?php

namespace Tests\Unit;

use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\SkillAssets;
use Tests\TestCase;

/**
 * RenderBridge 按脚本各自的调用约定组命令。
 *
 * 2026-08-07 的故障就出在这里：对所有脚本一律传 `--input <json> --output-dir`，
 * 而「排名型文章」和「知识库构建」要的是 `--source <markdown>`，参数名还各不相同
 * （一个 --out-dir、一个 --output-dir，只差一个字）。argparse 在 main() 第一行
 * 就退出，渲染必然失败，客户只拿到一段裸文字。
 *
 * 这几条把映射钉死。改动 MARKDOWN_SOURCE_SCRIPTS 前先跑 `grep add_argument <脚本>`
 * 确认脚本到底吃什么 —— 这个映射错了没有任何运行时报错会提醒你。
 */
class RenderBridgeCommandContractTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/render-contract-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /** @return list<string> */
    private function commandFor(string $skillId, array $jsonData = []): array
    {
        $assets = new SkillAssets(
            skillId: $skillId,
            skillMd: '',
            references: [],
            renderScript: '/fake/render_x.py',
            exampleJsonSchema: null,
            hasAssets: true,
        );

        $method = new \ReflectionMethod(RenderBridgeService::class, 'commandFor');

        return $method->invoke(
            new RenderBridgeService(),
            $assets,
            $jsonData ?: ['title' => '契约测试'],
            $this->tempDir,
            $this->tempDir . '/report_input.json',
            $this->tempDir . '/output',
        );
    }

    public function test_json_scripts_keep_the_default_contract(): void
    {
        $command = $this->commandFor('yao-geo-panorama-audit');

        $this->assertContains('--input', $command);
        $this->assertContains('--output-dir', $command);
        $this->assertNotContains('--source', $command);
        // 9 个脚本走这一支，别把它们一起改坏了。
        $this->assertStringEndsWith('report_input.json', $command[array_search('--input', $command, true) + 1]);
    }

    public function test_knowledge_base_builder_gets_markdown_and_out_dir(): void
    {
        // 注意是 --out-dir，不是 --output-dir。差一个字脚本就不认。
        // 摘要节只在真有 summary_cards 时才出现（适配层不编造内容），所以这里要给。
        $command = $this->commandFor('yao-geo-knowledge-base-builder', [
            'title' => '契约测试',
            'summary_cards' => [['label' => '覆盖平台', 'value' => 'ChatGPT']],
        ]);

        $this->assertContains('--source', $command);
        $this->assertContains('--out-dir', $command);
        $this->assertContains('--quality-report', $command);
        $this->assertNotContains('--input', $command);
        $this->assertNotContains('--output-dir', $command);

        $source = $command[array_search('--source', $command, true) + 1];
        $this->assertStringEndsWith('.md', $source);
        $this->assertFileExists($source);
        // 脚本按字面找「结构化摘要」，标题对不上质检就不过。
        $this->assertStringContainsString('## 结构化摘要', file_get_contents($source));
        $quality = $command[array_search('--quality-report', $command, true) + 1];
        $this->assertStringEndsWith('/output/quality-report.json', str_replace('\\', '/', $quality));
    }

    public function test_ranking_article_gets_markdown_and_output_dir(): void
    {
        $command = $this->commandFor('yao-geo-ranking-article-builder');

        $this->assertContains('--source', $command);
        $this->assertContains('--output-dir', $command);
        $this->assertNotContains('--input', $command);

        $source = $command[array_search('--source', $command, true) + 1];
        $this->assertFileExists($source);
        $this->assertStringContainsString('# 契约测试', file_get_contents($source));
    }

    public function test_markdown_source_file_is_written_next_to_the_json(): void
    {
        // 两份都留在同一个临时目录里，排障时能一起捞出来看。
        $this->commandFor('yao-geo-knowledge-base-builder', [
            'title' => '留痕测试',
            'sections' => [['title' => '一节', 'paragraphs' => ['正文']]],
        ]);

        $this->assertFileExists($this->tempDir . '/report_source.md');
        $this->assertStringContainsString('## 一节', file_get_contents($this->tempDir . '/report_source.md'));
    }
}
