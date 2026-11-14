<?php

namespace App\Services\Enums;

/**
 * Skill 分类
 *
 * 与 yao-geo-skills 的 registry/skills.json 分类一致。
 */
enum SkillCategory: string
{
    case GeoResearch = 'geo-research';
    case GeoStrategy = 'geo-strategy';
    case GeoContentProduction = 'geo-content-production';
    case GeoPageTechnical = 'geo-page-technical';
    case GeoMeasurement = 'geo-measurement';
    case GeoKnowledgeAssets = 'geo-knowledge-assets';
    case GeoOperations = 'geo-operations';

    /**
     * 中文标签（前端展示用）
     */
    public function label(): string
    {
        return match ($this) {
            self::GeoResearch => '研究拓词',
            self::GeoStrategy => '策略诊断',
            self::GeoContentProduction => '内容生产',
            self::GeoPageTechnical => '页面技术',
            self::GeoMeasurement => '监测归因',
            self::GeoKnowledgeAssets => '知识资产',
            self::GeoOperations => '运营操作',
        };
    }
}
