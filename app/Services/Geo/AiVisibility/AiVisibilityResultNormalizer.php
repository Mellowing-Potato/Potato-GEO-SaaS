<?php

namespace App\Services\Geo\AiVisibility;

/**
 * 豆包 Responses API 返回体 → AiVisibilityResult 归一化（批次二 · 单 3）。
 *
 * 契约来源：spike 执行书 §10（2026-08-26 实打），字段名照抄不猜：
 *  - output 是交替数组：reasoning / web_search_call / message；
 *  - 正文与信源在 type=message 项的 content[0]（output_text + annotations）；
 *  - 搜索词在每个 web_search_call 的 action.query；
 *  - 搜索次数按 output 中实际 web_search_call 数量统计；
 *  - token 用量走 OpenAI Responses 风格（input_tokens / output_tokens /
 *    input_tokens_details.cached_tokens），兼容 prompt_tokens/completion_tokens 写法。
 */
final class AiVisibilityResultNormalizer
{
    public function __construct(
        private readonly ArkPublishTimeParser $publishTimeParser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  上游 JSON 解码结果
     */
    public function normalize(string $question, string $provider, string $model, array $payload, float $durationSeconds): AiVisibilityResult
    {
        $usage = $payload['usage'] ?? [];
        $input = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
        $outputTokens = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? null;
        $usageAvailable = is_int($input) && $input >= 0 && is_int($outputTokens) && $outputTokens >= 0;
        $status = (string) ($payload['status'] ?? '');
        $output = $payload['output'] ?? null;
        if (! is_array($output)) {
            return AiVisibilityResult::failure($question, $provider, $model, '上游返回缺少 output 结构');
        }

        [$answerText, $annotations] = $this->extractAnswerAndAnnotations($output);
        $searchQueries = $this->extractSearchQueries($output);
        [$promptTokens, $completionTokens, $cachedTokens, $searchCallCount, $searchCountEstimated] = $this->extractUsage($payload, $searchQueries);

        if (($status !== '' && $status !== 'completed') || $answerText === '') {
            // A supplier call can consume searches and tokens while ending
            // incomplete or returning an empty message. Keep observed usage for the
            // run detail, without treating the response as a billable answer.
            return new AiVisibilityResult(
                success: false,
                question: $question,
                provider: $provider,
                model: $model,
                searchQueries: $searchQueries,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                cachedTokens: $cachedTokens,
                searchCallCount: $searchCallCount,
                searchCountEstimated: $searchCountEstimated,
                durationSeconds: $durationSeconds,
                errorMessage: $status !== '' && $status !== 'completed'
                    ? (data_get($payload, 'incomplete_details.reason') === 'length'
                        ? '上游回答达到长度上限，未生成完整结果。'
                        : '上游未完成本次请求。')
                    : '上游返回没有可读正文',
                finishReason: $status !== '' ? $status : null,
                gateway: ['usage_available' => $usageAvailable],
            );
        }

        $sources = $this->buildSources($annotations);

        return new AiVisibilityResult(
            gateway: ['usage_available' => $usageAvailable],
            success: true,
            question: $question,
            provider: $provider,
            model: $model,
            answerText: $answerText,
            sources: $sources,
            searchQueries: $searchQueries,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            cachedTokens: $cachedTokens,
            searchCallCount: $searchCallCount,
            searchCountEstimated: $searchCountEstimated,
            durationSeconds: $durationSeconds,
            finishReason: $status !== '' ? $status : null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $output
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function extractAnswerAndAnnotations(array $output): array
    {
        $texts = [];
        $annotations = [];

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            $content = $item['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (! is_array($block) || ($block['type'] ?? null) !== 'output_text') {
                    continue;
                }
                $text = $block['text'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $texts[] = $text;
                }
                $blockAnnotations = $block['annotations'] ?? null;
                if (is_array($blockAnnotations)) {
                    foreach ($blockAnnotations as $annotation) {
                        if (is_array($annotation)) {
                            $annotations[] = $annotation;
                        }
                    }
                }
            }
        }

        return [implode("\n\n", $texts), $annotations];
    }

    /**
     * @param  list<array<string, mixed>>  $output
     * @return list<string>
     */
    private function extractSearchQueries(array $output): array
    {
        $queries = [];
        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'web_search_call') {
                continue;
            }
            $action = $item['action'] ?? null;
            $query = is_array($action) ? ($action['query'] ?? null) : null;
            if (is_string($query) && trim($query) !== '') {
                $queries[] = $query;
            }
        }

        return $queries;
    }

    /**
     * 用量提取（防御性多 key 兜底）。
     *
     * @param  list<string>  $searchQueries
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function extractUsage(array $payload, array $searchQueries): array
    {
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        $promptTokens = $this->int($usage, ['input_tokens', 'prompt_tokens']);
        $completionTokens = $this->int($usage, ['output_tokens', 'completion_tokens']);

        $cachedTokens = $this->int($usage, ['cached_tokens']);
        if ($cachedTokens === 0) {
            $cachedTokens = max(
                $this->int($usage, ['input_tokens_details.cached_tokens']),
                $this->int($usage, ['prompt_tokens_details.cached_tokens']),
            );
        }

        // Count observed tool calls, not estimated provider search fees.
        // https://docs.volcengine.com/docs/ark/web-search?lang=zh
        $searchCallCount = count(array_filter($payload['output'] ?? [],
            static fn ($item): bool => is_array($item) && ($item['type'] ?? null) === 'web_search_call'));
        $searchCountEstimated = false;

        return [$promptTokens, $completionTokens, $cachedTokens, $searchCallCount, $searchCountEstimated];
    }

    /**
     * annotations → 14 字段 SourceData（计划书 §3.3 映射表）。
     *
     * @param  list<array<string, mixed>>  $annotations
     * @return list<AiVisibilitySourceData>
     */
    private function buildSources(array $annotations): array
    {
        $sources = [];
        $seenUrls = [];
        $index = 0;

        foreach ($annotations as $annotation) {
            $url = trim((string) ($annotation['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            // Responses API 可能在多个 output_text 块重复返回同一条 annotation。
            // 一条检索结果只应保存一次；否则既误导用户，也会放大报告里的“信源数”。
            $urlKey = $this->sourceUrlKey($url);
            if (isset($seenUrls[$urlKey])) {
                continue;
            }
            $seenUrls[$urlKey] = true;

            $index++;
            $summary = isset($annotation['summary']) && is_string($annotation['summary'])
                ? $annotation['summary']
                : null;
            $rawPublishTime = isset($annotation['publish_time']) && is_string($annotation['publish_time'])
                ? $annotation['publish_time']
                : null;

            $metadata = [];
            // ⚠️ logo_url 是带 x-expires 的临时签名链接（spike 实测 x-expires=1850788828），
            //    不能存库当长期图片地址——只进 metadata，且明确命名其为 temporary。
            if (isset($annotation['logo_url']) && is_string($annotation['logo_url']) && $annotation['logo_url'] !== '') {
                $metadata['logo_url_temporary'] = $annotation['logo_url'];
            }
            if ($rawPublishTime !== null && $rawPublishTime !== '') {
                $metadata['publish_time_raw'] = $rawPublishTime;
            }

            $sources[] = new AiVisibilitySourceData(
                sourceType: 'url_citation',
                citationKey: '['.$index.']',
                title: isset($annotation['title']) && is_string($annotation['title']) ? $annotation['title'] : null,
                url: $url,
                domain: $this->domainOf($url),
                siteName: isset($annotation['site_name']) && is_string($annotation['site_name']) ? $annotation['site_name'] : null,
                snippet: $summary !== null ? mb_substr($summary, 0, 200) : null,
                summary: $summary,
                contentExcerpt: null,
                // 硬要求 1：解析失败降级 null，绝不让整条 run 挂掉。
                publishedAt: $this->publishTimeParser->parse($rawPublishTime),
                rank: $index,
                rankScore: null,
                authorityLevel: null,
                metadata: $metadata,
            );
        }

        return $sources;
    }

    private function sourceUrlKey(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        // fragment 不会改变服务端返回的页面内容，不应制造第二条结果。
        return $scheme.'://'.$host.$port.$path.$query;
    }

    private function domainOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return mb_substr(strtolower($host), 0, 255);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys  支持 "a.b" 点路径
     */
    private function int(array $source, array $keys): int
    {
        foreach ($keys as $key) {
            $value = $source;
            foreach (explode('.', $key) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;

                    break;
                }
                $value = $value[$segment];
            }
            if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
                return (int) $value;
            }
        }

        return 0;
    }

    private function hasAnyKey(array $source, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                return true;
            }
        }

        return false;
    }
}
