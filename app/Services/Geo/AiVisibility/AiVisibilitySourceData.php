<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Services/GeoFlow/AiVisibility/AiVisibilitySourceData.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Geo\AiVisibility;

/**
 * 信源 DTO（14 字段，与上游 GEOFlow AiVisibilitySourceData 1:1，计划书 §3.3）。
 *
 * 6 个直接映射 / 4 个可派生 / 4 个本轮留空——处置见字段注释。
 */
final readonly class AiVisibilitySourceData
{
    public function __construct(
        /** 固定 "url_citation"（豆包 annotations 的 type） */
        public string $sourceType = 'url_citation',
        /** 上游无：自造 "[1]" 样式的角标键（annotations 数组下标 +1） */
        public ?string $citationKey = null,
        /** 豆包 title 直接映射 */
        public ?string $title = null,
        /** 豆包 url 直接映射 */
        public string $url = '',
        /** 上游无：从 url 解析 host 派生 */
        public ?string $domain = null,
        /** 豆包 site_name 直接映射 */
        public ?string $siteName = null,
        /** 上游无：summary 截前 200 字派生 */
        public ?string $snippet = null,
        /** 豆包 summary 直接映射（整段正文摘录） */
        public ?string $summary = null,
        /** 本轮同 summary 或留空 */
        public ?string $contentExcerpt = null,
        /** ArkPublishTimeParser 解析；解析失败降级 null，绝不抛 */
        public ?\Carbon\CarbonImmutable $publishedAt = null,
        /** 上游无：annotations 数组下标 +1 */
        public ?int $rank = null,
        /** 本轮 null，不假造分数 */
        public ?float $rankScore = null,
        /** 本轮 null（需域名权威度表，不在本轮范围） */
        public ?string $authorityLevel = null,
        /** logo_url（临时签名链接）+ 原始 publish_time 字符串等，全塞这里 */
        public array $metadata = [],
    ) {}

    public function toRow(): array
    {
        return [
            'source_type' => $this->sourceType,
            'citation_key' => $this->citationKey,
            'title' => $this->title,
            'url' => $this->url,
            'domain' => $this->domain,
            'site_name' => $this->siteName,
            'snippet' => $this->snippet,
            'summary' => $this->summary,
            'content_excerpt' => $this->contentExcerpt,
            'published_at' => $this->publishedAt?->toDateTimeString(),
            'rank' => $this->rank,
            'rank_score' => $this->rankScore,
            'authority_level' => $this->authorityLevel,
            'metadata' => $this->metadata,
        ];
    }
}
