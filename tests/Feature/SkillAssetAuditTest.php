<?php

namespace Tests\Feature;

use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\SkillDefinition;
use App\Services\SkillAgent\SkillAssetAuditService;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssetLock;
use App\Services\SkillAgent\SkillRegistryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SkillAssetAuditTest extends TestCase
{
    private string $temporaryRoot;

    private string $skillsRoot;

    private string $overlayRoot;

    private string $registryPath;

    private array $previousEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Snapshot matching has its own filesystem tests; this suite isolates asset diagnostics.
        $lock = $this->createMock(SkillAssetLock::class);
        $lock->method('verify')->willReturn(['status' => 'matched', 'findings' => []]);
        $this->app->instance(SkillAssetLock::class, $lock);
        $this->temporaryRoot = sys_get_temp_dir().'/skill-audit-'.bin2hex(random_bytes(8));
        $this->skillsRoot = $this->temporaryRoot.'/upstream/skills';
        $this->overlayRoot = $this->temporaryRoot.'/overlay';
        $this->registryPath = $this->temporaryRoot.'/selected-registry.json';
        mkdir($this->skillsRoot, 0755, true);
        mkdir($this->overlayRoot, 0755, true);
        $this->previousEnvironment = [getenv('SKILLS_REPO_PATH'), $_ENV['SKILLS_REPO_PATH'] ?? null, $_SERVER['SKILLS_REPO_PATH'] ?? null];
        $this->setSkillsRoot($this->skillsRoot);
        config([
            'geo.skill_overlay_path' => $this->overlayRoot,
            'geo.skill_registry_path' => $this->registryPath,
            'geo.visibility_panel_enabled' => false,
            // Any accidental database access must fail rather than use a developer database.
            'database.default' => 'asset_audit_must_not_connect',
        ]);
    }

    protected function tearDown(): void
    {
        [$process, $environment, $server] = $this->previousEnvironment;
        putenv($process === false ? 'SKILLS_REPO_PATH' : 'SKILLS_REPO_PATH='.$process);
        if ($environment === null) {
            unset($_ENV['SKILLS_REPO_PATH']);
        } else {
            $_ENV['SKILLS_REPO_PATH'] = $environment;
        }
        if ($server === null) {
            unset($_SERVER['SKILLS_REPO_PATH']);
        } else {
            $_SERVER['SKILLS_REPO_PATH'] = $server;
        }
        $this->removeFixtureTree($this->temporaryRoot);
        parent::tearDown();
    }

    public function test_actual_registry_and_loader_selections_are_reported_without_changing_files(): void
    {
        $this->writeRegistry(['audit-demo'], ['paused-demo']);
        $this->writeSkill();
        $this->write($this->overlayRoot.'/audit-demo/SKILL.md', 'Read references/method.md; run scripts/render_demo.py with examples/sample/report_input.json.');
        $this->write($this->overlayRoot.'/audit-demo/references/method.md', 'Required overlay method.');
        $this->write($this->overlayRoot.'/audit-demo/scripts/render_demo.py', '# selected overlay renderer');
        $this->write($this->overlayRoot.'/audit-demo/scripts/helper.py', '# renderer sibling');
        $this->write($this->overlayRoot.'/audit-demo/examples/sample/report_input.json', '{"version":"overlay"}');
        $before = $this->fixtureHashes();
        $registry = new SkillRegistryService;
        $loader = new SkillAssetLoader;

        $report = (new SkillAssetAuditService($registry, $loader))->audit();

        $this->assertSame(['audit-demo'], $report['registered_skill_ids']);
        $this->assertSame(array_map(fn (SkillDefinition $skill) => $skill->id, $registry->all()), $report['registered_skill_ids']);
        $this->assertSame(['audit-demo'], $report['available_skill_ids']);
        $this->assertSame('configured_registry', $report['registry']['selected_source']);
        $this->assertSame(0, $report['summary']['errors']);
        $skill = $report['skills'][0];
        $this->assertSame('overlay', $skill['instruction']['source']);
        $this->assertSame(hash('sha256', $loader->loadSkillMd('audit-demo')), $skill['instruction']['sha256']);
        $this->assertSame('overlay', $skill['references'][0]['source']);
        $this->assertSame(hash('sha256', $loader->loadReferences('audit-demo')['method.md']), $skill['references'][0]['sha256']);
        $this->assertSame($loader->getRenderScript('audit-demo'), $skill['render_script']['path']);
        $this->assertSame('overlay', $skill['render_script']['source']);
        $this->assertSame('overlay', $skill['example_json']['source']);
        $this->assertSame($loader->loadExampleJson('audit-demo'), json_decode(file_get_contents($skill['example_json']['path']), true));
        $this->assertSame('overlay', $skill['script_support_files'][0]['source']);
        $this->assertCount(3, $skill['declared_files']);
        $this->assertSame($before, $this->fixtureHashes(), 'Audit must not repair assets, rewrite the registry, or create outputs.');
    }

    #[DataProvider('invalidRegistryCases')]
    public function test_invalid_registry_is_an_error_and_does_not_enable_an_alternate_registry(?string $contents, string $reason): void
    {
        if ($contents !== null) {
            $this->write($this->registryPath, $contents);
        }
        config(['geo.skill_allowed_ids' => ['audit-demo']]);
        $registry = new SkillRegistryService;
        $extra = ['yao-deepseek-crawler', 'yao-doubao-crawler', 'yao-chatgpt-crawler', 'yao-geo-article-friendly'];
        $alternate = dirname($this->skillsRoot).'/registry/skills.json';
        $this->writeRegistry($extra, [], $alternate);

        $report = (new SkillAssetAuditService($registry, new SkillAssetLoader))->audit();

        $this->assertSame('invalid_registry', $report['registry']['selected_source']);
        $this->assertSame($reason, $report['registry']['reason']);
        $this->assertContains('registry_invalid', $this->codes($report, 'error'));
        $this->assertSame([], $report['registered_skill_ids']);
        $this->assertSame([], $report['available_skill_ids']);
        $this->assertSame($extra, $report['alternate_registry']['not_registered_ids']);
        $this->assertFalse($report['alternate_registry']['selected']);
        $this->assertSame($this->registryPath, config('geo.skill_registry_path'));
        $this->bindAudit();
        $this->assertSame(1, Artisan::call('geo:audit-skill-assets', ['--json' => true]));
        $this->expectException(\App\Services\Exceptions\SkillRegistryException::class);
        (new SkillRegistryService)->all();
    }

    public static function invalidRegistryCases(): array
    {
        return [
            'missing' => [null, 'missing'],
            'malformed' => ['{broken', 'invalid_structure'],
            'wrong structure' => ['{"skills":"wrong"}', 'invalid_structure'],
            'no active rows' => ['{"skills":[{"id":"paused","status":"paused"}]}', 'no_active_entries'],
        ];
    }

    public function test_open_dedicated_entry_does_not_require_generic_model_assets(): void
    {
        $this->writeRegistry(['yao-geo-effect-monitor']);
        config(['geo.visibility_panel_enabled' => true]);
        $report = $this->audit();
        $monitor = collect($report['skills'])->firstWhere('id', 'yao-geo-effect-monitor');
        $this->assertTrue($monitor['available']);
        $this->assertSame('dedicated_entry', $monitor['mode']);
        $this->assertSame('/visibility', $monitor['action_path']);
        $this->assertSame([], array_values(array_filter($report['findings'], fn ($finding) => $finding['skill_id'] === 'yao-geo-effect-monitor')));
    }

    public function test_missing_empty_and_declared_assets_are_actionable_errors(): void
    {
        $this->writeRegistry(['audit-demo', 'no-instruction']);
        $this->writeSkill();
        $this->write($this->skillsRoot.'/audit-demo/SKILL.md', 'Read references/absent.md, then scripts/missing.py.');
        $this->write($this->skillsRoot.'/audit-demo/references/method.md', '');
        $this->write($this->skillsRoot.'/audit-demo/scripts/render_demo.py', '');

        $report = $this->audit();

        foreach (['instruction_missing_or_empty', 'reference_unreadable_or_empty', 'render_script_unreadable_or_empty', 'declared_file_missing'] as $code) {
            $this->assertContains($code, $this->codes($report, 'error'));
        }
        $missing = array_values(array_filter($report['findings'], fn ($finding) => $finding['code'] === 'declared_file_missing'));
        $this->assertSame(['references/absent.md', 'scripts/missing.py'], array_column($missing, 'path'));
        $this->assertSame(['audit-demo', 'audit-demo'], array_column($missing, 'skill_id'));
    }

    public function test_path_escape_is_reported_without_reading_the_target(): void
    {
        $this->writeRegistry(['audit-demo']);
        $this->writeSkill();
        $this->write($this->skillsRoot.'/audit-demo/SKILL.md', 'Read references/../../outside.md.');
        $this->write($this->skillsRoot.'/outside.md', 'must not be included');

        $report = $this->audit();

        $this->assertContains('declared_path_escape', $this->codes($report, 'error'));
        $this->assertSame([], $report['skills'][0]['declared_files']);
        $this->assertStringNotContainsString(hash('sha256', 'must not be included'), json_encode($report));
    }

    public function test_theme_editing_paths_are_unverified_not_missing_skill_dependencies(): void
    {
        $this->writeRegistry(['yao-geoflow-template', 'audit-demo']);
        config(['geo.theme_package_enabled' => true]);
        $template = $this->skillsRoot.'/yao-geoflow-template';
        $design = $this->skillsRoot.'/audit-demo';
        $this->write($template.'/SKILL.md', 'Edit the target site theme using references/theme-styles.md.');
        $this->write($template.'/references/theme-styles.md', 'Update assets/css/style.css and assets/css/custom.css in the target theme.');
        // A runnable fixture and three references repeat the same allowed
        // target-theme file. It is not a dependency inside the skill package.
        $this->write($design.'/SKILL.md', 'Edit only allowed theme files: assets/theme.css.');
        foreach (['workflow', 'style-rules', 'acceptance'] as $name) {
            $this->write($design.'/references/'.$name.'.md', 'The target theme may be changed through assets/theme.css.');
        }

        $report = $this->audit();

        $this->assertSame(0, $report['summary']['errors']);
        $this->assertNotContains('declared_file_missing', $this->codes($report, 'error'));
        $unverified = array_values(array_filter($report['findings'], fn ($finding) => $finding['code'] === 'declared_path_unverified'));
        // 站点模板已改走本仓真实主题包面板，不再执行上游通用模型技能，
        // 因此它文档里的编辑目标也不属于运行依赖；通用技能使用独立夹具，避免依赖业务入口的当前实现。
        $this->assertCount(1, $unverified);
        $this->assertSame(['assets/theme.css'], array_column($unverified, 'path'));
        $this->assertSame(['warning'], array_column($unverified, 'severity'));
        $declarations = $report['skills'][1]['declared_files'];
        $this->assertCount(4, $declarations, 'Keep each declaring document as evidence while findings are deduplicated.');
        $this->assertCount(4, array_unique(array_column($declarations, 'declared_in')));
        $this->assertSame(['unverified'], array_values(array_unique(array_column($declarations, 'verification'))));
        $this->bindAudit();
        $this->assertSame(0, Artisan::call('geo:audit-skill-assets', ['--json' => true]));
        $this->assertSame(1, Artisan::call('geo:audit-skill-assets', ['--json' => true, '--strict' => true]));
    }

    #[DataProvider('ambiguousDocumentPaths')]
    public function test_unresolved_document_path_roles_are_not_guessed_as_package_dependencies(string $relative): void
    {
        $this->writeRegistry(['audit-demo']);
        $this->writeSkill();
        $this->write($this->skillsRoot.'/audit-demo/SKILL.md', 'Prepare '.$relative.'.');

        $report = $this->audit();

        $this->assertSame(0, $report['summary']['errors']);
        $this->assertContains('declared_path_unverified', $this->codes($report, 'warning'));
        $this->assertSame('unverified', $report['skills'][0]['declared_files'][0]['verification']);
        $this->assertSame([], $report['skills'][0]['declared_files'][0]['files']);
    }

    public static function ambiguousDocumentPaths(): array
    {
        return [
            'external theme' => ['assets/theme.css'],
            'template output' => ['templates/index.html'],
            'generated example' => ['examples/generated/report_input.json'],
            'parent target' => ['assets/../../theme.css'],
        ];
    }

    public function test_missing_package_resource_findings_are_deduplicated_per_skill_without_losing_evidence(): void
    {
        $this->writeRegistry(['audit-demo', 'audit-other']);
        foreach (['audit-demo', 'audit-other'] as $id) {
            $text = 'Read references/missing.md and run scripts/missing.py.';
            $this->write($this->skillsRoot.'/'.$id.'/SKILL.md', $text);
            $this->write($this->skillsRoot.'/'.$id.'/references/workflow.md', $text);
        }

        $report = $this->audit();

        $missing = array_values(array_filter($report['findings'], fn ($finding) => $finding['code'] === 'declared_file_missing'));
        $this->assertCount(4, $missing, 'Two distinct missing resources remain errors for each affected skill.');
        $this->assertSame(4, $report['summary']['errors']);
        $this->assertSame(['audit-demo', 'audit-demo', 'audit-other', 'audit-other'], array_column($missing, 'skill_id'));
        foreach ($report['skills'] as $skill) {
            $this->assertCount(4, $skill['declared_files']);
            $this->assertCount(2, array_unique(array_column($skill['declared_files'], 'declared_in')));
            $this->assertSame(['missing'], array_values(array_unique(array_column($skill['declared_files'], 'verification'))));
        }
    }

    public function test_wrong_repository_root_is_reported_and_not_automatically_corrected(): void
    {
        $this->writeRegistry(['audit-demo']);
        $this->writeSkill();
        $wrongRoot = dirname($this->skillsRoot);
        $this->setSkillsRoot($wrongRoot);

        $report = $this->audit();

        $this->assertContains('skills_root_points_to_repository', $this->codes($report, 'error'));
        $this->assertSame($wrongRoot, $report['skills_root']);
        $this->assertFalse($report['skills'][0]['instruction']['exists']);
        $this->assertSame($wrongRoot, (new SkillAssetLoader)->getSkillsRoot());
    }

    public function test_invalid_overlay_example_reports_fallback_to_the_actual_upstream_example(): void
    {
        $this->writeRegistry(['audit-demo']);
        $this->writeSkill();
        $this->write($this->overlayRoot.'/audit-demo/examples/sample/report_input.json', '{broken');

        $report = $this->audit();

        $this->assertContains('example_invalid', $this->codes($report, 'warning'));
        $this->assertSame('upstream', $report['skills'][0]['example_json']['source']);
        $this->assertSame('upstream', $report['skills'][0]['render_script']['source']);
        $this->assertSame(0, $report['summary']['errors']);
    }

    public function test_valid_scalar_json_that_violates_loader_return_contract_is_an_error(): void
    {
        $this->writeRegistry(['audit-demo']);
        $this->writeSkill();
        $this->write($this->overlayRoot.'/audit-demo/examples/sample/report_input.json', '42');

        $report = $this->audit();

        $this->assertContains('example_load_failed', $this->codes($report, 'error'));
        $this->assertNull($report['skills'][0]['example_json']);
    }

    public function test_git_metadata_fallback_reports_head_without_claiming_a_clean_worktree(): void
    {
        $this->writeRegistry(['audit-demo']);
        $this->writeSkill();
        $head = str_repeat('a1', 20);
        // Deliberately incomplete Git metadata: no objects/index. The Git command
        // cannot treat this as a repository, but a read-only mounted HEAD is useful.
        $git = dirname($this->skillsRoot).'/.git';
        $this->write($git.'/HEAD', "ref: refs/heads/main\n");
        $this->write($git.'/packed-refs', $head." refs/heads/main\n");

        $report = $this->audit();

        $this->assertSame($head, $report['upstream_version']['head']);
        $this->assertSame('git_metadata', $report['upstream_version']['method']);
        $this->assertNull($report['upstream_version']['asset_path_dirty']);
        $this->assertContains('upstream_dirty_state_unknown', $this->codes($report, 'warning'));
    }

    public function test_git_worktree_pointer_and_shared_refs_are_read_only(): void
    {
        $this->writeRegistry(['audit-demo']);
        $this->writeSkill();
        $head = str_repeat('b2', 20);
        $this->write(dirname($this->skillsRoot).'/.git', 'gitdir: ../git-meta/worktrees/audit');
        $this->write($this->temporaryRoot.'/git-meta/worktrees/audit/HEAD', "ref: refs/heads/main\n");
        $this->write($this->temporaryRoot.'/git-meta/worktrees/audit/commondir', '../..');
        $this->write($this->temporaryRoot.'/git-meta/refs/heads/main', $head);
        $before = $this->fixtureHashes();

        $report = $this->audit();

        $this->assertSame($head, $report['upstream_version']['head']);
        $this->assertSame($before, $this->fixtureHashes());
    }

    public function test_json_command_returns_warning_and_strict_exit_codes_without_a_database(): void
    {
        $this->writeRegistry(['audit-demo']);
        $this->writeSkill();
        $this->bindAudit();

        $this->assertSame(0, Artisan::call('geo:audit-skill-assets', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('warning', $report['status']);
        $this->assertSame(0, $report['exit_code']);
        $this->assertFalse($report['strict']);
        $this->assertGreaterThan(0, $report['summary']['warnings']);
        $this->assertSame(1, Artisan::call('geo:audit-skill-assets', ['--json' => true, '--strict' => true]));
        $strict = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $strict['exit_code']);
        $this->assertTrue($strict['strict']);
        $this->assertSame($report['summary'], $strict['summary']);
    }

    public function test_command_reports_errors_and_readable_text(): void
    {
        $this->bindAudit();
        $this->assertSame(1, Artisan::call('geo:audit-skill-assets', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('error', $report['status']);
        $this->assertSame(1, $report['exit_code']);
        $this->assertSame(1, Artisan::call('geo:audit-skill-assets'));
        $output = Artisan::output();
        $this->assertStringContainsString('invalid_registry', $output);
        $this->assertStringContainsString('GEO_SKILL_REGISTRY_PATH', $output);
    }

    public function test_unexpected_inspection_failure_is_machine_readable_with_exit_two(): void
    {
        $registry = $this->createMock(SkillRegistry::class);
        $registry->expects($this->once())->method('all')->willThrowException(new RuntimeException('fixture failure'));
        $this->app->instance(SkillAssetAuditService::class, new SkillAssetAuditService($registry, new SkillAssetLoader));

        $this->assertSame(2, Artisan::call('geo:audit-skill-assets', ['--json' => true]));

        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('inspection_failed', $report['status']);
        $this->assertSame('fixture failure', $report['message']);
        $this->assertSame(2, $report['exit_code']);
    }

    private function audit(): array
    {
        return (new SkillAssetAuditService(new SkillRegistryService, new SkillAssetLoader))->audit();
    }

    private function bindAudit(): void
    {
        $this->app->instance(SkillAssetAuditService::class, new SkillAssetAuditService(new SkillRegistryService, new SkillAssetLoader));
    }

    private function setSkillsRoot(string $path): void
    {
        putenv('SKILLS_REPO_PATH='.$path);
        $_ENV['SKILLS_REPO_PATH'] = $path;
        $_SERVER['SKILLS_REPO_PATH'] = $path;
    }

    private function writeRegistry(array $active, array $paused = [], ?string $path = null): void
    {
        if ($path === null || $path === $this->registryPath) {
            config(['geo.skill_allowed_ids' => $active]);
        }
        $rows = array_merge(array_map(fn ($id) => ['id' => $id, 'status' => 'active'], $active),
            array_map(fn ($id) => ['id' => $id, 'status' => 'paused'], $paused));
        $this->write($path ?? $this->registryPath, json_encode(['skills' => $rows], JSON_THROW_ON_ERROR));
    }

    private function writeSkill(): void
    {
        $root = $this->skillsRoot.'/audit-demo';
        $this->write($root.'/SKILL.md', 'Read references/method.md; run scripts/render_demo.py with examples/sample/report_input.json.');
        $this->write($root.'/references/method.md', 'Required method.');
        $this->write($root.'/scripts/render_demo.py', '# upstream renderer');
        $this->write($root.'/examples/sample/report_input.json', '{"version":"upstream"}');
    }

    private function write(string $path, string $contents): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function codes(array $report, string $severity): array
    {
        return array_column(array_filter($report['findings'], fn ($finding) => $finding['severity'] === $severity), 'code');
    }

    private function fixtureHashes(): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temporaryRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $result[$file->getPathname()] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($result);

        return $result;
    }

    private function removeFixtureTree(string $path): void
    {
        $resolved = realpath($path);
        $root = realpath($this->temporaryRoot);
        if ($resolved === false || $root === false) {
            return;
        }
        if ($resolved !== $root && ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Refusing to remove a path outside the temporary fixture.');
        }
        foreach (scandir($path) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $path.'/'.$name;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeFixtureTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
