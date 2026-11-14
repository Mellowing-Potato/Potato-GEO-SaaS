<?php

namespace Tests\Feature;

use App\Services\SkillAgent\SkillAssetLock;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SkillAssetLockTest extends TestCase
{
    private string $directory;

    private string $root;

    private string $path;

    private array $lock;

    private const HEAD = '1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/skill-lock-'.bin2hex(random_bytes(8));
        $this->root = $this->directory.'/skills';
        $this->path = $this->directory.'/lock.json';
        File::ensureDirectoryExists($this->root.'/demo/references');
        File::ensureDirectoryExists($this->directory.'/registry');
        file_put_contents($this->directory.'/registry/skills.json', '{"skills":[]}');
        file_put_contents($this->root.'/demo/SKILL.md', 'Read references/method.md');
        file_put_contents($this->root.'/demo/references/method.md', 'Expected instructions.');
        $this->lock = ['schema_version' => 1, 'upstream_commit' => self::HEAD,
            'approved_skill_ids' => ['demo'], 'registry_sha256' => hash_file('sha256', $this->directory.'/registry/skills.json'),
            'files' => ['demo/SKILL.md' => hash_file('sha256', $this->root.'/demo/SKILL.md'),
                'demo/references/method.md' => hash_file('sha256', $this->root.'/demo/references/method.md')]];
        $this->saveLock();
        config(['geo.skill_allowed_ids' => ['demo'], 'geo.skill_asset_lock_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_full_snapshot_matches_and_generated_python_cache_does_not_change_it(): void
    {
        File::ensureDirectoryExists($this->root.'/demo/scripts/__pycache__');
        file_put_contents($this->root.'/demo/scripts/__pycache__/render.cpython.pyc', 'cache');
        $before = file_get_contents($this->path);
        $result = $this->verify();
        $this->assertSame('matched', $result['status']);
        $this->assertSame(2, $result['actual_files']);
        $this->assertSame($before, file_get_contents($this->path));
    }

    public function test_same_head_does_not_hide_modified_missing_and_new_inputs(): void
    {
        file_put_contents($this->root.'/demo/SKILL.md', 'Modified instruction');
        unlink($this->root.'/demo/references/method.md');
        file_put_contents($this->root.'/demo/references/new.md', 'Unexpected newly loaded reference');
        $this->assertSame(['locked_asset_changed', 'locked_asset_missing', 'locked_asset_added'], $this->codes());
    }

    public function test_head_and_registry_changes_are_separately_reported(): void
    {
        file_put_contents($this->directory.'/registry/skills.json', '{"skills":["new"]}');
        $this->assertSame(['upstream_commit_mismatch', 'upstream_registry_mismatch'], $this->codes(null));
    }

    public function test_missing_or_invalid_lock_and_changed_approval_list_fail(): void
    {
        unlink($this->path);
        $this->assertSame(['asset_lock_invalid'], $this->codes());
        file_put_contents($this->path, '{bad');
        $this->assertSame(['asset_lock_invalid'], $this->codes());
        $this->saveLock();
        config(['geo.skill_allowed_ids' => ['demo', 'newly-opened']]);
        $this->assertSame(['asset_lock_invalid'], $this->codes());
    }

    public function test_lock_paths_cannot_read_outside_the_approved_skill_directory(): void
    {
        file_put_contents($this->directory.'/outside', 'Do not read or change');
        $this->lock['files']['demo/../../outside'] = hash('sha256', 'Do not read or change');
        $this->saveLock();
        $this->assertSame(['asset_lock_invalid'], $this->codes());
        $this->assertSame('Do not read or change', file_get_contents($this->directory.'/outside'));
    }

    public function test_missing_skill_directory_fails_even_if_head_matches(): void
    {
        File::deleteDirectory($this->root.'/demo');
        $this->assertContains('locked_skill_directory_missing', $this->codes());
        $this->assertContains('locked_asset_missing', $this->codes());
    }

    private function saveLock(): void
    {
        file_put_contents($this->path, json_encode($this->lock));
    }

    private function verify(?string $head = self::HEAD): array
    {
        return (new SkillAssetLock)->verify($this->root, $head);
    }

    private function codes(?string $head = self::HEAD): array
    {
        return array_column($this->verify($head)['findings'], 'code');
    }
}
