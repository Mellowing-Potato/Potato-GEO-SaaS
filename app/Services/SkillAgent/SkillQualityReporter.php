<?php

namespace App\Services\SkillAgent;

use App\Services\Enums\SkillCategory;

/**
 * 技能产出质量报告（计划 §7 第 3 步：把 SkillAgentRunnerService 按职责拆小，「质量」这一块）。
 *
 * 从执行引擎搬出来的三个纯函数：评分、证据核验、组装报告。它们不碰数据库、不调模型、
 * 只吃字符串返回数组 —— 所以可以用特征化测试冻结输出（tests/Feature/SkillQualityReportTest.php）。
 *
 * ⚠️ 评分与"页面实测证据"这两条判据是 2026-09 页面审计事故之后的产物：
 *    报告里必须引用状态码/抓取规则/页面结构/结构化数据这类**实测项**才算有证据。
 *    搬动它们时别顺手放宽阈值 —— 放宽等于让"凭空写的报告"重新通过质量门。
 */
class SkillQualityReporter
{
    public function report(
        string $content,
        SkillCategory $category,
        bool $jsonParsed,
        bool $expectsJson,
    ): array {
        $score = $this->evaluateQuality($content, $category);

        $checks = [
            ['name' => '内容长度', 'passed' => mb_strlen($content) > 200],
            ['name' => '结构化程度', 'passed' => str_contains($content, '#') || str_contains($content, '{')],
        ];

        if ($category === SkillCategory::GeoPageTechnical) {
            $checks[] = [
                'name' => '页面实测证据',
                'passed' => $this->hasEvidenceReference($content, $category),
                'note' => '检查报告是否引用状态码、抓取规则、页面结构或结构化数据等实测项',
            ];
        } else {
            $checks[] = [
                'name' => '数据引用',
                'passed' => $this->hasEvidenceReference($content, $category),
            ];
        }

        if ($expectsJson) {
            $checks[] = [
                'name' => 'JSON 解析',
                'passed' => $jsonParsed,
                'note' => $jsonParsed ? '成功解析为结构化 JSON' : '未检测到有效 JSON',
            ];
        }

        return [
            'passed' => $score >= 50,
            'score' => $score,
            'checks' => $checks,
        ];
    }

    private function hasEvidenceReference(string $content, SkillCategory $category): bool
    {
        if ($category === SkillCategory::GeoPageTechnical) {
            return preg_match(
                '/(?:HTTP\s*\d{3}|状态码|robots\.txt|sitemap|canonical|JSON-LD|结构化数据|H[1-6]\b)/iu',
                $content,
            ) === 1;
        }

        // A percentage alone is an unsupported claim, not a citation. Content
        // can instead point readers to a traceable source URL or DOI.
        return preg_match('~(?:https?://[^\s<>()]+|\bdoi:\s*10\.\d{4,9}/\S+)~iu', $content) === 1;
    }

    public function evaluateQuality(string $content, SkillCategory $category): int
    {
        $score = 50;

        $len = mb_strlen($content);
        if ($len > 500) {
            $score += 10;
        }
        if ($len > 1500) {
            $score += 10;
        }
        if ($len > 3000) {
            $score += 5;
        }

        if (preg_match('/^##?\s+/m', $content)) {
            $score += 10;
        }
        if (preg_match('/[-*]\s+/', $content)) {
            $score += 5;
        }
        if (str_contains($content, '|')) {
            $score += 5;
        }
        if ($this->hasEvidenceReference($content, $category)) {
            $score += 5;
        }
        if (str_starts_with(trim($content), '{')) {
            $score += 10;
        }

        return min($score, 100);
    }
}
