<?php

namespace App\Services\SkillAgent;

/** Verify the release's upstream snapshot without modifying or fetching assets. */
class SkillAssetLock
{
    public function verify(string $root, ?string $head): array
    {
        $path = (string) config('geo.skill_asset_lock_path');
        $lock = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        $findings = [];
        $add = function (string $code, string $message, string $file) use (&$findings): void {
            $findings[] = ['code' => $code, 'message' => $message, 'path' => $file];
        };
        $valid = is_array($lock) && ($lock['schema_version'] ?? null) === 1
            && is_string($lock['upstream_commit'] ?? null)
            && preg_match('/^[a-f0-9]{40}$/', $lock['upstream_commit']) === 1
            && is_array($lock['approved_skill_ids'] ?? null)
            && $lock['approved_skill_ids'] === array_values(array_diff(
                config('geo.skill_allowed_ids', []), SkillRegistryService::LOCAL_ACTION_IDS,
            ))
            && is_array($lock['files'] ?? null) && $lock['files'] !== []
            && is_string($lock['registry_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $lock['registry_sha256']) === 1;
        if (! $valid) {
            $add('asset_lock_invalid', '上游资产锁缺失、无效或与已审定技能清单不一致。', $path);

            return ['path' => $path, 'status' => 'invalid', 'findings' => $findings];
        }
        if ($head !== $lock['upstream_commit']) {
            $add('upstream_commit_mismatch', '上游版本与本次发布锁定的提交不一致或无法确认。', $root);
        }
        $registry = dirname($root).'/registry/skills.json';
        if (! is_readable($registry) || hash_file('sha256', $registry) !== $lock['registry_sha256']) {
            $add('upstream_registry_mismatch', '上游注册表与锁定快照不同；不会改变本地开放清单。', $registry);
        }
        $actual = [];
        foreach ($lock['approved_skill_ids'] as $id) {
            if (! is_string($id) || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) !== 1) {
                $add('asset_lock_invalid', '资产锁包含无效技能 ID。', $path);

                continue;
            }
            $directory = $root.'/'.$id;
            if (! is_dir($directory) || is_link($directory)) {
                $add('locked_skill_directory_missing', '锁定的技能目录缺失或为符号链接。', $directory);

                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, '/\\')) + 1));
                // Interpreter caches are generated, not versioned skill inputs.
                if (in_array('__pycache__', explode('/', $relative), true) || str_ends_with($relative, '.pyc')) {
                    continue;
                }
                if ($file->isLink()) {
                    $add('locked_asset_symlink', '资产包含符号链接，未读取链接目标。', $relative);
                } elseif ($file->isFile()) {
                    $actual[$relative] = $file->isReadable() ? hash_file('sha256', $file->getPathname()) : null;
                }
            }
        }
        foreach ($lock['files'] as $relative => $hash) {
            if (! is_string($relative) || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
                || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true)
                || ! in_array(explode('/', $relative)[0], $lock['approved_skill_ids'], true)) {
                $add('asset_lock_invalid', '资产锁包含无效文件路径或指纹；未读取该路径。', $path);

                continue;
            }
            if (! array_key_exists($relative, $actual)) {
                $add('locked_asset_missing', '锁定资产已缺失。', $relative);
            } elseif ($actual[$relative] !== $hash) {
                $add('locked_asset_changed', '资产内容与锁定指纹不一致。', $relative);
            }
        }
        foreach (array_diff_key($actual, $lock['files']) as $relative => $hash) {
            $add('locked_asset_added', '已审定技能目录新增了未锁定资产，需审核更新快照。', $relative);
        }

        return ['path' => $path, 'status' => $findings === [] ? 'matched' : 'mismatch',
            'expected_commit' => $lock['upstream_commit'], 'expected_files' => count($lock['files']),
            'actual_files' => count($actual), 'findings' => $findings];
    }
}
