<?php

namespace Tests\Unit;

use App\Services\Geo\AiVisibility\ArkPublishTimeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ArkPublishTimeParser（批次二 · 单 3 硬要求 1）。
 *
 * 判据 V3：中文日期解析成功；解析失败降级 null 而不是抛异常。
 */
final class ArkPublishTimeParserTest extends TestCase
{
    private ArkPublishTimeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ArkPublishTimeParser;
    }

    public static function validProvider(): array
    {
        return [
            // spike 实录格式（执行书 §10）。
            'spike full format' => ['2026年08月25日 00:00:00(CST) 星期二', [2026, 8, 25, 0, 0, 0]],
            'chinese without time' => ['2026年8月5日', [2026, 8, 5, 0, 0, 0]],
            'chinese with time only' => ['2025年12月31日 23:59:59', [2025, 12, 31, 23, 59, 59]],
            'iso space' => ['2026-08-25 00:00:00', [2026, 8, 25, 0, 0, 0]],
            'iso t' => ['2026-08-25T14:30:00+08:00', [2026, 8, 25, 14, 30, 0]],
        ];
    }

    #[DataProvider('validProvider')]
    public function test_parses_valid_formats(string $value, array $expected): void
    {
        $result = $this->parser->parse($value);

        $this->assertNotNull($result, "failed to parse: {$value}");
        $this->assertSame($expected[0], $result->year);
        $this->assertSame($expected[1], $result->month);
        $this->assertSame($expected[2], $result->day);
        $this->assertSame($expected[3], $result->hour);
        $this->assertSame($expected[4], $result->minute);
        $this->assertSame($expected[5], $result->second);
    }

    public static function invalidProvider(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'whitespace' => ["   \t\n"],
            'garbage' => ['not a date at all'],
            'year only' => ['2026年'],
            'impossible month' => ['2026年13月01日 00:00:00'],
            'impossible day' => ['2026年02月30日 00:00:00'],
            'impossible hour' => ['2026年08月25日 25:00:00'],
            'negative year' => ['1000年08月25日 00:00:00'],
            'month word' => ['Aug 25 2026'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_malformed_dates_degrade_to_null(?string $value): void
    {
        // 判据 V3 的第二半句：畸形输入绝不能让解析器抛异常。
        $result = $this->parser->parse($value);

        $this->assertNull($result);
    }
}
