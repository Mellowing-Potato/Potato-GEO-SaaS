<?php

namespace App\Services\SkillAgent;

use App\Services\DTOs\SkillBrief;

/**
 * 交付内容组装（计划 §7 第 3 步：把 SkillAgentRunnerService 按职责拆小，「渲染/交付」这一块）。
 *
 * 模型产出之后、交给渲染脚本与文章库之前的那几件纯准备工作：
 *   prepareStructuredInput()  —— 补齐品牌字段、全景审计补齐全景输入、注入表头唯一权威；
 *   publishableArticleContent() —— 把 explainer 的结构化输出拼成可发布正文（标题 + 分节）；
 *   withPublishableChecks()   —— 给质量报告加上"正文 2000–4000 字"与"与审查一致"两条检查；
 *   assembleMarkdownReport()  —— 按谱的顺序把各节正文拼成一份报告，没写完的节要显式标注。
 *
 * 依赖：RenderSchemaRegistry（表头唯一权威）、PanoramaReportInputBuilder（全景输入补齐）。
 * 行为由 tests/Feature/SkillDeliveryAssemblerTest.php 的指纹与断言冻结。
 */
class SkillDeliveryAssembler
{
    private readonly RenderSchemaRegistry $renderSchemaRegistry;

    private readonly PanoramaReportInputBuilder $panoramaReportInputBuilder;

    public function __construct(
        ?RenderSchemaRegistry $renderSchemaRegistry = null,
        ?PanoramaReportInputBuilder $panoramaReportInputBuilder = null,
    ) {
        // ⚠️ 不要写成 promoted readonly 再在构造函数里赋值 —— 那会报
        //    "Cannot modify readonly property"（本桶第一版就是这么写错的，6 条断言当场红）。
        $this->renderSchemaRegistry = $renderSchemaRegistry ?? new RenderSchemaRegistry;
        $this->panoramaReportInputBuilder = $panoramaReportInputBuilder ?? new PanoramaReportInputBuilder;
    }

    /**
     * 把各节正文拼成一份报告。
     *
     * 按谱的顺序拼，不按写完的顺序 —— 补跑会打乱完成顺序，但章节顺序必须是固定的。
     */
    public function assembleMarkdownReport(array $specs, array $merged, array $partials = []): string
    {
        $parts = [];

        foreach ($specs as $spec) {
            $field = $spec['fields'][0] ?? null;
            $text = $field !== null ? trim((string) ($merged[$field] ?? '')) : '';
            if ($text !== '') {
                $parts[] = $text;

                continue;
            }

            // 这一节没写成。有半截就附上并标清楚，没有就整节跳过 ——
            // 绝不能不加标记地混进去，那会让客户以为这节本来就这么短。
            $partial = trim((string) ($partials[$spec['key'] ?? ''] ?? ''));
            if ($partial !== '') {
                $parts[] = "> ⚠️ 「{$spec['label']}」这一节没有写完，以下是已经写出来的部分。\n\n".$partial;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * 将品牌信息注入到 JSON 数据中（渲染脚本可能需要）
     */
    public function enrichWithBrandInfo(array $data, SkillBrief $brief): array
    {
        $brandName = $brief->field('brand_name', '');
        $brandUrl = $brief->field('brand_url', '');

        // 填充常见的品牌相关字段
        if ($brandName) {
            $data['brand_name'] = $data['brand_name'] ?? $brandName;
            $data['brand'] = $data['brand'] ?? $brandName;
        }
        if ($brandUrl) {
            $data['brand_url'] = $data['brand_url'] ?? $brandUrl;
            $data['website'] = $data['website'] ?? $brandUrl;
        }

        // 生成日期必须来自实际执行时间，不能沿用示例 JSON 或模型知识里的旧日期。
        $data['generated_at'] = now()->toISOString();
        $data['report_date'] = now()->format('Y-m-d');
        if ($brief->skillId === 'yao-geo-intent-miner') {
            // This field used to carry a date copied from the model template.
            // No sampling timestamp was recorded by this pipeline.
            $data['analysis_date'] = '';
            $data['sampling_date'] = null;
        } elseif (array_key_exists('analysis_date', $data)) {
            $data['analysis_date'] = now()->format('Y-m-d');
        }

        return $data;
    }

    /**
     * 渲染前统一整理结构化输入。
     *
     * 全景诊断的 Python 模板需要 15 个表格区域。真实模型或 mock provider
     * 有时只返回 summary / score / quick_wins 这类薄 JSON；这里用项目资料补成
     * 可读、可下载的 report_input，避免生成只有章节没有内容的空报告。
     */
    public function prepareStructuredInput(array $data, SkillBrief $brief): array
    {
        $data = $this->enrichWithBrandInfo($data, $brief);

        if ($brief->skillId === 'yao-geo-intent-miner') {
            $data = $this->reconcileIntentReport($data);
        }

        if ($brief->skillId === 'yao-geo-title-optimizer' && is_array($data['title_candidates'] ?? null)) {
            foreach ($data['title_candidates'] as $index => &$candidate) {
                if (is_array($candidate)) {
                    $id = $candidate['id'] ?? null;
                    $candidate['id'] = is_string($id) ? trim($id) : $id;
                    if ($candidate['id'] === null || $candidate['id'] === '') {
                        $candidate['id'] = 'T'.($index + 1);
                    }
                }
            }
            unset($candidate);
        }

        if (str_contains($brief->skillId, 'panorama-audit')) {
            $data = $this->panoramaReportInputBuilder->complete($data, $brief);
        }

        // EXEC-07 B4: 表头唯一权威注入 payload。
        // 只登记该报告里真实存在的表（array_intersect_key 以 $data 的键为准），
        // 不存在的键不写，避免前端渲染空表。Python 渲染脚本忽略该键（无严格 schema 校验）。
        $headers = $this->renderSchemaRegistry->tableHeaders($brief->skillId);
        if ($headers !== [] && is_array($data)) {
            $data['table_headers'] = array_intersect_key($headers, $data);
        }

        if (str_contains($brief->skillId, 'explainer-builder')) {
            // 渲染器用这些原始输入判断数字是否由用户明确提供；该字段只作审计和
            // 事实核验，不参与报告章节，也不会增加模型调用。
            $data['_user_provided_facts'] = $brief->auditableInputData();
            // 先补齐正文，再由渲染器统一改写和检查。覆盖模型同名字段，正文仍由正式结构决定。
            $data['publishable_article_markdown'] = $this->publishableArticleContent($brief->skillId, $data, '');
        }

        return $data;
    }

    private function reconcileIntentReport(array $data): array
    {
        $verified = is_array($data['question_bank'] ?? null) && array_is_list($data['question_bank'])
            && count(array_filter($data['question_bank'], 'is_array')) === count($data['question_bank']);
        $total = $verified ? count($data['question_bank']) : null;
        $p0 = $verified ? count(array_filter($data['question_bank'], static fn ($row): bool => is_array($row)
            && strtoupper(trim((string) ($row['priority'] ?? ''))) === 'P0')) : null;
        $data['report_integrity'] = $verified
            ? ['status' => 'verified', 'question_count' => $total, 'p0_count' => $p0,
                'basis' => 'complete_question_bank']
            : ['status' => 'pending', 'reason' => 'question_bank_unavailable'];
        $correctSummary = static function (string $text) use ($verified, $total, $p0): string {
            $text = preg_replace('/(?:共|总计|累计|形成|生成|产出|覆盖)\s*\d+\s*条(?:用户)?问题/u',
                $verified ? "共 {$total} 条问题（按完整清单核对）" : '问题总数待校验', $text);
            $text = preg_replace('/P0\s*(?:优先(?:级|意图)?)?\s*[：:]?\s*\d+\s*条/ui',
                $verified ? "P0 {$p0} 条（按完整清单核对）" : 'P0 数量待校验', $text);

            return str_replace('研究覆盖', '计划监测', $text);
        };
        foreach (['analysis_summary', 'summary', 'executive_summary', 'conclusion', 'subtitle'] as $key) {
            if (is_string($data[$key] ?? null)) $data[$key] = $correctSummary($data[$key]);
        }
        if (is_array($data['summary_cards'] ?? null)) {
            foreach ($data['summary_cards'] as &$card) {
                if (! is_array($card)) continue;
                $label = (string) ($card['label'] ?? $card['key'] ?? '');
                if (preg_match('/P0/i', $label)) {
                    $card['value'] = $verified ? $p0.' 条' : '待校验';
                    $card['note'] = $verified ? '按完整问题清单核对' : '完整问题清单待校验';
                } elseif (preg_match('/(?:问题|意图).*(?:总数|数量|清单|规模)/u', $label)) {
                    $card['value'] = $verified ? $total.' 条' : '待校验';
                    $card['note'] = $verified ? '按完整问题清单核对' : '完整问题清单待校验';
                }
            }
            unset($card);
        }
        if (is_array($data['sections'] ?? null)) {
            foreach ($data['sections'] as &$section) {
                if (! is_array($section) || ! preg_match('/摘要|速览|概览|结论/u', (string) ($section['title'] ?? ''))
                    || ! is_array($section['paragraphs'] ?? null)) continue;
                foreach ($section['paragraphs'] as &$paragraph) {
                    if (! is_string($paragraph)) continue;
                    $paragraph = $correctSummary($paragraph);
                }
                unset($paragraph);
            }
            unset($section);
        }
        if (is_array($data['ai_sampling_plan'] ?? null)) {
            foreach ($data['ai_sampling_plan'] as &$row) {
                if (is_array($row) && empty($row['observed_at'])) {
                    $row['status'] = '计划监测，尚未采样';
                }
            }
            unset($row);
        }

        return $data;
    }

    /**
     * 报告和文章是两件交付物。解释型技能的渲染 Markdown 包含研究矩阵、发布检查等
     * 编辑资料；文章库只保存 article_sections 里的正文，避免把整份报告当成文章发布。
     */
    public function publishableArticleContent(string $skillId, array $structured, string $fallback): string
    {
        if ($skillId === 'yao-geo-content-refiner') {
            $sections = $structured['refined_article'] ?? null;
            if (! is_array($sections) || $sections === []) {
                return '';
            }
            $lines = [];
            foreach ($sections as $section) {
                if (! is_array($section) || ! is_string($section['heading'] ?? null)
                    || trim($section['heading']) === '' || ! is_array($section['paragraphs'] ?? null)
                    || $section['paragraphs'] === []) {
                    return '';
                }
                $paragraphs = [];
                foreach ($section['paragraphs'] as $paragraph) {
                    if (! is_string($paragraph) || trim($paragraph) === '') {
                        return '';
                    }
                    $paragraphs[] = trim($paragraph);
                }
                $lines[] = ($lines === [] ? '# ' : '## ').trim($section['heading']);
                $lines[] = implode("\n\n", $paragraphs);
            }

            return implode("\n\n", $lines)."\n";
        }

        if (! str_contains($skillId, 'explainer-builder')) {
            return $fallback;
        }

        $title = trim((string) ($structured['title'] ?? ''));
        $sections = $structured['article_sections'] ?? null;
        if ($title === '' || ! is_array($sections) || $sections === []) {
            return $fallback;
        }

        $lines = ['# '.$title];
        $lead = trim((string) ($structured['core_answer'] ?? ''));
        if ($lead !== '') {
            $lines[] = '';
            $lines[] = $lead;
        }

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $heading = trim((string) ($section['heading'] ?? $section['title'] ?? ''));
            $paragraphs = $section['paragraphs'] ?? $section['body'] ?? $section['content'] ?? [];
            if (is_string($paragraphs)) {
                $paragraphs = [$paragraphs];
            }
            if ($heading === '' || ! is_array($paragraphs)) {
                continue;
            }
            $cleanParagraphs = array_values(array_filter(array_map(
                static fn ($paragraph): string => trim(is_scalar($paragraph) ? (string) $paragraph : ''),
                $paragraphs,
            )));
            if ($cleanParagraphs === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = '## '.$heading;
            foreach ($cleanParagraphs as $paragraph) {
                $lines[] = '';
                $lines[] = $paragraph;
            }
        }

        // 旧执行里有一批 article_sections 只达到渲染器的 1400 字底线，却没有达到
        // 技能卡承诺的 2000 字。已经生成好的 steps 是同一次模型调用的结果：正文不足时
        // 把它整理成可发布的检查清单，补足交付物，但不再调用模型、也不产生第二次扣点。
        $bodyChars = mb_strlen(preg_replace('/\s+/u', '', implode("\n", $lines)) ?? '');
        $steps = $structured['steps'] ?? [];
        if ($bodyChars < 2000 && is_array($steps) && $steps !== []) {
            $stepLines = [];
            foreach ($steps as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }
                $stepTitle = trim((string) ($step['title'] ?? ''));
                $action = trim((string) ($step['action'] ?? ''));
                $check = trim((string) ($step['check'] ?? ''));
                if ($stepTitle === '' || $action === '') {
                    continue;
                }
                $item = ($index + 1).'. **'.$stepTitle.'**：'.$action;
                if ($check !== '') {
                    $item .= ' 检查点：'.$check;
                }
                $stepLines[] = $item;
            }
            if ($stepLines !== []) {
                $lines[] = '';
                $lines[] = '## 实施检查清单';
                foreach ($stepLines as $item) {
                    $lines[] = '';
                    $lines[] = $item;
                }
            }
        }

        return count($lines) > 1 ? implode("\n", $lines)."\n" : $fallback;
    }

    /** @return list<string> */
    public function titleDeliveryIssues(array $data, ?RenderResult $renderResult): array
    {
        $issues = [];
        $candidates = $data['title_candidates'] ?? null;
        if (! is_array($candidates) || count($candidates) < 5 || count($candidates) > 8) {
            $issues[] = '候选标题必须为 5–8 个';
        } else {
            $ids = [];
            foreach ($candidates as $candidate) {
                if (! is_array($candidate) || ! is_string($candidate['id'] ?? null)
                    || trim($candidate['id']) === '' || ! is_string($candidate['title'] ?? null)
                    || trim($candidate['title']) === '') {
                    $issues[] = '候选标题缺少 ID 或标题';
                    continue;
                }
                $ids[] = trim($candidate['id']);
                $scores = $candidate['scores'] ?? null;
                foreach (['intent_match', 'entity_clarity', 'differentiation', 'citation_potential', 'compliance', 'freshness'] as $key) {
                    if (! is_array($scores) || ! isset($scores[$key]) || ! is_int($scores[$key]) && ! is_float($scores[$key])
                        || $scores[$key] < 0 || $scores[$key] > 5) {
                        $issues[] = '候选标题评分须为 0–5 的数字';
                        break;
                    }
                }
            }
            if (count($ids) !== count(array_unique($ids))) {
                $issues[] = '候选标题 ID 重复';
            }
            if (! in_array($data['recommended_id'] ?? null, $ids, true)) {
                $issues[] = '推荐 ID 未指向候选标题';
            }
        }
        if (! is_string($data['recommendation'] ?? null) || trim($data['recommendation']) === '') {
            $issues[] = '缺少推荐理由';
        }
        if (! is_array($data['usage_advice'] ?? null) || $data['usage_advice'] === []) {
            $issues[] = '缺少使用建议';
        }
        if (! $renderResult?->success) {
            $issues[] = '标题报告渲染失败';
        } else {
            foreach (['markdown', 'html', 'docx', 'pdf', 'quality-report'] as $format) {
                if (! isset($renderResult->renderedPaths[$format])) {
                    $issues[] = "缺少 {$format} 文件";
                }
            }
            if (($renderResult->qualityReport['passed'] ?? false) !== true) {
                $issues[] = '标题报告质量检查未通过';
            }
        }

        return array_values(array_unique($issues));
    }

    public function coreAnswerCheck(array $structured): array
    {
        $answer = $structured['core_answer'] ?? null;
        $chars = is_string($answer) ? mb_strlen(preg_replace('/\s+/u', '', $answer) ?? '') : 0;

        return [
            'name' => 'core_answer_80_to_120_chars',
            'passed' => $chars >= 80 && $chars <= 120,
            'chars' => $chars,
            'minimum' => 80,
            'maximum' => 120,
        ];
    }

    public function withPublishableChecks(array $report, string $skillId, string $content, ?array $structured = null): array
    {
        if (! str_contains($skillId, 'explainer-builder')) {
            return $report;
        }

        $chars = mb_strlen(preg_replace('/\s+/u', '', $content) ?? '');
        $check = [
            'name' => 'publishable_article_2000_to_4000_chars',
            'passed' => $chars >= 2000 && $chars <= 4000,
            'chars' => $chars,
            'minimum' => 2000,
            'maximum' => 4000,
        ];
        $reviewCheck = [
            'name' => 'publishable_article_matches_review',
            'passed' => is_string($report['publishable_article_sha256'] ?? null)
                && hash_equals($report['publishable_article_sha256'], hash('sha256', $content)),
        ];
        $coreCheck = $structured === null ? null : $this->coreAnswerCheck($structured);
        $replacedChecks = [$check['name'], $reviewCheck['name'], 'core_answer_80_to_120_chars'];
        $originalChecks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        $hasFailedCheck = in_array(false, array_map(
            static fn ($existing): bool => (bool) ($existing['passed'] ?? false),
            $originalChecks,
        ), true);
        $unrepresentedFailure = ($report['passed'] ?? true) === false && ! $hasFailedCheck;
        $checks = array_values(array_filter(
            $originalChecks,
            static fn ($existing): bool => ! is_array($existing)
                || ! in_array($existing['name'] ?? '', $replacedChecks, true),
        ));
        $checks[] = $check;
        $checks[] = $reviewCheck;
        if ($coreCheck !== null) {
            $checks[] = $coreCheck;
        }
        $report['checks'] = $checks;
        $report['passed'] = ! $unrepresentedFailure && ! in_array(false, array_map(
            static fn ($existing): bool => (bool) ($existing['passed'] ?? false),
            $checks,
        ), true);
        $issues = array_values(array_filter(
            is_array($report['issues'] ?? null) ? $report['issues'] : [],
            static fn ($issue): bool => ! in_array($issue, $replacedChecks, true),
        ));
        foreach ($checks as $existing) {
            if (is_array($existing) && ! ($existing['passed'] ?? false) && isset($existing['name'])) {
                $issues[] = $existing['name'];
            }
        }
        $report['issues'] = array_values(array_unique($issues));
        $report['next_action'] = $report['passed'] ? '可交付' : '修复后重新检查';

        return $report;
    }
}
