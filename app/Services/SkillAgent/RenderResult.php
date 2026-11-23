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
        /** @var array<string, array{status: string, reason?: string}> */
        public array $formatStatus = [],
    ) {}

    /** Output formats provided by the pinned renderer contracts. */
    public static function supportedFormats(string $skillId): array
    {
        return match ($skillId) {
            'yao-geo-tracking' => ['html', 'docx'],
            'yao-geo-brand-graph', 'yao-geo-content-refiner', 'yao-geo-execution-roadmap',
            'yao-geo-explainer-builder', 'yao-geo-intent-miner', 'yao-geo-knowledge-base-builder',
            'yao-geo-page-blueprint', 'yao-geo-panorama-audit', 'yao-geo-ranking-article-builder',
            'yao-geo-title-optimizer' => ['markdown', 'html', 'docx', 'pdf'],
            default => [],
        };
    }

    public static function failure(string $error, array $formatStatus = []): self
    {
        return new self(success: false, errorMessage: $error, formatStatus: $formatStatus);
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
