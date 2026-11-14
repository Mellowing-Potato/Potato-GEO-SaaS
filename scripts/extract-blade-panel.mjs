// 把 welcome.blade.php 里的一个面板整段搬到 @include 分部（计划 §7 第 2 步）。
//
// 为什么用工具：面板有 170–260 行，手抄一次就可能少一个 `</div>`；而且这个迁移的正确性判据
// 不是"看起来还在"，而是**渲染出来的 HTML 逐字节不变**。工具只做三件事：
//   1. 按 `id="<panelId>"` 找到所属 `<section ...>` 起止（按 <section>/</section> 配对计数）；
//   2. 把该段原样写进 resources/views/workbench/panels/<name>.blade.php（不加尾换行）；
//   3. 在父模板原地留一行 `@include('workbench.panels.<name>')`。
// 搬完用 tests/Feature/WorkbenchMarkupIntegrityTest.php + 渲染哈希对比验证：
//   改前/改后 `GET /` 的 sha256 必须完全一致（见拆分文档第十一节）。
//
// 用法：
//   node scripts/extract-blade-panel.mjs <panelId> <partialName>          # 试跑
//   node scripts/extract-blade-panel.mjs <panelId> <partialName> --apply  # 真写
//   node scripts/extract-blade-panel.mjs --list                           # 列出候选面板与行数

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
export const ROOT = resolve(HERE, '..');
export const BLADE = 'resources/views/welcome.blade.php';
export const PANEL_DIR = 'resources/views/workbench/panels';

function posix(value) {
  return String(value).split(sep).join('/');
}

/** 列出模板里的面板：id="..." 与其所属 <section> 的行数。 */
export function listPanels(source = readFileSync(resolve(ROOT, BLADE), 'utf8')) {
  const panels = [];
  const re = /<section[^>]*\bid="([A-Za-z0-9_-]+)"[^>]*>/g;
  let match;
  while ((match = re.exec(source)) !== null) {
    const block = findSection(source, match.index);
    panels.push({
      id: match[1],
      start: source.slice(0, block.start).split('\n').length,
      lines: source.slice(block.start, block.end).split('\n').length,
    });
  }
  return panels;
}

/** 从 `<section ...>` 起点按配对计数找到 `</section>` 结束位置（end 为 `>` 之后）。 */
export function findSection(source, start) {
  const openRe = /<section\b/g;
  const closeRe = /<\/section>/g;
  let depth = 0;
  let i = start;

  while (i < source.length) {
    openRe.lastIndex = i;
    closeRe.lastIndex = i;
    const open = openRe.exec(source);
    const close = closeRe.exec(source);
    if (!close) throw new Error('未找到配对的 </section>');
    if (open && open.index < close.index) {
      depth++;
      i = open.index + open[0].length;
      continue;
    }
    depth--;
    i = close.index + close[0].length;
    if (depth === 0) return { start, end: i };
  }

  throw new Error('未找到配对的 </section>');
}

export function extract(source, panelId) {
  // 两种定位方式：面板自己的 id，或它的 data-workspace-view。
  // 个人设置 / 账号安全两个面板没有 id（只有 data-workspace-view），只认 id 会漏掉它们。
  const marker = new RegExp(`<section[^>]*(?:\\bid="${panelId}"|\\bdata-workspace-view="${panelId}")[^>]*>`).exec(source);
  if (!marker) throw new Error(`模板里找不到面板 id="${panelId}" 或 data-workspace-view="${panelId}"`);

  const { start, end } = findSection(source, marker.index);
  const block = source.slice(start, end);

  return {
    block,
    start,
    end,
    replaced: source.slice(0, start) + `@include('workbench.panels.NAME')` + source.slice(end),
  };
}

function main() {
  const args = process.argv.slice(2);
  const positionals = args.filter((item) => !item.startsWith('--'));
  const source = readFileSync(resolve(ROOT, BLADE), 'utf8');

  if (args.includes('--list') || positionals.length === 0) {
    console.log(`${BLADE} 里的面板（按行数倒序）：`);
    for (const panel of listPanels(source).sort((a, b) => b.lines - a.lines)) {
      console.log(`  ${String(panel.lines).padStart(4)} 行  L${panel.start}  ${panel.id}`);
    }
    console.log('\n用法：node scripts/extract-blade-panel.mjs <panelId> <partialName> [--apply]');
    return 0;
  }

  const [panelId, partialName] = positionals;
  const result = extract(source, panelId);
  const target = resolve(ROOT, PANEL_DIR, `${partialName}.blade.php`);
  const replaced = result.replaced.replace('workbench.panels.NAME', `workbench.panels.${partialName}`);

  if (! args.includes('--apply')) {
    console.log(`── 试跑：${panelId} → ${posix(target)}（未写盘）`);
    console.log(`   面板 ${result.block.split('\n').length} 行，父模板第 ${source.slice(0, result.start).split('\n').length} 行起`);
    console.log('   替换后的父模板片段：');
    const line = source.slice(0, result.start).split('\n').length;
    console.log(replaced.split('\n').slice(line - 2, line + 1).map((l) => '     ' + l).join('\n'));
    console.log('\n确认无误后加 --apply。');
    return 0;
  }

  if (! existsSync(dirname(target))) {
    mkdirSync(dirname(target), { recursive: true });
  }
  writeFileSync(target, result.block);
  writeFileSync(resolve(ROOT, BLADE), replaced);

  console.log(`✓ 已写 ${posix(target)}（${result.block.split('\n').length} 行）`);
  console.log(`✓ 已把 ${BLADE} 里第 ${source.slice(0, result.start).split('\n').length} 行起的面板换成 @include`);
  console.log('\n接着做：1) 渲染哈希必须与改前一致；2) 跑 WorkbenchMarkupIntegrityTest；'
    + '3) 下调 frontend-piece-sizes.mjs 里 welcome.blade.php 的水位。');

  const written = readFileSync(target, 'utf8');
  if (written !== result.block) {
    console.error('✗ 自检失败：分部文件内容与面板原文不一致。');
    return 1;
  }
  console.log('✓ 自检通过：分部文件逐字等于原面板。');

  return 0;
}

if (process.argv[1] && posix(fileURLToPath(import.meta.url)) === posix(process.argv[1])) {
  process.exitCode = main();
}
