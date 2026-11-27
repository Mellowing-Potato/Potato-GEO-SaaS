#!/usr/bin/env node
/**
 * 运营台 · 平台敏感词面板 前端接线回归测试（执行书 T11 · JS 测试）
 *
 * 为什么这个测试
 * ────────────────
 * T8 的运营台「敏感词基线」分区是一整块新前端：试算闸门、词表、按词聚合、命中日志。
 * 这块代码是原生 JS（无构建、无框架），函数写在 geo-platform-ops.js 的 IIFE 里，
 * 不导出、不单测。照搬 panel-tenant-header 的做法：从**真实源文件**抠函数，
 * 断言它们存在且被接线——既测了「函数写了」，也测了「函数被调用了」，
 * 防止出现「函数写对了但没人接进 loadAll / 事件绑定」这种半边工程。
 *
 * 运行
 * ────
 *   node tests/js/platform-ops-sensitive-words.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const SRC = resolve(HERE, '../..', 'public/geo-platform-ops.js');
const source = readFileSync(SRC, 'utf8');

/** 与 panel-tenant-header.test.mjs 同款：按花括号配对抠函数，避免抄一份实现自测自话。 */
function extractFunction(src, name) {
    const marker = `function ${name}(`;
    const start = src.indexOf(marker);
    if (start === -1) throw new Error(`源码里找不到 \`${marker}\` —— 函数被改名或删除了？`);
    const bodyStart = src.indexOf('{', start);
    if (bodyStart === -1) throw new Error(`\`function ${name}\` 缺少函数体`);
    let depth = 0;
    for (let i = bodyStart; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}' && --depth === 0) return src.slice(start, i + 1);
    }
    throw new Error(`\`function ${name}\` 花括号未配对，无法提取`);
}

let failures = 0;
let checks = 0;

function assert(label, cond) {
    checks++;
    if (!cond) {
        failures++;
        console.log(`  ✗ ${label}`);
    }
    return cond;
}

console.log('\n运营台 · 平台敏感词面板 前端接线测试\n');

const required = [
    'loadSensitiveWords',
    'renderSensitiveWords',
    'loadWordSummary',
    'renderWordSummary',
    'loadWordHits',
    'submitDryRun',
    'pollDryRun',
    'renderDryRun',
    'syncWordSaveGate',
    'saveWords',
    'actSwDisable',
    'actSwEnable',
    'splitSwCandidates',
];

let allExtracted = true;
for (const fn of required) {
    let ok = true;
    try {
        extractFunction(source, fn);
    } catch (e) {
        ok = false;
        allExtracted = false;
        console.log(`  ✗ 函数缺失或无法提取：${fn} —— ${e.message}`);
    }
    assert(`函数存在且可提取：${fn}`, ok);
}

/* 接线：loadAll 必须真的调用各加载函数，否则写了也不执行 */
assert('loadAll 调用了 loadSensitiveWords', /function loadAll\(\)\s*\{[\s\S]*?loadSensitiveWords\(\)/.test(source));
assert('loadAll 调用了 loadWordSummary', /function loadAll\(\)\s*\{[\s\S]*?loadWordSummary\(\)/.test(source));
assert('loadAll 调用了 loadWordHits', /function loadAll\(\)\s*\{[\s\S]*?loadWordHits\(\)/.test(source));

/* 接线：bindActions 必须处理三个新动作 */
assert('bindActions 处理了 sw-disable', /case 'sw-disable':/.test(source));
assert('bindActions 处理了 sw-enable', /case 'sw-enable':/.test(source));
assert('bindActions 处理了 sw-viewhits', /case 'sw-viewhits':/.test(source));

/* 接线：init 必须绑定试算 / 保存按钮，否则闸门形同虚设 */
assert('init 绑定了 swDryRunBtn → submitDryRun', /swDryRunBtn[\s\S]{0,300}addEventListener\('click',\s*submitDryRun/.test(source));
assert('init 绑定了 swSaveBtn → saveWords', /swSaveBtn[\s\S]{0,300}addEventListener\('click',\s*saveWords/.test(source));

/* 闸门不变量：提交试算前先锁死保存按钮，试算完成后才放开 */
assert('submitDryRun 先锁死保存闸门', /syncWordSaveGate\(false\)/.test(source));
assert('syncWordSaveGate 根据入参切换 disabled', /btn\.disabled\s*=\s*!allowed/.test(source));

/* 闸门不变量：blade 里保存按钮默认就是 disabled（双保险，JS 再放开） */
const blade = readFileSync(resolve(HERE, '../..', 'resources/views/platform-ops.blade.php'), 'utf8');
assert('blade 中 swSaveBtn 默认 disabled', /id="swSaveBtn"[^>]*\bdisabled/.test(blade));

/* The scan covers only the current workspace, while the saved baseline remains system-wide. */
const scanUi = Object.fromEntries(['swBulk', 'swDryResult', 'swDryTitle', 'swDryMeta', 'swDryBody'].map(id => [id, { value: 'fixture-candidate' }]));
new Function('$', 'splitSwCandidates', 'toast', 'syncWordSaveGate', 'apiPost',
    extractFunction(source, 'submitDryRun') + '; submitDryRun();')(
    selector => scanUi[selector.slice(1)], () => ['fixture-candidate'], () => {}, () => {}, () => ({ then() {} }),
);
assert('试算标题说明当前工作区范围', scanUi.swDryTitle.textContent.includes('当前工作区') && !scanUi.swDryTitle.textContent.includes('跨租户'));
assert('试算等待态不声称扫描全平台', scanUi.swDryBody.innerHTML.includes('当前工作区文章') && !scanUi.swDryBody.innerHTML.includes('全平台'));
assert('试算说明不将本区结果扩为其他工作区', blade.includes('结果不代表其他工作区'));
assert('系统级词表范围仍如实保留', blade.includes('保存的基线词仍对整个系统生效'));

/* nav spy 必须观察 sec-words 分区 */
assert('分区导航保留敏感词入口并由 href 匹配面板', blade.includes('href="#sec-words"') && /getAttribute\('href'\)\.slice\(1\)/.test(extractFunction(source, 'initNavSpy')));

console.log(`  ${allExtracted ? '✓' : '✗'} 关键函数提取：${required.length} 个`);

console.log('');
if (failures === 0) {
    console.log(`✓ 全部通过：${checks} 项断言\n`);
    process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
