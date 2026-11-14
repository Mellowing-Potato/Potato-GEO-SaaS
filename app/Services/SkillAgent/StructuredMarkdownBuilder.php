<?php

namespace App\Services\SkillAgent;

/**
 * 把技能的结构化 JSON 转成 Markdown 正文。
 *
 * 为什么需要它：11 个渲染脚本里有 9 个吃 `--input <json>`，另外两个
 * （排名型文章、知识库构建）吃的是 `--source <markdown>` —— 一份写好的 Markdown
 * 正文，不是 JSON。RenderBridge 用这个类把前者转成后者，两边就能走同一条管线。
 *
 * 输入形状是这两个技能共用的那套（见 RenderSchemaRegistry）：
 *
 *   { title, subtitle?, brand_name, analysis_date,
 *     summary_cards: [{label, value, note?}],
 *     sections: [{title, paragraphs?, bullets?, table?}] }
 *
 * 原则：**只转换，不编造**。模型没给的内容这里不会凭空补出来 ——
 * 交付物是给客户看的，宁可少一节，也不能填假话。
 */
class StructuredMarkdownBuilder
{
    public function build(array $data, string $summaryHeading = '结构化摘要'): string
    {
        $lines = [];

        $title = $this->text($data['title'] ?? '') ?: '未命名报告';
        $lines[] = '# ' . $title;
        $lines[] = '';

        if ($subtitle = $this->text($data['subtitle'] ?? '')) {
            $lines[] = '> ' . $subtitle;
            $lines[] = '';
        }

        // 品牌与日期做成一行元信息，模型缺哪个就少哪个，不占位。
        $meta = array_filter([
            $this->text($data['brand_name'] ?? $data['company_name'] ?? ''),
            $this->text($data['analysis_date'] ?? ''),
        ], fn(string $v) => $v !== '');
        if ($meta !== []) {
            $lines[] = '**' . implode('** · **', $meta) . '**';
            $lines[] = '';
        }

        $cards = $this->listOf($data['summary_cards'] ?? []);
        if ($cards !== []) {
            $lines[] = '## ' . $summaryHeading;
            $lines[] = '';
            foreach ($cards as $card) {
                $lines[] = $this->cardLine($card);
            }
            $lines[] = '';
        }

        foreach ($this->listOf($data['sections'] ?? []) as $section) {
            array_push($lines, ...$this->sectionLines($section));
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /** 摘要卡片：`- **标签**：值（备注）` */
    private function cardLine(mixed $card): string
    {
        if (! is_array($card)) {
            return '- ' . $this->text($card);
        }

        $label = $this->text($card['label'] ?? $card['title'] ?? '');
        $value = $this->text($card['value'] ?? $card['detail'] ?? $card['content'] ?? '');
        $note = $this->text($card['note'] ?? '');

        $line = $label !== '' ? '- **' . $label . '**' : '-';
        if ($value !== '') {
            $line .= ($label !== '' ? '：' : ' ') . $value;
        }
        if ($note !== '') {
            $line .= '（' . $note . '）';
        }

        return $line;
    }

    /** @return list<string> */
    private function sectionLines(mixed $section): array
    {
        if (! is_array($section)) {
            $text = $this->text($section);
            return $text === '' ? [] : [$text, ''];
        }

        $lines = [];
        if ($title = $this->text($section['title'] ?? $section['heading'] ?? '')) {
            $lines[] = '## ' . $title;
            $lines[] = '';
        }

        // 部分上游 schema 用 content 承载一整段已经写好的 Markdown（可包含多级标题、
        // 表格和列表）。这里必须原样保留换行；走 text() 会把整段压成一行并吞掉正文结构。
        if ($content = $this->markdownBlock($section['content'] ?? '')) {
            $lines[] = $content;
            $lines[] = '';
        }

        foreach ($this->listOf($section['paragraphs'] ?? []) as $paragraph) {
            if ($text = $this->text($paragraph)) {
                $lines[] = $text;
                $lines[] = '';
            }
        }

        $bullets = $this->listOf($section['bullets'] ?? []);
        if ($bullets !== []) {
            foreach ($bullets as $bullet) {
                if ($text = $this->text($bullet)) {
                    $lines[] = '- ' . $text;
                }
            }
            $lines[] = '';
        }

        if ($table = $this->tableLines($section['table'] ?? null)) {
            array_push($lines, ...$table);
        }

        if ($callout = $this->text($section['callout'] ?? '')) {
            $lines[] = '> ' . $callout;
            $lines[] = '';
        }

        return $lines;
    }

    private function markdownBlock(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    }

    /**
     * @return list<string> 空数组表示这份 table 不可用（缺表头或没有数据行）
     */
    private function tableLines(mixed $table): array
    {
        if (! is_array($table)) {
            return [];
        }

        $headers = array_map($this->text(...), $this->listOf($table['headers'] ?? []));
        $rows = $this->listOf($table['rows'] ?? []);
        if ($headers === [] || $rows === []) {
            return [];
        }

        $lines = [
            '| ' . implode(' | ', $headers) . ' |',
            '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |',
        ];

        foreach ($rows as $row) {
            $cells = array_map($this->text(...), $this->listOf($row));
            if ($cells === []) {
                continue;
            }
            // 列数对不上是模型的常见错误。补齐/截断而不是丢掉整行 ——
            // 丢行等于悄悄吞掉客户的数据，补齐至少内容还在。
            $cells = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        $lines[] = '';

        return count($lines) > 3 ? $lines : [];
    }

    /** @return list<mixed> */
    private function listOf(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        return $value === null || $value === '' ? [] : [$value];
    }

    /** 标量转文本；数组用顿号连接。Markdown 表格里的 | 必须转义，否则会把单元格切断。 */
    private function text(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? '是' : ($value === false ? '否' : '');
        }
        if (is_array($value)) {
            return implode('、', array_map($this->text(...), $value));
        }

        return trim(str_replace(['|', "\r\n", "\r", "\n"], ['\\|', ' ', ' ', ' '], (string) $value));
    }
}
