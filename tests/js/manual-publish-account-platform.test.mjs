#!/usr/bin/env node
/**
 * 手动发布 · 账号下拉「人设 + 平台」双条件过滤 回归测试
 *
 * 为什么要这个测试
 * ────────────────
 * 2026-08-30 生产实测：建单连续两次 422「账号与所选人设/平台不匹配」。
 * 根因不在后端——后端要求 account.persona_id 与 account.platform 同时对得上
 * （ManualPublicationService::prepare 的 account_mismatch 分支），这是对的。
 * 错在界面：
 *   - 账号下拉此前只按 persona_id 过滤，完全不看平台；
 *   - 平台下拉默认停在 PLATFORMS 第一项（知乎）；
 *   - 生产当时唯一一个账号「清禾官方」绑的是小红书。
 * 于是表单一打开就是「小红书的账号 + 知乎」这个后端必拒的组合，
 * 而且界面从头到尾没告诉过用户那个账号属于哪个平台。
 *
 * 测什么
 * ──────
 *   A. 从真实源文件抠出 accountMatches() 执行：平台不符必须过滤掉。
 *   B. 静态断言：建单与编辑两条路径都真的用了它，且平台下拉的 change
 *      会触发重新过滤 —— A 过 B 不过 = 函数写了但没接上，正是本次 bug 的形态。
 *
 * 运行
 * ────
 *   node tests/js/manual-publish-account-platform.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const FILE = 'public/geo-manual-publish.js';
const source = readFileSync(resolve(HERE, '../..', FILE), 'utf8');

/** 与其余前端用例同款：按花括号配对，从真实源文件里抠函数，避免抄一份实现自测自话。 */
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

const accountMatches = new Function(`${extractFunction(source, 'accountMatches')}\nreturn accountMatches;`)();

let failures = 0;
let checks = 0;

function assert(label, actual, expected) {
    checks++;
    if (actual !== expected) {
        failures++;
        console.log(`  ✗ ${label}\n      实际: ${JSON.stringify(actual)}\n      期望: ${JSON.stringify(expected)}`);
    }
}

console.log('\n手动发布 · 账号下拉双条件过滤测试\n');

/* 生产实测那条数据：清禾官方，人设 1，小红书 */
const prodAccount = { id: 1, persona_id: 1, platform: 'xiaohongshu', custom_platform: null, account_name: '清禾官方', is_active: true };

/* A-1 这就是生产上那次 422：人设对、平台错，必须被过滤掉 */
assert('人设对但平台错（小红书账号 + 知乎）→ 过滤掉', accountMatches(prodAccount, 1, 'zhihu', ''), false);
assert('人设与平台都对 → 保留', accountMatches(prodAccount, 1, 'xiaohongshu', ''), true);
assert('平台对但人设错 → 过滤掉', accountMatches(prodAccount, 2, 'xiaohongshu', ''), false);

/* A-2 停用账号任何情况下都不该出现在建单下拉里 */
assert('已停用 → 过滤掉', accountMatches({ ...prodAccount, is_active: false }, 1, 'xiaohongshu', ''), false);

/* A-3 下拉的 value 是字符串、库里的 id 是数字，比较必须容忍类型差异 */
assert('persona_id 字符串与数字混用 → 仍匹配', accountMatches({ ...prodAccount, persona_id: '1' }, 1, 'xiaohongshu', ''), true);

/* A-4 自定义平台：与后端同一判据——账号侧填了名字才比对，为空视为通配 */
const custom = { ...prodAccount, platform: 'custom', custom_platform: '即刻' };
assert('自定义平台名一致 → 保留', accountMatches(custom, 1, 'custom', '即刻'), true);
assert('自定义平台名不一致 → 过滤掉', accountMatches(custom, 1, 'custom', '豆瓣'), false);
assert('账号侧自定义平台名为空 → 视为通配保留', accountMatches({ ...custom, custom_platform: null }, 1, 'custom', '豆瓣'), true);

/* A-5 脏数据不能让整个下拉炸掉 */
assert('账号为 null → 返回 false 而不是抛异常', accountMatches(null, 1, 'zhihu', ''), false);

/* B. 静态断言：两条路径真的接上了，且平台变化会重新过滤 */
const refresh = extractFunction(source, 'refreshAccountOptions');
assert('建单：refreshAccountOptions 用了 accountMatches', /accountMatches\s*\(/.test(refresh), true);
assert('建单：refreshAccountOptions 读了 #platformSel', /#platformSel/.test(refresh), true);
assert('建单：空结果时给出「哪个平台下没有账号」的说明', /noAccountHint\s*\(/.test(refresh), true);

const editAccount = extractFunction(source, 'fillEditAccount');
assert('编辑：fillEditAccount 用了 accountMatches', /accountMatches\s*\(/.test(editAccount), true);
assert('编辑：fillEditAccount 读了 #editPlatform', /#editPlatform/.test(editAccount), true);

const binds = extractFunction(source, 'bindStaticListeners');
assert('建单：平台下拉 change 会重新过滤账号', /refreshAccountOptions\s*\(\s*\)/.test(binds), true);
assert('建单：人设下拉 change 绑的是同一个函数', /personaSel'\)\.addEventListener\('change',\s*refreshAccountOptions\)/.test(binds), true);

/* 编辑表单的监听器绑在 showEditForm 里 */
const editForm = extractFunction(source, 'showEditForm');
assert('编辑：平台下拉 change 会重新拉账号', /editPlatform[\s\S]{0,400}fillEditAccount/.test(editForm), true);

/* 建单失败时必须清掉上一单留下的风险扫描框 */
const submitNew = extractFunction(source, 'submitNew');
assert('建单：提交时先清空 #riskBox', /#riskBox[\s\S]{0,120}hidden\s*=\s*true/.test(submitNew), true);

console.log('');
if (failures === 0) {
    console.log(`✓ 全部通过：${checks} 项断言\n`);
    process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
