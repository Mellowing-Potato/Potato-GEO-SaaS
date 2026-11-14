<?php

namespace Tests\Unit;

use App\Services\Geo\AiVisibility\AiVisibilityResultNormalizer;
use App\Services\Geo\AiVisibility\ArkPublishTimeParser;
use PHPUnit\Framework\TestCase;

/**
 * AiVisibilityResultNormalizer（批次二 · 单 3）。
 *
 * 载荷形态照抄 spike 执行书 §10 实录（字段名不猜）：
 * output 交替数组 + 信源在 message.content[0].annotations + usage.tool_usage.web_search。
 */
final class AiVisibilityResultNormalizerTest extends TestCase
{
    private AiVisibilityResultNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AiVisibilityResultNormalizer(new ArkPublishTimeParser);
    }

    /** spike §10 实录的载荷形态（压缩为一信源）。 */
    private function spikePayload(array $overrides = []): array
    {
        $payload = [
            'id' => 'resp_001',
            'status' => 'completed',
            'model' => 'doubao-seed-evolving',
            'output' => [
                ['type' => 'reasoning', 'summary' => '思考过程'],
                [
                    'type' => 'web_search_call',
                    'action' => ['type' => 'search', 'query' => 'GEO 服务公司'],
                    'status' => 'success',
                ],
                [
                    'type' => 'message',
                    'content' => [
                        [
                            'type' => 'output_text',
                            'text' => '回答正文，见 [1]。',
                            'annotations' => [
                                [
                                    'type' => 'url_citation',
                                    'title' => '国内GEO服务商推荐指南',
                                    'url' => 'https://www.ithome.com/0/993/806.htm',
                                    'site_name' => 'IT之家',
                                    'publish_time' => '2026年08月25日 00:00:00(CST) 星期二',
                                    'summary' => str_repeat('整段正文摘录', 100),
                                    'logo_url' => 'https://p3-volcsearch-sign.byteimg.com/abc?x-expires=1850788828',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => [
                'input_tokens' => 25171,
                'input_tokens_details' => ['cached_tokens' => 11888],
                'output_tokens' => 2184,
                'total_tokens' => 27355,
                'tool_usage' => ['web_search' => 23],
            ],
        ];

        return array_replace_recursive($payload, $overrides);
    }

    public function test_maps_spike_payload_to_result(): void
    {
        $result = $this->normalizer->normalize(
            '问题',
            'doubao',
            'doubao-seed-evolving',
            $this->spikePayload(),
            50.0,
        );

        $this->assertTrue($result->success);
        $this->assertSame('回答正文，见 [1]。', $result->answerText);
        $this->assertSame(['GEO 服务公司'], $result->searchQueries);
        $this->assertSame(25171, $result->promptTokens);
        $this->assertSame(2184, $result->completionTokens);
        $this->assertSame(11888, $result->cachedTokens);
        $this->assertSame(1, $result->searchCallCount);
    }

    public function test_maps_annotation_to_14_field_source(): void
    {
        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $this->spikePayload(), 50.0);

        $this->assertCount(1, $result->sources);
        $source = $result->sources[0];

        // 6 直接映射 + 4 派生。
        $this->assertSame('url_citation', $source->sourceType);
        $this->assertSame('[1]', $source->citationKey);
        $this->assertSame('国内GEO服务商推荐指南', $source->title);
        $this->assertSame('https://www.ithome.com/0/993/806.htm', $source->url);
        $this->assertSame('www.ithome.com', $source->domain);
        $this->assertSame('IT之家', $source->siteName);
        $this->assertSame(200, mb_strlen((string) $source->snippet));
        $this->assertNotNull($source->publishedAt);
        $this->assertSame(2026, $source->publishedAt->year);
        $this->assertSame(1, $source->rank);
        // 4 留空字段不假造。
        $this->assertNull($source->rankScore);
        $this->assertNull($source->authorityLevel);
        $this->assertNull($source->contentExcerpt);
    }

    public function test_duplicate_annotation_urls_are_stored_once(): void
    {
        $payload = $this->spikePayload();
        $annotation = $payload['output'][2]['content'][0]['annotations'][0];
        $duplicateWithFragment = $annotation;
        $duplicateWithFragment['url'] .= '#section';
        $payload['output'][2]['content'][0]['annotations'][] = $annotation;
        $payload['output'][2]['content'][0]['annotations'][] = $duplicateWithFragment;

        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $payload, 50.0);

        $this->assertCount(1, $result->sources);
        $this->assertSame('[1]', $result->sources[0]->citationKey);
    }

    public function test_logo_url_only_lands_in_metadata_as_temporary(): void
    {
        // 单 3 硬要求 2：logo_url 是临时签名链接，不能当长期图片地址存储。
        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $this->spikePayload(), 50.0);
        $metadata = $result->sources[0]->metadata;

        $this->assertSame('https://p3-volcsearch-sign.byteimg.com/abc?x-expires=1850788828', $metadata['logo_url_temporary']);
        $this->assertArrayNotHasKey('logo_url', $metadata);
        // 落库行也不含 logo_url 永久列。
        $this->assertArrayNotHasKey('logo_url', $result->sources[0]->toRow());
    }

    public function test_malformed_publish_time_degrades_to_null_not_failure(): void
    {
        // 判据 V3：畸形日期 → null，run 不挂。
        $payload = $this->spikePayload();
        $payload['output'][2]['content'][0]['annotations'][0]['publish_time'] = '完全不是日期';

        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $payload, 50.0);

        $this->assertTrue($result->success);
        $this->assertNull($result->sources[0]->publishedAt);
        $this->assertSame('完全不是日期', $result->sources[0]->metadata['publish_time_raw']);
    }

    public function test_non_completed_status_is_failure(): void
    {
        $payload = $this->spikePayload(['status' => 'incomplete']);

        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $payload, 10.0);

        $this->assertFalse($result->success);
        $this->assertSame('上游未完成本次请求。', $result->errorMessage);
        $this->assertSame('incomplete', $result->finishReason);
        $this->assertSame(25171, $result->promptTokens);
        $this->assertSame(2184, $result->completionTokens);
        $this->assertNull($result->answerText);
    }

    public function test_missing_tool_usage_counts_observed_blocks_without_estimation(): void
    {
        // F1 修复：tool_usage 缺失时不再静默退回 web_search_call 块计数（差约 8 倍），
        // 改用带 estimated 标注的每问均值，并标记 searchCountEstimated。
        $payload = $this->spikePayload();
        unset($payload['usage']['tool_usage']);

        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $payload, 10.0);

        $this->assertSame(1, $result->searchCallCount);
        $this->assertFalse($result->searchCountEstimated);
    }

    public function test_present_but_zero_search_count_stays_zero(): void
    {
        // tool_usage 明确给出 web_search=0（模型未联网搜索）→ 真实 0，不估算。
        $payload = $this->spikePayload();
        $payload['usage']['tool_usage'] = ['web_search' => 0];
        $payload['output'] = array_values(array_filter($payload['output'], fn ($item) => $item['type'] !== 'web_search_call'));

        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $payload, 10.0);

        $this->assertSame(0, $result->searchCallCount);
        $this->assertFalse($result->searchCountEstimated);
    }

    public function test_usage_alias_cannot_override_observed_search_calls(): void
    {
        // 扩别名防漏读：某些响应把字段写作 web_search_calls。
        $payload = $this->spikePayload();
        unset($payload['usage']['tool_usage']);
        $payload['usage']['tool_usage'] = ['web_search_calls' => 23];

        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $payload, 50.0);

        $this->assertSame(1, $result->searchCallCount);
        $this->assertFalse($result->searchCountEstimated);
    }

    public function test_missing_usage_is_explicit_and_does_not_estimate_tokens(): void
    {
        $payload = $this->spikePayload();
        unset($payload['usage']);
        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $payload, 10.0);
        $this->assertTrue($result->success);
        $this->assertFalse($result->gateway['usage_available']);
        $this->assertSame(1, $result->searchCallCount);
        $this->assertFalse($result->searchCountEstimated);
    }

    public function test_extra_wrapper_cannot_supply_prices_or_replace_provider(): void
    {
        $payload = $this->spikePayload();
        $payload['gateway'] = ['cost' => ['estimated_cost_fen' => 24.5], 'served_provider' => 'private'];
        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $payload, 50.0);
        $this->assertSame('doubao', $result->provider);
        $this->assertArrayNotHasKey('cost', $result->gateway);
        $this->assertFalse(method_exists($result, 'costBreakdown'));
    }

    public function test_zero_source_payload_still_succeeds_with_health_signal(): void
    {
        // 信源为空不算失败（健康检查另行告警），正文缺失才算失败。
        $payload = $this->spikePayload();
        $payload['output'][2]['content'][0]['annotations'] = [];

        $result = $this->normalizer->normalize('问题', 'doubao', 'doubao-seed-evolving', $payload, 50.0);

        $this->assertTrue($result->success);
        $this->assertSame([], $result->sources);
    }
}
