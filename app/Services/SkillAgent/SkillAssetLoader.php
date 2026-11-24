<?php

namespace App\Services\SkillAgent;

use Illuminate\Support\Facades\Log;

/**
 * Skill 资产加载器
 *
 * 从 yao-geo-skills 仓库加载 Skill 的完整资产：
 * - SKILL.md（Agent 执行指令）
 * - references/*.md（方法论文档）
 * - scripts/render_*.py（Python 渲染脚本路径）
 * - examples/[name]/report_input.json（示例 JSON，用于引导 LLM 输出格式）
 */
class SkillAssetLoader
{
    private string $skillsRoot;
    private string $overlayRoot;
    private RenderSchemaRegistry $schemaRegistry;

    public function __construct()
    {
        $this->skillsRoot = $this->resolveSkillsRoot();
        $this->overlayRoot = rtrim((string) config('geo.skill_overlay_path'), '/\\');
        $this->schemaRegistry = new RenderSchemaRegistry();
    }

    /**
     * 检查 Skill 资产目录是否存在
     */
    public function exists(string $skillId): bool
    {
        return is_dir($this->skillPath($skillId)) || is_file($this->overlaySkillMdPath($skillId));
    }

    public function getSkillsRoot(): string
    {
        return $this->skillsRoot;
    }

    /**
     * 加载 SKILL.md 内容
     */
    public function loadSkillMd(string $skillId): string
    {
        $path = $this->overlaySkillMdPath($skillId);
        if (! is_file($path)) {
            $path = $this->skillPath($skillId) . '/SKILL.md';
        }
        if (! file_exists($path)) {
            Log::warning("SKILL.md not found for {$skillId}", ['path' => $path]);
            return '';
        }
        return file_get_contents($path);
    }

    /**
     * 加载所有 references 文档，合并为一个字符串
     *
     * @return array<string, string> 文件名 => 内容
     */
    public function loadReferences(string $skillId): array
    {
        $refDir = is_file($this->overlaySkillMdPath($skillId))
            ? dirname($this->overlaySkillMdPath($skillId)) . '/references'
            : $this->skillPath($skillId) . '/references';
        if (! is_dir($refDir)) {
            return [];
        }

        $refs = [];
        foreach (glob($refDir . '/*.md') as $file) {
            $name = basename($file);
            $refs[$name] = file_get_contents($file);
        }

        return $refs;
    }

    /**
     * 获取 Python 渲染脚本路径
     *
     * 先查本仓覆盖层，再回落挂载进来的 yao-geo-skills（见 config/geo.php
     * 的 skill_overlay_path）。覆盖层里的脚本随镜像走，是有版本控制的那一份。
     */
    public function getRenderScript(string $skillId): ?string
    {
        foreach ($this->assetDirCandidates($skillId, 'scripts') as $scriptsDir) {
            // 查找 render_*.py 文件
            $scripts = glob($scriptsDir . '/render_*.py');
            if (! empty($scripts)) {
                return $scripts[0];
            }
        }

        return null;
    }

    /**
     * 加载示例 report_input.json（用于引导 LLM 输出格式）
     */
    public function loadExampleJson(string $skillId): ?array
    {
        // 与渲染脚本同源：覆盖层优先。示例 JSON 决定喂给 LLM 的输出格式，
        // 它和脚本必须是配套的一版，否则脚本按新形状校验、LLM 照旧形状生成。
        foreach ($this->assetDirCandidates($skillId, 'examples') as $examplesDir) {
            // 查找第一个 report_input.json
            foreach (glob($examplesDir . '/*/report_input.json') as $file) {
                $content = file_get_contents($file);
                $data = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * 获取示例 JSON 的 key 结构摘要（不含具体值，节省 token）
     */
    public function loadExampleJsonSchema(string $skillId): ?string
    {
        $example = $this->loadExampleJson($skillId);
        if (! $example) {
            return null;
        }

        return $this->summarizeJsonStructure($example);
    }

    /**
     * 获取完整的 Skill 资产包
     */
    public function loadAll(string $skillId): SkillAssets
    {
        return new SkillAssets(
            skillId: $skillId,
            skillMd: $this->loadSkillMd($skillId),
            references: $this->loadReferences($skillId),
            renderScript: $this->getRenderScript($skillId),
            exampleJsonSchema: $this->buildSchemaPrompt($skillId),
            hasAssets: $this->exists($skillId),
        );
    }

    /**
     * 获取 schema registry 实例（供 RenderBridge 使用）
     */
    public function getSchemaRegistry(): RenderSchemaRegistry
    {
        return $this->schemaRegistry;
    }

    /**
     * 构建综合 schema 描述：注册表精确 schema + example JSON 结构
     */
    private function buildSchemaPrompt(string $skillId): ?string
    {
        // 优先使用注册表的精确 schema
        $registrySchema = $this->schemaRegistry->buildSchemaPrompt($skillId);

        // 同时加载 example JSON 作为参考
        $exampleSchema = $this->loadExampleJsonSchema($skillId);

        if ($registrySchema && $exampleSchema) {
            return $registrySchema . "\n\n### 示例 JSON 结构参考（填充真实数据，不要照抄值）：\n```\n{$exampleSchema}\n```";
        }

        return $registrySchema ?? $exampleSchema;
    }

    private function skillPath(string $skillId): string
    {
        return $this->skillsRoot . '/' . $skillId;
    }

    private function overlaySkillMdPath(string $skillId): string
    {
        return $this->overlayRoot . '/' . $skillId . '/SKILL.md';
    }

    /**
     * 某类资产的查找顺序：本仓覆盖层 → 挂载进来的 yao-geo-skills。
     *
     * scripts 与 examples 独立回落。若覆盖层另有 SKILL.md，则指令与 references
     * 一起切换到覆盖层，避免新渲染契约和挂载树中的旧指令相互冲突。
     *
     * @return list<string> 存在的目录，按优先级排列；都不存在则为空
     */
    private function assetDirCandidates(string $skillId, string $subdir): array
    {
        $dirs = [];

        if ($this->overlayRoot !== '') {
            $dirs[] = $this->overlayRoot . '/' . $skillId . '/' . $subdir;
        }
        $dirs[] = $this->skillPath($skillId) . '/' . $subdir;

        return array_values(array_filter($dirs, 'is_dir'));
    }

    private function resolveSkillsRoot(): string
    {
        $configured = trim((string) config('geo.skills_repo_path', ''));
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        $candidates = [
            // Production's explicit read-only mount.
            base_path('yao-geo-skills/skills'),
            // Local reference workspace kept outside the deployed app artifact.
            base_path('yao-geo/yao-geo-skills/skills'),
            // Historical layout: yao-geo-skills next to newsaas.
            dirname(base_path()) . '/yao-geo-skills/skills',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return rtrim($candidate, '/\\');
            }
        }

        return rtrim($candidates[0], '/\\');
    }

    /**
     * 将 JSON 结构摘要为 schema 描述（保留 key 和类型，裁剪数组内容）
     */
    private function summarizeJsonStructure(array $data, int $depth = 0, int $maxDepth = 3): string
    {
        if ($depth >= $maxDepth) {
            return '...';
        }

        $lines = [];
        foreach ($data as $key => $value) {
            $prefix = str_repeat('  ', $depth);
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $count = count($value);
                    $firstItem = $count > 0 ? $value[0] : null;
                    if ($firstItem !== null && is_array($firstItem)) {
                        $itemType = $this->summarizeJsonStructure($firstItem, $depth + 1, $maxDepth);
                    } else {
                        $itemType = $firstItem !== null ? gettype($firstItem) : 'empty';
                    }
                    $lines[] = "{$prefix}\"{$key}\": [{$count} items] => {$itemType}";
                } else {
                    $lines[] = "{$prefix}\"{$key}\": {";
                    $lines[] = $this->summarizeJsonStructure($value, $depth + 1, $maxDepth);
                    $lines[] = "{$prefix}}";
                }
            } else {
                $type = gettype($value);
                $lines[] = "{$prefix}\"{$key}\": {$type}";
            }
        }

        return implode("\n", $lines);
    }
}
