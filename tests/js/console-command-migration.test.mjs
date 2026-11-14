#!/usr/bin/env node
/**
 * 命令外迁工具 · 回归测试（计划 §7 第 4 条）
 *
 * scripts/migrate-console-command.mjs 逐字把闭包命令搬成 Command 类。它是"批量工具"，
 * 出错不会报错、只会静默改语义 —— 所以它自己也要被钉住：
 *
 *   1. 命令名解析正确（历史上踩过：单行签名会把 `{identifier}` 一起captured进名字，
 *      生成出 PlatformOpsGrantIdentifierCommand 这种错类名）；
 *   2. 闭包体**逐字**进入新类（这是整个工具存在的理由）；
 *   3. 依赖注入参数、签名、description 都跟着走；
 *   4. 已经搬走的命令不再出现在待搬清单里（说明"清单 = 真实状态"，不是一份手抄表）。
 *
 * 运行：node tests/js/console-command-migration.test.mjs
 */

import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  className,
  listCommands,
  parseCommand,
  renderClass,
  unqualifiedGlobalClasses,
} from '../../scripts/migrate-console-command.mjs';

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

function normalize(text) {
  return text.replace(/^\s+/gm, '').replace(/\s+$/gm, '');
}

console.log('\n命令外迁工具测试\n');

const source = readFileSync(resolve(ROOT, 'routes/console.php'), 'utf8');
const pending = listCommands(source);
const pendingNames = pending.map((item) => item.name);

console.log(`  待搬命令 ${pendingNames.length} 条`);
ok('待搬清单里每条命令名都是干净的（不含空白或花括号）',
  pendingNames.every((name) => !/[\s{]/.test(name)),
  pendingNames.filter((name) => /[\s{]/.test(name)).join(', '));

ok('类名映射正确（demo:sample-report → DemoSampleReportCommand）',
  className('demo:sample-report') === 'DemoSampleReportCommand');
ok('类名映射处理冒号与短横线（geo:beta-edge-check → GeoBetaEdgeCheckCommand）',
  className('geo:beta-edge-check') === 'GeoBetaEdgeCheckCommand');

// 已经搬走的命令不该再出现在待搬清单里 —— 清单必须是仓库真实状态。
for (const moved of ['geo:commercial-report', 'geo:commercial-sync', 'geo:beta-report', 'geo:beta-edge-check']) {
  ok(`已外迁的 ${moved} 不在待搬清单里`, !pendingNames.includes(moved));
}

// 逐字搬运：优先拿一条"还带选项/参数"的**真实待搬命令**验证；当清单只剩没有选项的命令
// （现在只剩 Laravel 自带的 inspire）时退回一条合成命令 —— 这样断言永远有效，
// 而不是随着迁移推进变成假红/空转。
const sampleName = pendingNames.find((name) => parseCommand(source, name).signature.includes('{'));
const sampleSource = sampleName
  ? source
  : `<?php

Artisan::command('demo:sample {--json}', function () {
    $this->info('sample');
})->purpose('demo sample command for the migration tool test');`;
const sampleCommand = sampleName ?? 'demo:sample';
const parsed = parseCommand(sampleSource, sampleCommand);
ok(`能解析 ${sampleCommand} 的签名`, parsed.signature.startsWith(sampleCommand));
ok(`${sampleCommand} 的签名带参数/选项`, parsed.signature.includes('{'));
ok(`${sampleCommand} 的 purpose 非空`, parsed.purpose.length > 10);

const rendered = renderClass(parsed);
ok('生成类里逐字包含原闭包体（忽略缩进）',
  normalize(rendered).includes(normalize(parsed.body)));
ok('生成类带 protected $signature / $description',
  rendered.includes('protected $signature') && rendered.includes('protected $description'));
ok('生成类 extends Illuminate\\Console\\Command',
  rendered.includes('use Illuminate\\Console\\Command;') && rendered.includes('extends Command'));

// 带依赖注入的命令：参数要进 handle() 签名，用的类要被带过去。
const injectedSource = `<?php
use App\\Services\\Ops\\PrivacyPurgeService;
Artisan::command('demo:purge', function (PrivacyPurgeService $purge) {
    $purge->prune();
})->purpose('demo dependency injection');`;
const injected = renderClass(parseCommand(injectedSource, 'demo:purge'));
ok('依赖注入参数进了 handle() 签名',
  /function handle\(PrivacyPurgeService \$purge\): int/.test(injected));
ok('被用到的服务类跟着 import 过去',
  injected.includes('use App\\Services\\Ops\\PrivacyPurgeService;'));

// 结构不符合预期时必须显式报错，不能生成半成品。
let refused = false;
try {
  parseCommand(source, 'geo:not-a-real-command');
} catch (error) {
  refused = true;
}
ok('未知命令名会显式报错而不是生成空类', refused);

/* ── 命名空间迁移的静态护栏 ────────────────────────────────────────
   2026-09-21 实测踩到的坑：console.php 没有命名空间，`catch (Throwable)`、
   `new RuntimeException(...)` 在那边直接解析到全局类；搬进 App\Console\Commands 之后
   会变成 App\Console\Commands\Throwable → 运行期 "Class not found"。
   GeoBetaTenantProvisioningTest 当时就是被这一条打红的。 */

const synthetic = `<?php

Artisan::command('demo:globals', function () {
    try {
        throw new RuntimeException('boom');
    } catch (Throwable $e) {
        return \\Illuminate\\Support\\Facades\\Log::warning($e->getMessage());
    }
})->purpose('demo');`;

const globals = unqualifiedGlobalClasses(synthetic, 'DemoGlobalsCommand');
ok('全局类检测抓得到 RuntimeException', globals.includes('RuntimeException'), globals.join(', '));
ok('全局类检测抓得到 Throwable', globals.includes('Throwable'), globals.join(', '));
ok('已全限定的类不算（\\Illuminate\\…\\Log）',
  !globals.some((token) => token === 'Log'), globals.join(', '));

const syntheticClass = renderClass(parseCommand(synthetic, 'demo:globals'));
ok('生成的类会把全局类 import 过去',
  syntheticClass.includes('use Throwable;') && syntheticClass.includes('use RuntimeException;'),
  syntheticClass.split('\n').filter((line) => line.startsWith('use ')).join(' | '));

// 没有 return 的闭包：闭包命令跑完 = 退出码 0，命令类的 handle(): int 必须补一个显式返回，
// 否则运行期 "Return value must be of type int, none returned"（实测踩到：tasks:tick）。
const noReturn = `<?php

Artisan::command('demo:no-return', function () {
    $this->info('done');
})->purpose('demo');`;
const noReturnClass = renderClass(parseCommand(noReturn, 'demo:no-return'));
ok('闭包没有 return 时自动补 return Command::SUCCESS',
  /return Command::SUCCESS;\s*\n\s*\}/.test(noReturnClass));

// 闭包把命令参数注入成函数参数（platform:ops-grant 那种形态）：命令类里必须改用
// $this->argument()，语义不同，工具必须拒绝而不是生成一个跑不起来的类。
const scalarParam = `<?php

Artisan::command('demo:scalar {identifier}', function (string $identifier) {
    $this->info($identifier);
})->purpose('demo');`;
let refusedScalar = false;
let scalarMessage = '';
try {
  parseCommand(scalarParam, 'demo:scalar');
} catch (error) {
  refusedScalar = true;
  scalarMessage = error.message;
}
ok('闭包把参数注入成函数参数时工具拒绝迁移', refusedScalar, scalarMessage);
ok('拒绝原因里点明要用 $this->argument()', scalarMessage.includes('$this->argument'));

// 真正落地的命令类：任何一个都不能有"未 import 的类引用"。
const commandFiles = readdirSync(resolve(ROOT, 'app/Console/Commands')).filter((name) => name.endsWith('.php'));
const offenders = [];
for (const file of commandFiles) {
  const src = readFileSync(resolve(ROOT, 'app/Console/Commands', file), 'utf8');
  const missing = unqualifiedGlobalClasses(src, file.replace('.php', ''));
  if (missing.length) offenders.push(`${file}: ${missing.join(', ')}`);
}
ok(`app/Console/Commands 下 ${commandFiles.length} 个命令类都没有未 import 的类引用`,
  offenders.length === 0,
  offenders.join('\n      '));

console.log('');
if (failures === 0) {
  console.log(`✓ 全部通过：${checks} 项断言\n`);
  process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
