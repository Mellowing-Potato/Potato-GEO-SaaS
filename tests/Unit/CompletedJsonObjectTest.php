<?php

namespace Tests\Unit;

use App\Services\SkillAgent\SkillChunkAssembly;
use App\Support\CompletedJsonObject;
use PHPUnit\Framework\TestCase;

class CompletedJsonObjectTest extends TestCase
{
    public function test_one_missing_array_closer_is_recovered_only_when_completed(): void
    {
        $text = '中文引用带有 } 和 \"]\" 字样';
        $broken = '{"example":{"paragraphs":['.json_encode($text, JSON_UNESCAPED_UNICODE).'}}';
        $expected = ['example' => ['paragraphs' => [$text]]];
        $assembly = new SkillChunkAssembly;
        $this->assertSame($expected, CompletedJsonObject::decode($broken));
        $this->assertSame($expected, $assembly->extractJson("```json\n{$broken}\n```", true));
        $this->assertNull($assembly->extractJson($broken));
        $this->assertNull($assembly->extractJson($broken, false));
        $this->assertSame(['a' => [1, 2]], $assembly->extractJson('{"a":[1,2]}'));
    }

    public function test_truncation_ambiguity_and_scalar_are_rejected(): void
    {
        $assembly = new SkillChunkAssembly;
        foreach (['{"a":["unfinished', '{"a":[1', '{"a":1 "b":2}',
            '{"a":[1,}}', '{"a":[1},"b":[2}', '{"a":[1]', '"scalar"',
        ] as $json) {
            $this->assertNull($assembly->extractJson($json, true), $json);
        }
    }
}
