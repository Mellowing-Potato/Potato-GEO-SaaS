// 把 routes/console.php 里的闭包命令抽成 app/Console/Commands 下的命令类（计划 §7 第 4 条）。
//
// 为什么要工具而不是手抄：剩下 25 条命令，最大的一条 466 行。手抄一次就是一次
// "把 B 写成 b、把 ' 写成 " 的机会"，而这类错误测试不一定抓得住（比如文案里少个空格）。
// 这个脚本**逐字搬**闭包体，只做三件事：
//   1. 签名字符串 → `protected $signature`；purpose 字符串 → `protected $description`；
//   2. 闭包参数 → handle() 的参数（依赖注入在命令类里同样按容器解析）；
//   3. 闭包体 → handle() 的方法体，**一字不改**；
// 并从 console.php 的 use 列表里挑出命令体真正用到的类带过去（Symfony 的 Command
// 换成 Illuminate\Console\Command —— 命令类是 extends 它，不是 use 它）。
//
// 用法：
//   node scripts/migrate-console-command.mjs geo:commercial-sync            # 试跑，打印类源码与改动范围
//   node scripts/migrate-console-command.mjs geo:commercial-sync --apply    # 真写文件 + 从 console.php 删块
//   node scripts/migrate-console-command.mjs --list                         # 列出还能搬的命令
//
// 搬完必须做的事（脚本会在结尾打印提醒）：
//   1. php -l 新类 + routes/console.php；
//   2. php artisan list 对比命令描述与参数；
//   3. 跑这条命令对应的 Feature 测试；
//   4. 下调 scripts/frontend-piece-sizes.mjs 里 routes/console.php 的水位。

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..');
const CONSOLE = 'routes/console.php';

function posix(value) {
  return String(value).split(sep).join('/');
}

/** 从 `{`/`(` 起按配对找到结束位置（跳过字符串，PHP 字符串里的括号不算）。 */
function findMatch(source, start, open, close) {
  let depth = 0;
  let i = start;
  while (i < source.length) {
    const ch = source[i];
    if (ch === "'" || ch === '"') {
      const quote = ch;
      i++;
      while (i < source.length) {
        if (source[i] === '\\') { i += 2; continue; }
        if (source[i] === quote) break;
        i++;
      }
      i++;
      continue;
    }
    if (ch === open) depth++;
    if (ch === close) {
      depth--;
      if (depth === 0) return i;
    }
    i++;
  }
  throw new Error(`括号未配对：起始位置 ${start}`);
}

/**
 * 去掉 PHP 源码里的注释与字符串字面量。
 *
 * 为什么要先剥：类引用检查会扫到注释。实测踩到三处假阳性 ——
 * ReapStuckSkillExecutions 的头注释里写着 `SkillAgentRunnerService::execute()`、
 * RecordSchedulerHeartbeat 写着 `Queue::looping`、ThemePackagesPruneCommand 写着
 * `Service::deleteReplication()`。这些都不是代码，不该被当成"未 import 的类引用"。
 */
export function stripCommentsAndStrings(source) {
  let out = '';
  let i = 0;
  while (i < source.length) {
    const ch = source[i];
    const next = source[i + 1];
    if (ch === '/' && next === '*') {
      i += 2;
      while (i < source.length && !(source[i] === '*' && source[i + 1] === '/')) i++;
      i += 2;
      out += ' ';
      continue;
    }
    if ((ch === '/' && next === '/') || ch === '#') {
      while (i < source.length && source[i] !== '\n') i++;
      continue;
    }
    if (ch === "'" || ch === '"') {
      const quote = ch;
      i++;
      while (i < source.length) {
        if (source[i] === '\\') { i += 2; continue; }
        if (source[i] === quote) { i++; break; }
        i++;
      }
      out += '""';
      continue;
    }
    out += ch;
    i++;
  }
  return out;
}

/** 读取 console.php 里的 `use` 短名 → FQCN 映射。 */
function useMap(source) {
  const map = new Map();
  for (const match of source.matchAll(/^use\s+([A-Za-z0-9_\\]+);/gm)) {
    const fqcn = match[1];
    map.set(fqcn.split('\\').pop(), fqcn);
  }
  return map;
}

/**
 * 找出"没有 import、又没有反斜杠前缀"的类引用 —— 这类引用在 console.php（无命名空间）
 * 里指的是**全局类**，搬进 `App\Console\Commands` 命名空间后会变成
 * `App\Console\Commands\Xxx`，运行时直接 "Class not found"。
 *
 * 2026-09-21 实测踩到：geo:beta-tenant 里的 InvalidArgumentException / RuntimeException
 * 被搬成命名空间内解析，两条 Feature 测试当场红（这正是逐字搬迁必须配静态检查的原因）。
 *
 * 判断口径：console.php 没有命名空间，所以一个类引用只能是
 *   ① `use` 进来的（短名在 useMap 里）→ 已经带过去了；
 *   ② 全限定（源码里写 `\Foo` 或 `Foo\Bar`）→ 不用管；
 *   ③ 全局类（`Throwable`、`RuntimeException`…）→ 必须显式 import。
 * 因此"不在 useMap、又不是自引用/Command"的候选，一律要 import。
 *
 * @returns {string[]} 需要 import 的全局类短名（已去重排序）
 */
export function unqualifiedGlobalClasses(source, selfName = '') {
  // 先剥注释与字符串：注释里出现的 `Foo::bar()` 不是代码引用（实测三处假阳性）。
  source = stripCommentsAndStrings(source);
  const imported = new Set();
  for (const match of source.matchAll(/^use\s+([A-Za-z0-9_\\]+);/gm)) {
    imported.add(match[1].split('\\').pop());
  }

  const patterns = [
    /\bnew\s+([A-Z][A-Za-z0-9_]*)/g,
    /([A-Z][A-Za-z0-9_]*)::/g,
    /\bcatch\s*\(\s*([A-Z][A-Za-z0-9_]*)/g,
    /\binstanceof\s+([A-Z][A-Za-z0-9_]*)/g,
    /(?<![\w\\>:])([A-Z][A-Za-z0-9_]*)\s+\$\w+/g,
    /\)\s*:\s*\??([A-Z][A-Za-z0-9_]*)/g,
  ];

  const found = new Set();
  for (const pattern of patterns) {
    for (const match of source.matchAll(pattern)) {
      const token = match[1];
      const before = source[match.index + match[0].indexOf(token) - 1];
      if (before === '\\') continue;            // 已全限定
      if (imported.has(token)) continue;        // 已 import
      if (token === selfName || token === 'Command') continue;
      found.add(token);
    }
  }

  return [...found].sort();
}

export function listCommands(source = readFileSync(resolve(ROOT, CONSOLE), 'utf8')) {
  return [...source.matchAll(/Artisan::command\(\s*'([^'\n]+)/g)].map((match) => ({
    // 单行签名（`Artisan::command('tasks:tick', function () {`）会把签名一起captured进来，
    // 名字只取到第一个空白或 `{` 为止。
    name: match[1].split(/[\s{]/)[0].trim(),
    index: match.index,
  }));
}

/**
 * 解析一条命令，返回 { name, blockStart, blockEnd, signature, params, body, purpose, imports }。
 */
export function parseCommand(source, name) {
  const marker = `Artisan::command('${name}`;
  const blockStart = source.indexOf(marker);
  if (blockStart < 0) throw new Error(`找不到命令：${name}`);

  const sigQuoteEnd = source.indexOf("', function (", blockStart);
  if (sigQuoteEnd < 0) throw new Error(`命令 ${name} 的签名/闭包结构不符合预期（签名必须是单引号字符串 + , function (）`);
  const signature = source.slice(blockStart + "Artisan::command('".length, sigQuoteEnd);

  const paramsStart = sigQuoteEnd + "', function (".length;
  const paramsEnd = findMatch(source, paramsStart - 1, '(', ')');
  const params = source.slice(paramsStart, paramsEnd).trim();

  // 闭包把命令的**参数/选项**注入成函数参数（`function (string $identifier)`），
  // 而命令类里必须用 $this->argument('identifier') / $this->option('...') 取。
  // 这是两种不同的注入语义，自动改写函数体风险太高（要动代码区域），所以拒绝并让人手工搬。
  // 实测：platform:ops-grant / platform:ops-revoke 就是这种形态。
  const scalarParams = params
    .split(',')
    .map((item) => item.trim())
    .filter((item) => /^(string|int|float|bool|array|mixed)\s+\$[A-Za-z_]/.test(item));
  if (scalarParams.length > 0) {
    throw new Error(
      `命令 ${name} 的闭包把参数注入成了函数参数（${scalarParams.join(', ')}）。`
      + '命令类里必须改用 $this->argument(...) / $this->option(...)，请手工迁移这一条。',
    );
  }

  const bodyStart = source.indexOf('{', paramsEnd);
  const bodyEnd = findMatch(source, bodyStart, '{', '}');
  const body = source.slice(bodyStart + 1, bodyEnd);

  // bodyEnd 指向闭包体的收尾 `}`，所以 tail 以 `})->purpose('…');` 开头。
  const tail = source.slice(bodyEnd);
  const purposeMatch = tail.match(/^\}\s*\)\s*->purpose\('([^']*)'\)\s*;/);
  if (!purposeMatch) throw new Error(`命令 ${name} 的 ->purpose('…'); 结构不符合预期`);
  const purpose = purposeMatch[1];
  const blockEnd = bodyEnd + purposeMatch[0].length;

  const used = new Set();
  const haystack = `${params}${body}${purpose}`;
  const imports = [];
  for (const [short, fqcn] of useMap(source)) {
    if (short === 'Command') continue; // 命令类 extends Command，不是 use 它
    if (new RegExp(`\\b${short}\\b`).test(haystack)) {
      imports.push(fqcn);
      used.add(short);
    }
  }
  // 迁移后命名空间变了：原来靠"无命名空间"直接解析的**全局类**必须显式 import，
  // 否则 `catch (Throwable)` / `new RuntimeException(...)` 会去找
  // App\Console\Commands\Throwable。见 unqualifiedGlobalClasses() 的注释。
  const globals = unqualifiedGlobalClasses(haystack, className(name)).filter((token) => !used.has(token));
  imports.sort();

  return { name, blockStart, blockEnd, signature, params, body, purpose, imports, used, globals };
}

export function className(name) {
  const camel = name
    .split(/[^a-zA-Z0-9]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('');
  return `${camel}Command`;
}

/** 生成命令类源码。body 原样嵌入 —— 这是这个脚本存在的全部理由。 */
export function renderClass(parsed, { sourceFile = CONSOLE } = {}) {
  const cls = className(parsed.name);
  const indent = (text, spaces) => text
    .replace(/^\n+/, '')   // 丢掉闭包体开头的空行（类里紧随 `{` 的那一行）
    .replace(/\s+$/, '')
    .split('\n')
    .map((line) => (line.trim() === '' ? '' : ' '.repeat(spaces) + line.replace(/^ {4}/, '')))
    .join('\n');

  const signatureLines = parsed.signature
    .split('\n')
    .map((line, index) => (index === 0 ? line.trim() : `        ${line.trim()}`))
    .join('\n');

  const params = parsed.params === '' ? '' : ` ${parsed.params}`;
  // 全局类按 PHP 惯例 import 短名（`use Throwable;`），与仓库既有命令类一致。
  const fqcns = [...new Set([...parsed.imports, ...(parsed.globals ?? []), 'Illuminate\\Console\\Command'])].sort();
  const importLines = fqcns.map((fqcn) => `use ${fqcn};`).join('\n');

  // 闭包命令"跑完不 return"= 退出码 0；命令类的 handle(): int 必须显式给值，
  // 否则运行期 TypeError（实测踩到：tasks:tick）。
  const body = /\breturn\b/.test(stripCommentsAndStrings(parsed.body))
    ? parsed.body
    : `${parsed.body.replace(/\s+$/, '')}\n\n    return Command::SUCCESS;`;

  return `<?php

namespace App\\Console\\Commands;

${importLines}

/**
 * 由 scripts/migrate-console-command.mjs 从 ${sourceFile} 迁出（计划 §7 第 4 条：
 * 路由文件只留调度声明，命令实现进 app/Console/Commands）。
 *
 * 搬迁口径：只搬位置。签名字符串与 purpose 逐字复制，闭包体原样进 handle()，
 * 输出格式、退出码与用户可见文案没有任何改动。
 */
class ${cls} extends Command
{
    protected $signature = '${signatureLines}';

    protected $description = '${parsed.purpose.replace(/'/g, "\\'")}';

    public function handle(${params.trim()}): int
    {
${indent(body, 8)}
    }
}
`;
}

function main() {
  const args = process.argv.slice(2);
  const apply = args.includes('--apply');
  const source = readFileSync(resolve(ROOT, CONSOLE), 'utf8');

  if (args.includes('--list') || args.length === 0) {
    console.log('routes/console.php 里仍以闭包形式存在的命令：');
    for (const item of listCommands(source)) {
      console.log(`  ${item.name.padEnd(38)} → ${className(item.name)}`);
    }
    console.log('\n用法：node scripts/migrate-console-command.mjs <命令名> [--apply]');
    return 0;
  }

  const name = args.find((item) => !item.startsWith('--'));
  const parsed = parseCommand(source, name);
  const cls = className(name);
  const classSource = renderClass(parsed);

  if (!apply) {
    console.log(`── 试跑 ${name} → app/Console/Commands/${cls}.php（未写盘）\n`);
    console.log(classSource);
    console.log(`── 将从 ${CONSOLE} 删除 L${source.slice(0, parsed.blockStart).split('\n').length}–`
      + `${source.slice(0, parsed.blockEnd).split('\n').length}（共 ${parsed.blockEnd - parsed.blockStart} 字符）`);
    console.log('\n确认无误后加 --apply。');
    return 0;
  }

  const target = resolve(ROOT, 'app/Console/Commands', `${cls}.php`);
  writeFileSync(target, classSource);

  const note = `// ${name} 的实现已迁入 app/Console/Commands/${cls}.php（计划 §7 第 4 条）。`;
  const updated = source.slice(0, parsed.blockStart) + note + source.slice(parsed.blockEnd);
  writeFileSync(resolve(ROOT, CONSOLE), updated);

  console.log(`✓ 已写 ${posix(target)}`);
  console.log(`✓ 已从 ${CONSOLE} 删除 ${name} 的闭包（替换为一行说明）`);
  console.log('\n接着做：1) php -l 两个文件；2) php artisan list 对比描述与参数；'
    + '3) 跑该命令的 Feature 测试；4) 下调 frontend-piece-sizes.mjs 里 console.php 的水位。');

  // 自检：新类里必须逐字包含原闭包体。做不到就说明抽取逻辑坏了，立刻暴露。
  const written = readFileSync(target, 'utf8');
  const normalized = (text) => text.replace(/^\s+/gm, '').replace(/\s+$/gm, '');
  if (!normalized(written).includes(normalized(parsed.body))) {
    console.error('✗ 自检失败：新类里没有逐字包含原闭包体，请回滚这次搬迁。');
    return 1;
  }
  console.log('✓ 自检通过：新类逐字包含原闭包体。');

  try {
    execFileSync('php', ['-l', target], { stdio: 'inherit' });
  } catch (error) {
    console.error('✗ php -l 未通过，请回滚这次搬迁。');
    return 1;
  }
  return 0;
}

if (process.argv[1] && posix(fileURLToPath(import.meta.url)) === posix(process.argv[1])) {
  process.exitCode = main();
}
