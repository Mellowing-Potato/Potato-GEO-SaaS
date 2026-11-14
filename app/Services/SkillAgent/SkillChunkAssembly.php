<?php

namespace App\Services\SkillAgent;

use App\Support\CompletedJsonObject;

/**
 * 分节数据装配（计划 §7 第 3 步：把 SkillAgentRunnerService 按职责拆小，「执行」里的分节装配）。
 *
 * 一次分节调用回来之后要走四步，这四步原来散在执行引擎里：
 *   1. extractJson()    —— 把模型回包（可能带说明文字、可能带代码围栏）抠成数组；
 *   2. merge()          —— 把本节认领的字段并进总结果（空值不许覆盖已有内容）；
 *   3. fieldIssues()    —— 本节到底交出哪些字段？空节、意图挖掘的单字段粒度都在这里判；
 *   4. repairTasks()    —— 按谱的粒度决定"补跑整节"还是"补跑单字段"（markdown 谱没有字段粒度）。
 *
 * 纯函数 + 一个只读依赖（RenderSchemaRegistry 拿 minimums）。
 * 行为由 tests/Feature/SkillChunkAssemblyTest.php 的指纹与断言冻结。
 */
class SkillChunkAssembly
{
    private readonly RenderSchemaRegistry $renderSchemaRegistry;

    public function __construct(?RenderSchemaRegistry $renderSchemaRegistry = null)
    {
        $this->renderSchemaRegistry = $renderSchemaRegistry ?? new RenderSchemaRegistry;
    }

    /**
     * 把「这段缺了什么」翻成具体的补跑任务。
     *
     * 补跑切多细，取决于这个技能的验收口径能分辨到多细 —— 切得比验收还细就是白花钱：
     *
     *   意图挖掘：chunkFieldIssues 逐字段验收，能准确说出缺哪几个字段
     *             → 缺几个补几个，一个字段一次调用，已交的字段不重跑。
     *
     *   其余分段技能：验收口径是「这段至少交出一个字段」（见 chunkFieldIssues），
     *             分辨不到字段级，$issues 要么空要么是整段字段。此时：
     *             · 被截断  → 仍然按字段拆开重来。拆小本来就是截断的正解，
     *                        单个字段的输出比整段短得多，不容易再撞上限。
     *             · 没被截断（坏 JSON／空回复）→ 整段重来一次，只花一次预算。
     *
     * 最后这条不是省事，是算过的：执行路线图第一段有 8 个字段，逐字段补要 8 次调用，
     * 超过默认预算 6，会触发「预算不够就整批放弃」——一次都不补，等于这个机制对它不存在。
     * 整段重来一次既在预算内，也正好对得上它「整段成败」的验收口径。
     *
     * @param  list<string>  $issues
     * @return list<array{parent: array, spec: array, recovery: bool}>
     */
    public function repairTasks(string $skillId, array $parent, string $key, array $issues, bool $truncated): array
    {
        // 分节谱没有字段粒度，一节的成败就是整节的成败 → 补跑＝把这一节整个重写一遍。
        //
        // ⚠️ 必须 array_merge($parent, ...) 而不是自己拼一个新 spec：补跑任务要原样继承
        //    format / modules / lead / target_chars。少继承一个 format，重写那一节就会
        //    掉回 JSON 模式，写出来的东西直接毁掉整份报告。
        if (SkillPromptBuilder::isMarkdownSpec($parent)) {
            return [[
                'parent' => $parent,
                'spec' => array_merge($parent, [
                    'key' => $key.'.retry',
                    'label' => $parent['label'].' · 重写',
                    // 被截断说明这一节写超了。重写时把篇幅目标压到七成，
                    // 与其写一半被切断，不如写短一点但写完。
                    'target_chars' => $truncated
                        ? (int) round(($parent['target_chars'] ?? 3000) * 0.7)
                        : ($parent['target_chars'] ?? null),
                    'retry_truncated' => $truncated,
                ]),
                'recovery' => true,
            ]];
        }

        $perField = $truncated || str_contains($skillId, 'intent-miner')
            || (str_contains($skillId, 'explainer-builder') && $issues === ['core_answer']);

        if (! $perField) {
            return [[
                'parent' => $parent,
                'spec' => ['key' => $key.'.retry', 'label' => $parent['label'].' · 重试', 'fields' => $issues],
                'recovery' => true,
            ]];
        }

        return array_map(fn (string $field): array => [
            'parent' => $parent,
            'spec' => ['key' => $key.'.'.$field, 'label' => $parent['label'].' · '.$field, 'fields' => [$field]],
            'recovery' => true,
        ], $issues);
    }

    /**
     * 在补默认值、补模板行之前检查交付内容。意图挖掘的 21 个字段逐一验收，
     * 来源可显式为空（没有采集就不能编造），问题库等核心产物不可为空或少于 schema 最小行数。
     * 其他技能保持既有字段口径，避免借本次故障改变它们的可选字段契约。
     *
     * @return list<string>
     */
    public function fieldIssues(string $skillId, array $data, array $fields): array
    {
        if (! str_contains($skillId, 'intent-miner')) {
            if ($this->countClaimed($data, $fields) === 0) {
                return $fields;
            }
            if (str_contains($skillId, 'explainer-builder') && in_array('core_answer', $fields, true)) {
                $answer = $data['core_answer'] ?? null;
                $length = is_string($answer) ? mb_strlen(preg_replace('/\s+/u', '', $answer) ?? '') : 0;
                if ($length < 80 || $length > 120) {
                    return ['core_answer'];
                }
            }

            return [];
        }

        $minimums = $this->renderSchemaRegistry->getSchema($skillId)['minimums'] ?? [];
        $contentKeys = ['question_bank' => 'question', 'follow_up_chains' => 'follow_up_question', 'prompt_library' => 'prompt'];
        $textFields = ['slug', 'title', 'subtitle', 'brand_name', 'analysis_date', 'prepared_by', 'limitations'];
        $allowEmpty = ['slug', 'subtitle', 'prepared_by', 'limitations', 'data_sources', 'evidence_sources'];
        $issues = [];
        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            if (in_array($field, $textFields, true)) {
                $valid = is_string($value) && (trim($value) !== '' || in_array($field, $allowEmpty, true));
            } else {
                $valid = is_array($value)
                    && count($value) >= ($minimums[$field] ?? (in_array($field, $allowEmpty, true) ? 0 : 1));
                if ($valid && isset($contentKeys[$field])) {
                    foreach ($value as $row) {
                        $content = is_array($row) ? ($row[$contentKeys[$field]] ?? null) : null;
                        if (! is_string($content) || trim($content) === '') {
                            $valid = false;
                            break;
                        }
                    }
                }
                if ($valid && $field === 'sections') {
                    $sectionText = implode(' ', array_map(
                        static fn (mixed $section): string => is_array($section)
                            ? (string) ($section['title'] ?? '')
                            : '',
                        $value,
                    ));
                    $normalizedSectionText = $this->normalizeQualityKeyword($sectionText);
                    foreach ($this->renderSchemaRegistry->requiredSectionKeywords($skillId) as $keyword) {
                        if (! str_contains($normalizedSectionText, $this->normalizeQualityKeyword($keyword))) {
                            $valid = false;
                            break;
                        }
                    }
                }
            }
            if (! $valid) {
                $issues[] = $field;
            }
        }

        return $issues;
    }

    /**
     * A complete lead sentence can already satisfy the short-answer contract.
     * Keep that sentence verbatim instead of paying for a rewrite that may
     * repeat the same overlong answer. Never cut through a sentence.
     */
    public function useCompleteCoreAnswerSentence(array $data): array
    {
        $answer = $data['core_answer'] ?? null;
        if (! is_string($answer)) {
            return $data;
        }

        $length = mb_strlen(preg_replace('/\s+/u', '', $answer) ?? '');
        if ($length <= 120 || ! preg_match('/^(.+?[。！？])(?=\s*\S)/us', trim($answer), $match)) {
            return $data;
        }

        $lead = trim($match[1]);
        $leadLength = mb_strlen(preg_replace('/\s+/u', '', $lead) ?? '');
        if ($leadLength >= 80 && $leadLength <= 120) {
            $data['core_answer'] = $lead;
        }

        return $data;
    }

    private function normalizeQualityKeyword(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', trim($value)));
    }

    /**
     * 数一段真正认领了几个自有字段。判空口径必须与 mergeChunk 完全一致：
     * 这里数 N 个、mergeChunk 就得并进去 N 个，否则「这段成功了」与「这段并进去了东西」
     * 会对不上，完整性闸放行的报告仍可能缺内容。改一处必须改另一处。
     */
    public function countClaimed(array $chunkData, array $fields): int
    {
        $count = 0;
        foreach ($fields as $field) {
            if (! array_key_exists($field, $chunkData)) {
                continue;
            }
            $value = $chunkData[$field];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * 合并单段结果：只认领本段声明的字段，且仅当非空，杜绝跨段污染 / 覆盖。
     */
    public function merge(array $merged, array $chunkData, array $fields): array
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $chunkData)) {
                continue;
            }
            $value = $chunkData[$field];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $merged[$field] = $value;
        }

        return $merged;
    }

    /**
     * 从 LLM 输出中提取 JSON 数据
     */
    public function extractJson(string $content, bool $completed = false): ?array
    {
        $candidates = [];
        // 方法1: 从 ```json ... ``` 代码块提取
        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $content, $m)) {
            $candidates[] = $m[1];
            $decoded = json_decode($m[1], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            // 尝试修复常见问题：尾部逗号
            $fixed = preg_replace('/,\s*([}\]])/', '$1', $m[1]);
            $decoded = json_decode($fixed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // 方法2: 尝试直接解析整个内容为 JSON
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // 方法3: 查找第一个 { 到最后一个 } 之间的内容
        $firstBrace = strpos($content, '{');
        $lastBrace = strrpos($content, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $jsonStr = substr($content, $firstBrace, $lastBrace - $firstBrace + 1);
            $candidates[] = $jsonStr;
            $decoded = json_decode($jsonStr, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        if ($completed) {
            foreach (array_unique($candidates) as $candidate) {
                $repaired = CompletedJsonObject::decode($candidate);
                if ($repaired !== null) {
                    return $repaired;
                }
            }
        }

        return null;
    }
}
