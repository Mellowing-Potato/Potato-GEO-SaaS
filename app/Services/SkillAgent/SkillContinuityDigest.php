<?php

namespace App\Services\SkillAgent;

/**
 * 分节续写摘要（计划 §7 第 3 步：把 SkillAgentRunnerService 按职责拆小，「准备」里的续写材料）。
 *
 * 每一节都是独立的一次模型调用：上一节看到过什么，这一节一点也不知道。这些方法负责
 * 把「已经写到哪了」压成一段可塞进提示词的摘要，让后面几节**接着写**而不是重写 ——
 * 结论、评级、问题编号、修复项编号必须和前面几节对得上。
 *
 * 六条分支各自的取舍不同（explainer 带事实核验纪律、panorama 带竞品与基线样本、
 * markdown 只带每节开头若干字、generic 兜底列字段名），所以按技能分流而不是合并成一份。
 * 纯函数，无依赖；行为由 tests/Feature/SkillContinuityDigestTest.php 的 sha256 冻结。
 */
class SkillContinuityDigest
{
    /** markdown 分节续写只带每节开头这么多字（再多就把上下文预算吃光了） */
    private const MARKDOWN_DIGEST_CHARS = 1200;

    /**
     * 分节续写的衔接材料：整份报告的章节顺序 + 已经写好的各节节选。
     *
     * 只带每节开头 MARKDOWN_DIGEST_CHARS 个字（理由见那个常量）。带章节顺序是为了让
     * 模型知道自己写的是第几节、后面还有谁 —— 不然它会在中间某节里写「综上所述」，
     * 或者把后面几节的内容提前写完。
     */
    public function markdown(array $state): string
    {
        $outline = [];
        $written = [];

        foreach (array_values($state['specs'] ?? []) as $index => $spec) {
            $outline[] = ($index + 1).'. '.($spec['label'] ?? $spec['key'] ?? '');

            $field = $spec['fields'][0] ?? null;
            $text = $field !== null ? trim((string) ($state['merged'][$field] ?? '')) : '';
            if ($text === '') {
                continue;
            }

            $clipped = mb_substr($text, 0, self::MARKDOWN_DIGEST_CHARS);
            if (mb_strlen($text) > self::MARKDOWN_DIGEST_CHARS) {
                $clipped .= "\n……（本节后半略）";
            }
            $written[] = $clipped;
        }

        $lines = ['本报告的完整章节顺序：'.implode('；', $outline)];

        if ($written !== []) {
            $lines[] = '';
            $lines[] = '下面是已经写好的章节（只给了开头部分，供你衔接）。接着往下写即可：'
                .'不要重复它们已经说过的内容，但结论、评级、问题编号、修复项编号必须和它们对得上；'
                .'如果你的判断与它们冲突，以已经写好的为准。';
            $lines[] = implode("\n\n", $written);
        }

        return implode("\n", $lines);
    }

    /**
     * continuity digest 分派：panorama 保留其身份锚点（品牌/竞品/基线）专用摘要，
     * 其余技能走通用摘要（已产出字段名 + 品牌/公司 + 目标平台锚点）。
     */
    public function forSkill(string $skillId, array $merged, ?string $currentChunkKey = null): string
    {
        if (str_contains($skillId, 'panorama-audit')) {
            return $this->panoramaContinuityDigest($merged);
        }

        if (str_contains($skillId, 'explainer-builder')) {
            return $this->explainerContinuityDigest($merged, $currentChunkKey);
        }

        return $this->genericContinuityDigest($merged);
    }

    /**
     * 解释文章先产证据、再写正文。正文调用必须真正拿到证据内容，而不只是“已产出字段名”。
     * 限长只防证据段异常膨胀；四段调用次数、扣点次数均不因此增加。
     */
    private function explainerContinuityDigest(array $merged, ?string $currentChunkKey = null): string
    {
        if (! array_key_exists('data_availability', $merged)
            && ! array_key_exists('fact_verification_matrix', $merged)) {
            return '';
        }

        $maximumChars = match ($currentChunkKey) {
            'article_core' => 650,
            'knowledge_method' => 360,
            'brand_publish' => 280,
            default => 650,
        };
        $lines = ['- 前段只产出核验候选；以下缺失项和安全表达可约束本段，但不能为新增事实背书。'];
        $append = static function (
            string $label,
            mixed $value,
            int $rows = 1,
            array $fields = [],
            int $rowChars = 180,
        ) use (&$lines, $maximumChars): void {
            $items = is_array($value) && array_is_list($value) ? array_slice($value, 0, $rows) : [$value];
            foreach ($items as $item) {
                if ($fields !== [] && is_array($item)) {
                    $item = array_intersect_key($item, array_flip($fields));
                }
                $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (! is_string($encoded) || $encoded === '' || $encoded === 'null' || $encoded === '[]' || $encoded === '{}') {
                    continue;
                }
                $line = '- '.$label.'：'.mb_substr(preg_replace('/\s+/u', ' ', $encoded) ?? $encoded, 0, $rowChars);
                if (mb_strlen(implode("\n", [...$lines, $line])) > $maximumChars) {
                    return;
                }
                $lines[] = $line;
            }
        };

        $dataRowChars = match ($currentChunkKey) {
            'article_core' => 180,
            'knowledge_method' => 110,
            'brand_publish' => 70,
            default => 180,
        };
        $factRowChars = match ($currentChunkKey) {
            'article_core' => 150,
            'knowledge_method' => 100,
            'brand_publish' => 80,
            default => 150,
        };
        $factRows = match ($currentChunkKey) {
            'article_core' => 2,
            'knowledge_method', 'brand_publish' => 1,
            default => 2,
        };

        $append('数据限制', $merged['data_availability'] ?? null, 1, [
            'status', 'missing_data', 'safe_conclusion',
        ], $dataRowChars);
        $pendingFacts = array_values(array_filter(
            is_array($merged['fact_verification_matrix'] ?? null) ? $merged['fact_verification_matrix'] : [],
            static fn (mixed $row): bool => is_array($row)
                && preg_match('/待|未|缺|暂无|未知/u', (string) ($row['current_status'] ?? '')) === 1,
        ));
        $append('待核验', $pendingFacts, $factRows, [
            'fact', 'current_status', 'fallback_expression',
        ], $factRowChars);

        return mb_substr(implode("\n", $lines), 0, $maximumChars);
    }

    /**
     * 通用 continuity digest：不依赖具体 schema。列已产出顶层字段名（防后段重复），
     * 并抽取身份锚点（品牌/公司、目标平台）供后段交叉引用保持一致。
     */
    private function genericContinuityDigest(array $merged): string
    {
        if ($merged === []) {
            return '';
        }

        $lines = [];
        $lines[] = '- 已产出字段：'.implode('、', array_keys($merged));

        $company = $merged['report']['company'] ?? $merged['company'] ?? $merged['brand_name'] ?? null;
        if (is_string($company) && trim($company) !== '') {
            $lines[] = '- 品牌/公司：'.trim($company);
        }

        $platforms = $this->firstColumnSample($merged['target_platforms'] ?? null, 8);
        if ($platforms !== []) {
            $lines[] = '- 目标平台：'.implode('、', $platforms);
        }

        // 拆开问题库后，后续追问/提示词仍须引用同一批问题 ID，不能重新编一套。
        if (is_array($merged['question_bank'] ?? null)) {
            $lines[] = '- 后续问题引用只使用以下已生成 ID：';
            foreach (array_slice($merged['question_bank'], 0, 60) as $question) {
                if (is_array($question) && is_string($question['question_id'] ?? null)) {
                    $lines[] = $question['question_id'].': '.mb_substr((string) ($question['question'] ?? ''), 0, 100);
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 从已合并结果里抽取一份紧凑摘要，注入后续分段以保持交叉引用一致（品牌、竞品名、基线来源）。
     * 只取标识列，避免把整张表塞进后续 prompt 撑大 token。
     */
    private function panoramaContinuityDigest(array $merged): string
    {
        if ($merged === []) {
            return '';
        }

        $lines = [];
        if (! empty($merged['brand_name'])) {
            $lines[] = '- 品牌：'.(is_string($merged['brand_name']) ? $merged['brand_name'] : json_encode($merged['brand_name'], JSON_UNESCAPED_UNICODE));
        }
        // 竞品名（competitors 第 1 列）
        $names = $this->firstColumnSample($merged['competitors'] ?? null, 8);
        if ($names !== []) {
            $lines[] = '- 已识别竞品：'.implode('、', $names);
        }
        // 基线事实来源（baseline 靠后列常为 source；这里取第 1 列的域/页作参照）
        $bases = $this->firstColumnSample($merged['baseline'] ?? null, 8);
        if ($bases !== []) {
            $lines[] = '- 基线覆盖：'.implode('、', $bases);
        }

        return implode("\n", $lines);
    }

    /**
     * 取二维数组每行第 1 列的字符串样本（最多 $limit 个），供 continuity digest 使用。
     */
    private function firstColumnSample(mixed $rows, int $limit): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $cell = is_array($row) ? ($row[0] ?? null) : $row;
            if (is_string($cell) && trim($cell) !== '') {
                $out[] = trim($cell);
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
