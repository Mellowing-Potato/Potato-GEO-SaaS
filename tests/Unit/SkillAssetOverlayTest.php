<?php

namespace Tests\Unit;

use App\Services\SkillAgent\SkillAssetLoader;
use Tests\TestCase;

/**
 * 技能资产覆盖层。
 *
 * 背景：yao-geo-skills 是姚的仓库，以只读方式挂进容器。我们对渲染脚本的修复
 * 推不进上游、也写不进挂载点，此前只能以未跟踪文件的形态散落在开发机和生产机上，
 * 既没有版本控制，也让镜像 tag 无法说明脚本是哪一版。
 *
 * 覆盖层把这类文件收回本仓，随镜像走。这里守两件事：覆盖层要真的赢过挂载树，
 * 以及没有覆盖时必须干净回落 —— 回落断了的话，另外 18 个技能的渲染会一起哑掉。
 */
class SkillAssetOverlayTest extends TestCase
{
    private string $tmpRoot;
    private string $overlayRoot;
    private string $skillsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/skill-overlay-' . uniqid();
        $this->overlayRoot = $this->tmpRoot . '/overlay';
        $this->skillsRoot = $this->tmpRoot . '/skills';
        mkdir($this->overlayRoot, 0755, true);
        mkdir($this->skillsRoot, 0755, true);

        $this->setSkillsRepoPath($this->skillsRoot);
        config(['geo.skill_overlay_path' => $this->overlayRoot]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpRoot);
        parent::tearDown();
    }

    /** SkillAssetLoader 直接读 env()，所以三处都要设，不能只 putenv。 */
    private function setSkillsRepoPath(string $path): void
    {
        putenv("SKILLS_REPO_PATH={$path}");
        $_ENV['SKILLS_REPO_PATH'] = $path;
        $_SERVER['SKILLS_REPO_PATH'] = $path;
    }

    private function writeScript(string $root, string $skillId, string $body): void
    {
        $dir = "{$root}/{$skillId}/scripts";
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/render_thing.py", $body);
    }

    private function writeExample(string $root, string $skillId, array $data): void
    {
        $dir = "{$root}/{$skillId}/examples/sample";
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/report_input.json", json_encode($data));
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = "{$path}/{$item}";
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }

    public function test_overlay_script_wins_over_the_mounted_tree(): void
    {
        $this->writeScript($this->skillsRoot, 'demo-skill', '# 挂载树里那份（旧的）');
        $this->writeScript($this->overlayRoot, 'demo-skill', '# 覆盖层那份（修好的）');

        $script = (new SkillAssetLoader())->getRenderScript('demo-skill');

        $this->assertNotNull($script);
        $this->assertStringStartsWith($this->overlayRoot, $script);
        $this->assertStringContainsString('修好的', file_get_contents($script));
    }

    public function test_falls_back_to_the_mounted_tree_when_no_overlay(): void
    {
        // 另外 18 个技能走的就是这条路，断了它们会一起哑掉。
        $this->writeScript($this->skillsRoot, 'upstream-skill', '# 上游那份');

        $script = (new SkillAssetLoader())->getRenderScript('upstream-skill');

        $this->assertNotNull($script);
        $this->assertStringStartsWith($this->skillsRoot, $script);
    }

    public function test_returns_null_when_neither_side_has_a_script(): void
    {
        $this->assertNull((new SkillAssetLoader())->getRenderScript('nowhere-skill'));
    }

    public function test_example_json_also_prefers_the_overlay(): void
    {
        // 示例 JSON 决定喂给 LLM 的输出格式，必须和脚本是配套的一版，
        // 否则脚本按新形状校验、LLM 照旧形状生成。
        $this->writeExample($this->skillsRoot, 'demo-skill', ['shape' => 'old']);
        $this->writeExample($this->overlayRoot, 'demo-skill', ['shape' => 'new']);

        $example = (new SkillAssetLoader())->loadExampleJson('demo-skill');

        $this->assertSame(['shape' => 'new'], $example);
    }

    public function test_overlay_instruction_replaces_legacy_instruction_and_references_together(): void
    {
        $mounted = "{$this->skillsRoot}/demo-skill";
        $overlay = "{$this->overlayRoot}/demo-skill";
        mkdir("{$mounted}/references", 0755, true);
        mkdir($overlay, 0755, true);
        file_put_contents("{$mounted}/SKILL.md", '旧版长报告');
        file_put_contents("{$mounted}/references/legacy.md", '旧版研究表');
        file_put_contents("{$overlay}/SKILL.md", '新版简洁报告');

        $loader = new SkillAssetLoader;
        $this->assertTrue($loader->exists('demo-skill'));
        $this->assertSame('新版简洁报告', $loader->loadSkillMd('demo-skill'));
        $this->assertSame([], $loader->loadReferences('demo-skill'));
        $this->assertSame('新版简洁报告', $loader->loadAll('demo-skill')->skillMd);
    }

    public function test_shipped_panorama_script_carries_the_crash_fix(): void
    {
        // 防回退：这份脚本是为修 meta 形状导致的渲染崩溃才收进本仓的
        // （ValueError: not enough values to unpack）。谁把它换回上游那版，这条会红。
        $script = base_path('skill-overlays/yao-geo-panorama-audit/scripts/render_panorama.py');

        $this->assertFileExists($script);
        $this->assertStringContainsString('_normalize_pairs', file_get_contents($script));
    }

    public function test_shipped_explainer_script_subsets_the_fonts_it_inlines(): void
    {
        // 防回退：下载用的 index.html 内联的字体必须走子集化 —— 完整 NotoSerifCJK 是
        // 26.3 MB，不子集化就会让每份报告、每份快照都多背一份完整字体。谁把 subset_fonts
        // 的接线摘掉（只留函数定义不调用），这条会红。
        $script = base_path('skill-overlays/yao-geo-explainer-builder/scripts/render_yao_geo_explainer_builder.py');

        $this->assertFileExists($script);
        $body = file_get_contents($script);
        $this->assertStringContainsString('def subset_fonts(', $body);
        $this->assertStringContainsString('subset_map = subset_fonts(html_doc, html_dir)', $body);
        $this->assertStringContainsString('html_doc = render_html(data, subset_map)', $body);
    }
}
