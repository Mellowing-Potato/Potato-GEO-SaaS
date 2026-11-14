<?php

namespace App\Services\SkillAgent;

/**
 * Skill 完整资产包
 */
final readonly class SkillAssets
{
    public function __construct(
        public string $skillId,
        public string $skillMd,
        /** @var array<string, string> 文件名 => 内容 */
        public array $references,
        public ?string $renderScript,
        public ?string $exampleJsonSchema,
        public bool $hasAssets,
    ) {}

    /**
     * 将 SKILL.md + references 合并为完整的 Agent 指令文本
     */
    public function buildFullInstruction(): string
    {
        $parts = [];

        // SKILL.md 作为主指令
        if ($this->skillMd) {
            $parts[] = "=== SKILL 执行指令 ===\n{$this->skillMd}";
        }

        // 注入所有 references
        foreach ($this->references as $name => $content) {
            $parts[] = "\n=== 参考文档: {$name} ===\n{$content}";
        }

        return implode("\n", $parts);
    }

    /**
     * 估算总 token 数（粗略：4字符 ≈ 1 token）
     */
    public function estimateTokens(): int
    {
        $total = mb_strlen($this->skillMd);
        foreach ($this->references as $content) {
            $total += mb_strlen($content);
        }
        if ($this->exampleJsonSchema) {
            $total += mb_strlen($this->exampleJsonSchema);
        }
        return (int) ceil($total / 4);
    }
}
