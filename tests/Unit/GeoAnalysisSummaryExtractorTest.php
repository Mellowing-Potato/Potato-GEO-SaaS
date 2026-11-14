<?php

namespace Tests\Unit;

use App\Services\DTOs\SkillExecutionResult;
use App\Services\Geo\GeoAnalysisSummaryExtractor;
use PHPUnit\Framework\TestCase;

class GeoAnalysisSummaryExtractorTest extends TestCase
{
    public function test_extract_handles_null_intermediate_data_without_error(): void
    {
        $result = new SkillExecutionResult(
            executionId: 1,
            skillId: 'yao-geo-panorama-audit',
            success: true,
            intermediateData: null,
        );

        $summary = (new GeoAnalysisSummaryExtractor())->extract(null, $result);

        $this->assertSame('', $summary['summary']);
        $this->assertSame([], $summary['key_findings']);
        $this->assertSame([], $summary['recommendations']);
        $this->assertSame([], $summary['next_actions']);
        $this->assertNull($summary['overall_score']);
    }
}
