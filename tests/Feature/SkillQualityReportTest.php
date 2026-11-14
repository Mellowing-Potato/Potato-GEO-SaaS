<?php

namespace Tests\Feature;

use App\Services\Enums\SkillCategory;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillQualityReporter;
use Tests\TestCase;

/**
 * 产出质量报告 · 特征化测试（计划 §7 第 3 步：`SkillAgentRunnerService` 按职责拆小，「质量」那一块）。
 *
 * 与提示词那份同一个套路：拆分**前**先对一组代表性输入取输出 sha256（下表的 FROZEN），
 * 拆分**后**必须逐字一致。质量报告是"这份内容能不能交付"的判据，改一个阈值就可能让
 * 凭空写的报告重新通过质量门（2026-09 页面审计事故的教训），所以用哈希钉死。
 *
 * 另外几条显式断言记录的是**判据本身**：短内容不给长度分、页面技术类必须有实测证据
 * （状态码/抓取规则/页面结构/结构化数据）才算过、JSON 期望与解析结果要进报告。
 */
class SkillQualityReportTest extends TestCase
{
    /** 拆分前实测冻结的指纹（对 report 结果的 json_encode 取 sha256）。 */
    private const FROZEN = [
        'short_json_missing' => 'f798ef88bc12dcdc7f35b86f9ee707a45c3bdec2aa332d4ee35950187617a440',
        'long_plain_ok' => 'fb028fd80a405b7c722b00963909d0eaef210a0568709e4ac9942f1ca0a46aaf',
        'geo_page_with_evidence' => '6b71d6db55570279b32a270b68f97740d0db754d013f78a78aa4228f8cfd81e5',
        'geo_page_without_evidence' => 'c5cb9a9b87af540f171aa3e8fbe9613f02870010b6c88478dad4a197c3a2b610',
    ];

    private function assertFrozen(string $key, array $payload): void
    {
        $this->assertSame(
            self::FROZEN[$key],
            hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE)),
            "{$key}：质量报告内容变了（阈值或被检查项被动过）",
        );
    }

    private function cases(): array
    {
        return [
            'short_json_missing' => [str_repeat('短内容。', 20), SkillCategory::GeoContentProduction, false, true],
            'long_plain_ok' => [str_repeat('这是一段普通内容，用于评分。', 60), SkillCategory::GeoContentProduction, true, false],
            'geo_page_with_evidence' => ["HTTP 200\ncanonical: https://example.test/\nJSON-LD 结构化数据齐备。".str_repeat('审计细节。', 60), SkillCategory::GeoPageTechnical, true, true],
            'geo_page_without_evidence' => [str_repeat('没有技术证据的段落。', 60), SkillCategory::GeoPageTechnical, true, false],
        ];
    }

    public function test_quality_reporter_keeps_its_frozen_reports(): void
    {
        $reporter = app(SkillQualityReporter::class);

        foreach ($this->cases() as $name => [$content, $category, $jsonParsed, $expectsJson]) {
            $payload = $reporter->report($content, $category, $jsonParsed, $expectsJson);
            $this->assertFrozen($name, $payload);
            $this->assertArrayHasKey('passed', $payload);
            $this->assertArrayHasKey('score', $payload);
            $this->assertArrayHasKey('checks', $payload);
        }
    }

    public function test_quality_thresholds_keep_their_meaning(): void
    {
        $reporter = app(SkillQualityReporter::class);

        // 短内容不给长度分，也不该因为"看着还行"就给高分。
        $this->assertSame(50, $reporter->evaluateQuality('短', SkillCategory::GeoContentProduction));
        $this->assertSame(70, $reporter->evaluateQuality(str_repeat('长内容。', 400), SkillCategory::GeoContentProduction));
        $this->assertSame(
            65,
            $reporter->evaluateQuality("HTTP 200 canonical sitemap".str_repeat('细节。', 200), SkillCategory::GeoPageTechnical),
        );

        // 页面技术类：没有实测证据的检查项必须判 false（这条是 2026-09 事故的直接产物）。
        $withEvidence = $reporter->report("HTTP 200\ncanonical: https://example.test/\nJSON-LD 结构化数据齐备。".str_repeat('细节。', 60), SkillCategory::GeoPageTechnical, true, true);
        $evidenceCheck = collect($withEvidence['checks'])->firstWhere('name', '页面实测证据');
        $this->assertNotNull($evidenceCheck);
        $this->assertTrue($evidenceCheck['passed']);

        $withoutEvidence = $reporter->report(str_repeat('没有技术证据的段落。', 60), SkillCategory::GeoPageTechnical, true, false);
        $evidenceCheck = collect($withoutEvidence['checks'])->firstWhere('name', '页面实测证据');
        $this->assertNotNull($evidenceCheck);
        $this->assertFalse($evidenceCheck['passed'], '没有实测证据时不能判通过');

        // JSON 期望与解析结果都要进报告：期望 JSON 却没解析出来时必须出现「未检测到有效 JSON」。
        $jsonCheck = collect($reporter->report(...$this->cases()['short_json_missing'])['checks'])
            ->firstWhere('name', 'JSON 解析');
        $this->assertNotNull($jsonCheck);
        $this->assertFalse($jsonCheck['passed']);
        $this->assertSame('未检测到有效 JSON', $jsonCheck['note'] ?? null);
    }

    public function test_content_source_check_accepts_traceable_links_but_not_bare_percentages(): void
    {
        $reporter = app(SkillQualityReporter::class);
        $withSource = $reporter->report(
            "# 产品说明\n\n官网来源：https://scholarclue.com/download.html",
            SkillCategory::GeoContentProduction,
            false,
            false,
        );
        $unsupportedMetric = $reporter->report(
            "# 产品说明\n\nAI 引用率提升 30%，没有来源。",
            SkillCategory::GeoContentProduction,
            false,
            false,
        );

        $this->assertTrue(collect($withSource['checks'])->firstWhere('name', '数据引用')['passed']);
        $this->assertFalse(collect($unsupportedMetric['checks'])->firstWhere('name', '数据引用')['passed']);
    }

    /** 服务侧同样可见（两个调用点 + 评分调用都走转发方法）。 */
    public function test_service_quality_entry_points_are_unchanged(): void
    {
        $service = app(SkillAgentRunnerService::class);
        $ref = new \ReflectionClass($service);

        $report = $ref->getMethod('buildQualityReport');
        $report->setAccessible(true);
        [$content, $category, $jsonParsed, $expectsJson] = $this->cases()['geo_page_with_evidence'];
        $this->assertFrozen('geo_page_with_evidence', $report->invoke($service, $content, $category, $jsonParsed, $expectsJson));

        $evaluate = $ref->getMethod('evaluateQuality');
        $evaluate->setAccessible(true);
        $this->assertSame(50, $evaluate->invoke($service, '短', SkillCategory::GeoContentProduction));
    }
}
