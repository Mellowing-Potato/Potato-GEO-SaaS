<?php

namespace App\Services\SkillAgent;

use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\SkillDefinition;
use App\Services\Exceptions\SkillRegistryException;
use Symfony\Component\Process\Process;
use Throwable;

/** 只读检查当前进程实际选择的技能资产；不修复配置、不执行技能。 */
final class SkillAssetAuditService
{
    private array $findings = [];

    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly SkillAssetLoader $loader,
    ) {}

    public function audit(): array
    {
        $this->findings = [];
        $skillsRoot = $this->loader->getSkillsRoot();
        $overlayRoot = rtrim((string) config('geo.skill_overlay_path'), '/\\');
        $registryPath = (string) config('geo.skill_registry_path');
        $source = $this->registryFile($registryPath);
        // 清单唯一来源是当前注册器，诊断源 JSON 不用于创建或增删 SkillDefinition。
        try {
            $definitions = $this->registry->all();
            $source['selected_source'] = 'configured_registry';
        } catch (SkillRegistryException $exception) {
            $definitions = [];
            $source['selected_source'] = 'invalid_registry';
            $this->finding('error', 'registry_invalid', $exception->getMessage().' 运行时停止技能注册，不使用备用清单。', $registryPath);
        }
        $source['approved_ids'] = config('geo.skill_allowed_ids', []);
        $source['excluded_active_ids'] = array_values(array_diff($source['active_ids'], $source['approved_ids']));
        $registeredIds = array_map(fn (SkillDefinition $skill) => $skill->id, $definitions);
        $availableIds = array_values(array_map(fn (SkillDefinition $skill) => $skill->id,
            array_filter($definitions, fn (SkillDefinition $skill) => $skill->isAvailable())));
        $alternatePath = dirname($skillsRoot).'/registry/skills.json';
        $alternate = $this->samePath($registryPath, $alternatePath) || ! is_file($alternatePath)
            ? null : $this->registryFile($alternatePath);
        if ($alternate !== null) {
            $alternate['not_registered_ids'] = array_values(array_diff($alternate['active_ids'], $registeredIds));
            $alternate['selected'] = false;
        }
        if (! is_dir($skillsRoot)) {
            $this->finding('error', 'skills_root_missing', '实际技能根目录不存在。', $skillsRoot);
        } elseif (is_dir($skillsRoot.'/skills')) {
            $this->finding('error', 'skills_root_points_to_repository', '技能根目录内还有 skills 子目录，请核对 SKILLS_REPO_PATH 是否误指向仓库根；本命令不会自动改路径。', $skillsRoot);
        }
        if ($overlayRoot !== '' && ! is_dir($overlayRoot)) {
            $this->finding('warning', 'overlay_root_missing', '配置的覆盖层目录不存在，将使用上游脚本和示例。', $overlayRoot);
        }

        $skills = array_map(fn (SkillDefinition $skill) => $this->inspectSkill($skill, $skillsRoot, $overlayRoot), $definitions);
        $upstreamVersion = $this->gitVersion($skillsRoot);
        $overlayVersion = $this->gitVersion($overlayRoot);
        $assetLock = app(SkillAssetLock::class)->verify($skillsRoot, $upstreamVersion['head']);
        foreach ($assetLock['findings'] as $finding) {
            $this->finding('error', $finding['code'], $finding['message'], $finding['path']);
        }
        if ($upstreamVersion['head'] === null) {
            $this->finding('warning', 'upstream_version_unknown', '无法读取上游 Git 版本；仍提供选中文件的 SHA256。', $skillsRoot);
        }
        if ($upstreamVersion['asset_path_dirty'] === true) {
            $this->finding('warning', 'upstream_assets_modified', '上游资产目录有未提交修改；HEAD 不能单独代表实际文件。', $skillsRoot);
        } elseif ($upstreamVersion['head'] !== null && $upstreamVersion['asset_path_dirty'] === null) {
            $this->finding('warning', 'upstream_dirty_state_unknown', '已读取 HEAD，但无法核对工作区修改；文件 SHA256 才代表本次实际内容。', $skillsRoot);
        }
        $errors = count(array_filter($this->findings, fn ($finding) => $finding['severity'] === 'error'));
        $warnings = count($this->findings) - $errors;

        return [
            'schema_version' => 1,
            'generated_at' => gmdate('c'),
            'read_only' => true,
            'status' => $errors ? 'error' : ($warnings ? 'warning' : 'ok'),
            'summary' => ['registered' => count($definitions), 'available' => count($availableIds), 'errors' => $errors, 'warnings' => $warnings],
            'registry' => $source,
            'alternate_registry' => $alternate,
            'registered_skill_ids' => $registeredIds,
            'available_skill_ids' => $availableIds,
            'skills_root' => $skillsRoot,
            'overlay_root' => $overlayRoot,
            'upstream_version' => $upstreamVersion,
            'upstream_lock' => $assetLock,
            'overlay_version' => $overlayVersion,
            'skills' => $skills,
            'findings' => $this->findings,
            'not_checked' => [
                '数据库中的租户提示词覆盖、模型密钥、额度和外部服务连通性',
                'Python 包、系统命令、字体和动态构造的文件依赖；不执行渲染脚本',
                '文档中 assets、templates、examples 路径的用途；它们可能指向外部工程或待生成产物',
                '渲染内容正确性、生成质量、付费调用和完整用户流程',
                '远端上游最新版本；Git 信息仅来自本地挂载目录，不 fetch',
            ],
        ];
    }

    private function inspectSkill(SkillDefinition $skill, string $root, string $overlay): array
    {
        $result = [
            'id' => $skill->id, 'name' => $skill->name, 'available' => $skill->isAvailable(),
            'unavailable_reason' => $skill->unavailableReason, 'action_path' => $skill->actionPath,
            'mode' => $skill->actionPath !== null ? 'dedicated_entry' : 'skill_runner',
            'assets_checked' => false,
        ];
        if (! $skill->isAvailable()) {
            return $result;
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $skill->id) !== 1) {
            $this->finding('error', 'invalid_skill_path', '技能 ID 不能安全地映射为资产子目录。', $skill->id, $skill->id);

            return $result;
        }
        $skillDir = $root.'/'.$skill->id;
        $required = $skill->actionPath === null;
        $overlayInstruction = $overlay.'/'.$skill->id.'/SKILL.md';
        $instructionPath = $overlay !== '' && is_file($overlayInstruction)
            ? $overlayInstruction : $skillDir.'/SKILL.md';
        $instruction = $this->file($instructionPath, $this->origin($instructionPath, $overlay));
        $result['assets_checked'] = true;
        $result['instruction'] = $instruction;
        if ($required && ! $this->usable($instruction)) {
            $this->finding('error', 'instruction_missing_or_empty', 'SKILL.md 缺失、不可读或为空；通用技能将缺少上游指令。', $instruction['path'], $skill->id);
        }
        $references = [];
        $referenceRoot = dirname($instructionPath);
        foreach (glob($referenceRoot.'/references/*.md') ?: [] as $path) {
            $references[] = $file = $this->file($path, $this->origin($path, $overlay));
            if ($required && ! $this->usable($file)) {
                $this->finding('error', 'reference_unreadable_or_empty', '加载器会读取的参考文件不可读或为空。', $path, $skill->id);
            }
        }
        $result['references'] = $references;
        $result['reference_loading'] = $instruction['source'].' references/*.md；与实际选中的 SKILL.md 同源';
        if ($required && $references === []) {
            $this->finding('warning', 'references_empty', '没有可自动注入的 references/*.md；是否必需仍以该技能契约为准。', $referenceRoot.'/references', $skill->id);
        }
        $selectedScript = $this->loader->getRenderScript($skill->id);
        $result['render_script'] = $selectedScript === null ? null : $this->file($selectedScript, $this->origin($selectedScript, $overlay));
        if ($required && $selectedScript === null) {
            $this->finding('warning', 'render_script_not_found', '加载器未选择渲染脚本；可能走 Markdown 路径，此处不等同于技能执行失败。', $skillDir.'/scripts', $skill->id);
        } elseif ($required && ! $this->usable($result['render_script'])) {
            $this->finding('error', 'render_script_unreadable_or_empty', '选中的渲染脚本不可读或为空。', $selectedScript, $skill->id);
        }
        $result['script_support_files'] = [];
        if ($selectedScript !== null) {
            foreach (glob(dirname($selectedScript).'/*') ?: [] as $path) {
                if (is_file($path) && $path !== $selectedScript) {
                    $result['script_support_files'][] = $this->file($path, $this->origin($path, $overlay));
                }
            }
        }
        $result['example_json'] = $this->example($skill->id, $root, $overlay, $required);
        $result['builtin_schema_available'] = $this->loader->getSchemaRegistry()->getSchema($skill->id) !== null;
        $result['declared_files'] = [];
        if ($required) {
            foreach (array_merge([$instruction], $references) as $document) {
                if ($this->usable($document) && $document['bytes'] <= 2 * 1024 * 1024) {
                    $result['declared_files'] = array_merge($result['declared_files'],
                        $this->declaredFiles((string) file_get_contents($document['path']), $document['path'], $skill->id, $skillDir, $overlay, $referenceRoot));
                }
            }
        }

        return $result;
    }

    /** 复用加载器返回值定位实际选中的示例；不把无效的上游示例冒充生效内容。 */
    private function example(string $id, string $root, string $overlay, bool $required): ?array
    {
        try {
            $selected = $this->loader->loadExampleJson($id);
        } catch (Throwable $exception) {
            $this->finding($required ? 'error' : 'warning', 'example_load_failed', '示例 JSON 不能按加载器契约读取：'.$exception->getMessage(), $root.'/'.$id.'/examples', $id);

            return null;
        }
        $candidates = $overlay !== '' ? [$overlay.'/'.$id.'/examples', $root.'/'.$id.'/examples'] : [$root.'/'.$id.'/examples'];
        $found = null;
        foreach ($candidates as $directory) {
            foreach (glob($directory.'/*/report_input.json') ?: [] as $path) {
                $file = $this->file($path, $this->origin($path, $overlay));
                $decoded = $file['readable'] ? json_decode((string) file_get_contents($path), true) : null;
                if (! $file['readable'] || json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    $this->finding('warning', 'example_invalid', '示例不可读或不是 JSON 对象/数组，加载器可能回退到下一份。', $path, $id);
                } elseif ($found === null && $selected !== null && $decoded === $selected) {
                    $found = $file;
                }
            }
        }

        return $found;
    }

    /** 文档中的路径不必然是包内依赖；只对 references/scripts 的缺失作错误判断。 */
    private function declaredFiles(string $text, string $document, string $id, string $skillDir, string $overlay, string $referenceRoot): array
    {
        preg_match_all('~(?<![a-zA-Z0-9_/.:])(?:\./)?(?:references|scripts|templates|examples|assets)/[\p{L}a-zA-Z0-9_./*?+()\-]+\.(?:md|json|py|txt|toml|yaml|yml|ttf|ttc|woff2?|css|html|csv)~u', $text, $matches);
        $files = [];
        foreach (array_unique($matches[0]) as $relative) {
            $relative = preg_replace('~^\./~', '', $relative);
            $packageResource = preg_match('~^(references|scripts)/~', $relative) === 1;
            if (in_array('..', explode('/', $relative), true)) {
                $this->finding($packageResource ? 'error' : 'warning',
                    $packageResource ? 'declared_path_escape' : 'declared_path_unverified',
                    $packageResource ? '声明路径越出技能目录，未读取。' : '文档路径用途未确认且含上级目录，未读取；可能属于外部工程或生成产物，不判定包内依赖缺失。',
                    $relative, $id);
                if (! $packageResource) {
                    $files[] = ['declared_in' => $document, 'relative_path' => $relative,
                        'verification' => 'unverified', 'files' => [], 'note' => '路径用途未确认，未读取技能目录外文件。'];
                }

                continue;
            }
            $candidates = [];
            if (str_starts_with($relative, 'references/')) {
                $candidates[] = $referenceRoot.'/'.$relative;
            } elseif ($overlay !== '' && preg_match('~^(scripts|examples)/~', $relative)) {
                $candidates[] = $overlay.'/'.$id.'/'.$relative;
            }
            if (! str_starts_with($relative, 'references/')) {
                $candidates[] = $skillDir.'/'.$relative;
            }
            $selected = [];
            foreach ($candidates as $candidate) {
                $selected = array_values(array_filter(glob($candidate) ?: [], 'is_file'));
                if ($selected !== []) {
                    break;
                }
            }
            if ($selected === []) {
                $this->finding($packageResource ? 'error' : 'warning',
                    $packageResource ? 'declared_file_missing' : 'declared_path_unverified',
                    $packageResource ? '指令或参考文档引用的包内参考文件或脚本不存在。' : '文档路径在技能目录未找到，用途未确认；可能属于外部工程或生成产物，不判定包内依赖缺失。',
                    $relative, $id);
            }
            $files[] = [
                'declared_in' => $document, 'relative_path' => $relative,
                'verification' => $selected !== [] ? 'found' : ($packageResource ? 'missing' : 'unverified'),
                'files' => array_map(fn ($path) => $this->file($path, $this->origin($path, $overlay)), $selected),
                'note' => '路径出现不代表包内依赖；文件存在不代表自动注入。加载器只读取 SKILL.md、上游 references/*.md、选中的脚本和示例。',
            ];
        }

        return $files;
    }

    private function registryFile(string $path): array
    {
        $file = $this->file($path, 'configured_file');
        $decoded = $file['readable'] ? json_decode((string) file_get_contents($path), true) : null;
        $reason = ! $file['exists'] ? 'missing' : (! $file['readable'] ? 'unreadable' :
            (! is_array($decoded) || ! is_array($decoded['skills'] ?? null) ? 'invalid_structure' : null));
        $activeIds = [];
        if ($reason === null) {
            foreach ($decoded['skills'] as $metadata) {
                if (is_array($metadata) && is_string($metadata['id'] ?? null) && ($metadata['status'] ?? null) === 'active') {
                    $activeIds[] = $metadata['id'];
                }
            }
            $reason = $activeIds === [] ? 'no_active_entries' : null;
        }

        return array_merge($file, ['reason' => $reason, 'active_ids' => $activeIds]);
    }

    private function file(string $path, string $source): array
    {
        $exists = is_file($path);
        $readable = $exists && is_readable($path);

        return [
            'path' => $path, 'source' => $source, 'exists' => $exists, 'readable' => $readable,
            'resolved_path' => $exists ? realpath($path) : null,
            'bytes' => $exists ? filesize($path) : null,
            'sha256' => $readable ? hash_file('sha256', $path) : null,
        ];
    }

    private function usable(?array $file): bool
    {
        return $file !== null && $file['readable'] && $file['bytes'] > 0;
    }

    private function origin(string $path, string $overlay): string
    {
        return $overlay !== '' && str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $overlay).'/')
            ? 'overlay' : 'upstream';
    }

    private function samePath(string $left, string $right): bool
    {
        $left = str_replace('\\', '/', realpath($left) ?: $left);
        $right = str_replace('\\', '/', realpath($right) ?: $right);

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private function gitVersion(string $path): array
    {
        $result = ['asset_path' => $path, 'repository_root' => null, 'head' => null, 'asset_path_dirty' => null, 'method' => null];
        if (! is_dir($path)) {
            return $result;
        }
        $snapshot = dirname($path).'/.upstream-commit';
        if (is_readable($snapshot)) {
            $head = trim((string) file_get_contents($snapshot));
            if (preg_match('/^[a-f0-9]{40}$/', $head) === 1) {
                $lock = app(SkillAssetLock::class)->verify($path, $head);
                return array_merge($result, ['repository_root' => dirname($path), 'head' => $head,
                    'asset_path_dirty' => $lock['status'] !== 'matched', 'method' => 'locked_archive']);
            }
        }
        try {
            foreach (['repository_root' => ['rev-parse', '--show-toplevel'], 'head' => ['rev-parse', 'HEAD'],
                'asset_path_dirty' => ['status', '--porcelain=v1', '--untracked-files=all', '--', '.']] as $key => $args) {
                $process = new Process(array_merge(['git', '-c', 'core.fsmonitor=false', '-C', $path], $args));
                $process->setEnv(['GIT_OPTIONAL_LOCKS' => '0']);
                $process->setTimeout(10);
                $process->run();
                if (! $process->isSuccessful()) {
                    break;
                }
                $value = trim($process->getOutput());
                $result[$key] = $key === 'asset_path_dirty' ? $value !== '' : $value;
                $result['method'] = 'git_command';
            }
        } catch (Throwable) {
            // Git 不在镜像或元数据未挂载时，文件指纹仍然可用。
        }
        if ($result['head'] === null) {
            $metadata = $this->gitMetadata($path);
            if ($metadata !== null) {
                $result = array_merge($result, $metadata, ['method' => 'git_metadata']);
            }
        }

        return $result;
    }

    /** 镜像未安装 git 时只读取 HEAD/refs；不把元数据读取冒充工作区干净。 */
    private function gitMetadata(string $path): ?array
    {
        $directory = realpath($path);
        while ($directory !== false) {
            $gitDir = $directory.'/.git';
            if (is_file($gitDir) && is_readable($gitDir)) {
                $pointer = trim((string) file_get_contents($gitDir));
                if (! str_starts_with($pointer, 'gitdir: ')) {
                    return null;
                }
                $target = substr($pointer, 8);
                $gitDir = $this->absolutePath($target) ? $target : $directory.'/'.$target;
            }
            if (is_dir($gitDir)) {
                $headFile = $gitDir.'/HEAD';
                if (! is_readable($headFile)) {
                    return null;
                }
                $head = trim((string) file_get_contents($headFile));
                if (str_starts_with($head, 'ref: ')) {
                    $ref = substr($head, 5);
                    if (! preg_match('~^refs/[a-zA-Z0-9_./-]+$~', $ref) || in_array('..', explode('/', $ref), true)) {
                        return null;
                    }
                    $commonDir = $gitDir;
                    if (is_readable($gitDir.'/commondir')) {
                        $common = trim((string) file_get_contents($gitDir.'/commondir'));
                        $commonDir = $this->absolutePath($common) ? $common : $gitDir.'/'.$common;
                    }
                    $head = '';
                    foreach (array_unique([$gitDir, $commonDir]) as $refDir) {
                        if (is_readable($refDir.'/'.$ref)) {
                            $head = trim((string) file_get_contents($refDir.'/'.$ref));
                            break;
                        }
                        if (is_readable($refDir.'/packed-refs')) {
                            foreach (file($refDir.'/packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                                if (preg_match('/^([a-f0-9]{40,64}) '.preg_quote($ref, '/').'$/i', $line, $match)) {
                                    $head = $match[1];
                                    break 2;
                                }
                            }
                        }
                    }
                }

                return preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/i', $head)
                    ? ['repository_root' => $directory, 'head' => strtolower($head)] : null;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return null;
    }

    private function absolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1;
    }

    private function finding(string $severity, string $code, string $message, ?string $path = null, ?string $skillId = null): void
    {
        foreach ($this->findings as $finding) {
            if ($finding['code'] === $code && $finding['path'] === $path && $finding['skill_id'] === $skillId) {
                return;
            }
        }
        $this->findings[] = ['severity' => $severity, 'code' => $code, 'message' => $message, 'path' => $path, 'skill_id' => $skillId];
    }
}
