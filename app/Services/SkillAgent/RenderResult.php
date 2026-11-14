<?php

namespace App\Services\SkillAgent;

/**
 * Python 渲染脚本执行结果
 */
final readonly class RenderResult
{
    public function __construct(
        public bool $success,
        /** @var array<string, string> 格式 => 存储路径 */
        public array $renderedPaths = [],
        public ?array $qualityReport = null,
        public ?string $errorMessage = null,
        /** 渲染器规范化并完成质检的文章正文，不是整份研究报告。 */
        public ?string $publishableArticle = null,
    ) {}

    public static function failure(string $error): self
    {
        return new self(success: false, errorMessage: $error);
    }

    public static function skipped(string $reason = 'No render script'): self
    {
        return new self(success: false, errorMessage: $reason);
    }

    /**
     * 获取所有渲染产物的格式列表
     */
    public function availableFormats(): array
    {
        return array_keys($this->renderedPaths);
    }
}
