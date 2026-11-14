<?php

namespace Tests\Feature;

use App\Console\Commands\GeoHealthCheckCommand;
use App\Services\SkillAgent\SkillAssetLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `geo:health-check` 必须量"应用能加载到什么"，不是"某个路径上有没有文件"。
 *
 * 生产实测（2026-09-21）：这个命令报 `not_ready`，同一台机上 `geo:audit-skill-assets`
 * 报 registered 17 / available 17 / errors 0 —— 因为它把 required files 拼成**上游裸路径**，
 * 而 `yao-geo-panorama-audit` 的渲染脚本本来就只在本仓**覆盖层**（skill-overlays）里
 * （config/geo.php 的注释写明了："它的 scripts/ 在上游历史中从未存在过"）。
 *
 * 这条判据守两件事：
 *  1. 三个必需资产 check 必须 ok（走 loader 的覆盖层感知接口）；
 *  2. render_script 的 evidence 必须报出**解析后**的路径（来自覆盖层），
 *     这样"哪天又改成探裸路径"会当场红。
 */
class GeoHealthCheckOverlayTest extends TestCase
{
    private string $skillsFixture;

    private array $previousSkillsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skillsFixture = storage_path('framework/testing/health-skills-'.uniqid());
        File::ensureDirectoryExists($this->skillsFixture.'/yao-geo-panorama-audit');
        File::put($this->skillsFixture.'/yao-geo-panorama-audit/SKILL.md', '# Test panorama skill');
        $this->previousSkillsPath = [
            getenv('SKILLS_REPO_PATH'),
            $_ENV['SKILLS_REPO_PATH'] ?? null,
            $_SERVER['SKILLS_REPO_PATH'] ?? null,
        ];
        putenv('SKILLS_REPO_PATH='.$this->skillsFixture);
        $_ENV['SKILLS_REPO_PATH'] = $this->skillsFixture;
        $_SERVER['SKILLS_REPO_PATH'] = $this->skillsFixture;
    }

    protected function tearDown(): void
    {
        $this->previousSkillsPath[0] === false
            ? putenv('SKILLS_REPO_PATH')
            : putenv('SKILLS_REPO_PATH='.$this->previousSkillsPath[0]);
        if ($this->previousSkillsPath[1] === null) {
            unset($_ENV['SKILLS_REPO_PATH']);
        } else {
            $_ENV['SKILLS_REPO_PATH'] = $this->previousSkillsPath[1];
        }
        if ($this->previousSkillsPath[2] === null) {
            unset($_SERVER['SKILLS_REPO_PATH']);
        } else {
            $_SERVER['SKILLS_REPO_PATH'] = $this->previousSkillsPath[2];
        }
        File::deleteDirectory($this->skillsFixture);
        parent::tearDown();
    }

    public function test_required_skill_assets_resolve_through_the_overlay_layer(): void
    {
        Artisan::call('geo:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('geo_health_check', $payload['object']);
        $checks = collect($payload['checks'])->keyBy('key');

        $this->assertTrue($checks['skill_md']['ok'], 'SKILL.md 没解析到 —— 体检会对生产误报');
        $this->assertTrue($checks['render_script']['ok'], '渲染脚本没解析到 —— 覆盖层里的那份应当被认到');
        $this->assertTrue($checks['example_json']['ok'], '示例 JSON 没解析到');

        // 解析路径必须指向覆盖层（本仓那份有版本控制的脚本），而不是上游裸路径
        $resolved = (string) ($checks['render_script']['evidence']['resolved_path'] ?? '');
        $this->assertNotSame('', $resolved, '体检没有报出解析后的脚本路径，运维看不出这份脚本来自哪');
        $this->assertStringContainsString(
            (string) config('geo.skill_overlay_path') !== ''
                ? basename((string) config('geo.skill_overlay_path'))
                : 'skill-overlays',
            $resolved,
            '渲染脚本应当来自覆盖层（skill-overlays）',
        );

        // 与运行时同源：loader 解析出来的就是同一个文件
        $this->assertSame(
            app(SkillAssetLoader::class)->getRenderScript(\App\Models\Project::GEO_ANALYSIS_SKILL_ID),
            $resolved,
        );
    }

    /** 命令类自身的一句保证：JSON 输出可机读（运维脚本按它判定）。 */
    public function test_the_command_emits_a_machine_readable_payload(): void
    {
        Artisan::call('geo:health-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertContains($payload['status'], ['ready', 'not_ready']);
        $this->assertInstanceOf(GeoHealthCheckCommand::class, app(GeoHealthCheckCommand::class));
    }
}
