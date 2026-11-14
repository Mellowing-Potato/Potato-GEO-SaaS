#!/usr/bin/env php
<?php
/**
 * 候选清单生成器：从 platform_sensitive_words_baseline.php（唯一权威数据源）生成
 * docs/敏感词基线-候选清单-2026-08-31.md（人读版，供法务/老板签字）。
 *
 * 关键纪律：markdown 由脚本生成、不许手写，确保「签字签的是和灌库读的是同一份」。
 * 内置校验：词本身不得含空格/标点，否则直接报错退出（扫描器对内容去分隔符折叠，
 * 词本身带分隔符会让折叠逻辑退化）。
 */

$base = __DIR__ . '/platform_sensitive_words_baseline.php';
if (!is_file($base)) {
    fwrite(STDERR, "找不到数据源：$base\n");
    exit(1);
}

/** @var array<int,array{word:string,category:string,severity:string,source:string,note:string}> $words */
$words = require $base;

// ── 校验：每条必须是完整字段，且 word 不含空格/标点 ──
$invalid = 0;
foreach ($words as $i => $row) {
    foreach (['word', 'category', 'severity', 'source', 'note'] as $k) {
        if (!is_string($row[$k] ?? null) || $row[$k] === '') {
            fwrite(STDERR, "第 {$i} 条缺字段或为空：$k\n");
            $invalid++;
        }
    }
    if (preg_match('/\s|[[:punct:]]/u', $row['word'] ?? '')) {
        fwrite(STDERR, "第 {$i} 条的词含空格或标点，违反纪律：{$row['word']}\n");
        $invalid++;
    }
    if (!in_array($row['category'] ?? '', ['politics', 'illegal'], true)) {
        fwrite(STDERR, "第 {$i} 条 category 非法：{$row['category']}\n");
        $invalid++;
    }
}
if ($invalid > 0) {
    fwrite(STDERR, "校验未通过，共 {$invalid} 处问题，已中止生成。\n");
    exit(1);
}

// ── 分组统计 ──
$byCat = ['politics' => [], 'illegal' => []];
$bySource = [];
foreach ($words as $row) {
    $byCat[$row['category']][] = $row;
    $bySource[$row['source']] = ($bySource[$row['source']] ?? 0) + 1;
}

$total = count($words);
$date = date('Y-m-d');
$catLabel = ['politics' => '政治时政（politics）', 'illegal' => '违法暴力（illegal：毒品/枪爆/赌博/色情）'];

$md = "# 平台级敏感词基线库 · 候选清单（签字版）\n\n";
$md .= "> 生成时间：$date ｜ 唯一权威数据源：`database/data/platform_sensitive_words_baseline.php`\n";
$md .= "> 本文件由 `database/data/generate-sensitive-words-candidate-list.php` 从数据源**自动生成**，请勿手写修改——\n";
$md .= "> 签字签的是这一份，但灌库读的是同一个 PHP 数据源，二者同源。\n";
$md .= "> **候选态**：法务/老板签字前，本清单不建迁移、不灌库、不落入任何持久化层。\n\n";

$md .= "## 总览\n\n";
$md .= "- 总条数：**{$total}**\n";
$md .= "- 政治时政（politics）：**" . count($byCat['politics']) . "** 条\n";
$md .= "- 违法暴力（illegal）：**" . count($byCat['illegal']) . "** 条\n";
$md .= "- 医疗功效类：**0** 条（老板第 2 条，本批不碰，另行派单）\n\n";

$md .= "### 词源分布\n\n";
$md .= "| 来源 | 条数 |\n|---|---|\n";
foreach ($bySource as $src => $cnt) {
    $md .= "| " . str_replace('|', '\\|', $src) . " | {$cnt} |\n";
}
$md .= "\n";

// 政治类占位说明（暂无词）
if (count($byCat['politics']) === 0) {
    $md .= "> ⚠️ **政治类暂为空**：本环境代理无法直连 GitHub 自动抓取上游公开词表，且按「不许凭印象编 / 来源说不清的不要收」铁律，未手写政治词。\n";
    $md .= "> 待老板提供公开词表文件或确认可抓取来源后，由工兵补全并经法务定稿，再重新生成本签字版。\n\n";
}

foreach (['politics', 'illegal'] as $cat) {
    $md .= "## {$catLabel[$cat]}（" . count($byCat[$cat]) . " 条）\n\n";
    if (count($byCat[$cat]) === 0) {
        $md .= "_（暂无）_\n\n";
        continue;
    }
    $md .= "| # | 词 | 级别 | 来源 | 备注 |\n|---|---|---|---|---|\n";
    foreach ($byCat[$cat] as $n => $row) {
        $md .= '| ' . ($n + 1) . ' | ' . $row['word']
            . ' | ' . $row['severity']
            . ' | ' . str_replace('|', '\\|', $row['source'])
            . ' | ' . str_replace('|', '\\|', $row['note']) . " |\n";
    }
    $md .= "\n";
}

$outPath = __DIR__ . '/../../docs/敏感词基线-候选清单-2026-08-31.md';
if (file_put_contents($outPath, $md) === false) {
    fwrite(STDERR, "写入失败：$outPath\n");
    exit(1);
}

echo "已生成：{$outPath}\n";
echo "总条数：{$total}（政治 " . count($byCat['politics']) . " / 非法 " . count($byCat['illegal']) . "）\n";
echo "校验：全部词均无空格/标点，类别合法。\n";
