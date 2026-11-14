#!/usr/bin/env node
/**
 * 大文件拆分水位棘轮 · 回归测试（计划 §7）
 *
 * 计划 §7 把四个文件点名为维护热点（前端 geo-entry.js / welcome.blade.php、
 * 后端 SkillAgentRunnerService、routes/console.php），并给了拆分顺序。拆分是渐进工程，
 * 真正会失控的是"边拆边加"：加功能时顺手又塞几百行，水位永远降不下来。
 *
 * 所以这里守三件事：
 *   1. 四个文件都能被盘点出多块（解析器没瞎，不是把所有行算成一块）；
 *   2. 每块的行号区间自洽，加起来不超过文件总行数；
 *   3. 当前行数不超过 scripts/frontend-piece-sizes.mjs 里记录的 CEILINGS ——
 *      要加就显式抬水位并说明原因，要降就在同一提交里调低。
 *
 * 运行：node tests/js/frontend-piece-sizes.test.mjs
 */

import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { CEILINGS, inventory } from '../../scripts/frontend-piece-sizes.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');

let failures = 0;
let checks = 0;

function ok(label, condition, detail = '') {
  checks++;
  if (condition) {
    console.log(`  ✓ ${label}`);
    return true;
  }
  failures++;
  console.log(`  ✗ ${label}${detail ? `\n      ${detail}` : ''}`);
  return false;
}

console.log(`\n大文件拆分水位测试（根目录 ${ROOT}）\n`);

const report = inventory();

ok('四个热点文件都盘点到了', report.length === Object.keys(CEILINGS).length, `report=${report.length}`);

for (const item of report) {
  ok(`${item.file}：水位已登记`, typeof item.ceiling === 'number' && item.ceiling > 0);
  ok(
    `${item.file}：${item.total} 行 ≤ 水位 ${item.ceiling}`,
    item.total <= item.ceiling,
    `超出 ${item.total - item.ceiling} 行 —— 要么拆，要么在同一提交里显式抬高 CEILINGS 并说明原因`,
  );
  if (item.file === 'routes/console.php') {
    // 这个文件正在被"命令外迁"持续削小（2026-09-21：5066 → 342 行），所以不能用
    // "至少 N 块"当解析器有效性的判据 —— 块数会随迁移下降。真实不变式是：
    // 切出的块数**等于**文件里 `Artisan::command(` 的条数。
    const source = readFileSync(resolve(ROOT, item.file), 'utf8');
    const commandCount = [...source.matchAll(/Artisan::command\(/g)].length;
    ok(`${item.file}：切块数等于 Artisan::command 条数（${commandCount}）`,
      item.pieces.length === commandCount, `pieces=${item.pieces.length}`);
  } else if (item.file === 'resources/views/welcome.blade.php') {
    // 这个文件也在被"按面板拆 @include"持续削小（2026-09-21：2176 → 943 → 326 行），
    // 所以同理不能用"至少 N 块"。不变式：父模板切块数 == 它自己的 data-workspace-view 条数。
    const source = readFileSync(resolve(ROOT, item.file), 'utf8');
    const panelCount = [...source.matchAll(/data-workspace-view="/g)].length;
    ok(`${item.file}：切块数等于文件里 data-workspace-view 条数（${panelCount}）`,
      item.pieces.length === panelCount, `pieces=${item.pieces.length}`);
  } else {
    ok(`${item.file}：切出了多块（解析器有效）`, item.pieces.length >= 4, `pieces=${item.pieces.length}`);
  }

  const last = item.pieces.reduce((max, piece) => Math.max(max, piece.end), 0);
  ok(`${item.file}：块的行号不超过文件行数`, last <= item.total, `last=${last} total=${item.total}`);

  // pieces 是按体积倒序给的（拆分时从最大的那块下手），比重叠要按行号顺序看。
  const byLine = [...item.pieces].sort((a, b) => a.start - b.start);
  const overlapping = byLine.some((piece, index) => index > 0 && piece.start <= byLine[index - 1].end);
  ok(`${item.file}：块之间不重叠`, overlapping === false);
}

const entry = report.find((item) => item.file === 'public/geo-entry.js');
ok(
  'geo-entry.js 最大的块有记录（拆分时从这一块开始）',
  entry.pieces[0].size > 500,
  `最大块 ${entry.pieces[0].size} 行：${entry.pieces[0].label}`,
);

// 拆 @include 之后，"面板总数"这条更重要的不变式在**整组模板**上：父模板 + 分部。
// 少了任何一个面板，这里会立刻红（比"至少 N 块"有意义得多）。
const bladeFile = readFileSync(resolve(ROOT, 'resources/views/welcome.blade.php'), 'utf8');
const includedPanels = [...bladeFile.matchAll(/@include\('workbench\.panels\.([A-Za-z0-9_-]+)'\)/g)].length;
const panelFiles = readdirSync(resolve(ROOT, 'resources/views/workbench/panels')).filter((n) => n.endsWith('.blade.php')).length;
const panelsInParent = [...bladeFile.matchAll(/data-workspace-view="/g)].length;
ok(
  `工作台面板总数 = 父模板 ${panelsInParent} + 分部 ${panelFiles}（@include ${includedPanels} 条）≥ 20`,
  panelsInParent + panelFiles >= 20 && includedPanels === panelFiles,
  `父模板面板=${panelsInParent} 分部文件=${panelFiles} @include=${includedPanels}`,
);

console.log('');
if (failures === 0) {
  console.log(`✓ 全部通过：${checks} 项断言\n`);
  process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
