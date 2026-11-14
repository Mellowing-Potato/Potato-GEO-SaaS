#!/usr/bin/env node
/**
 * 独立面板 · 会话重新核验 + 指派人选择 前端回归测试
 *
 * 为什么要这个测试
 * ────────────────
 * 派单二（2026-08-30）四条缺陷，两条落在这两个「自持 session 的独立壳页」上：
 *
 *   A. 403 被当成永久结论。showGuard('forbidden') 说的是「当前账号没有权限」——
 *      这是一句**关于身份的断言**，但面板做断言时用的是 sessionStorage 里一份
 *      从没核验过的缓存身份（退出登录清的是账户中心的 cookie，清不掉这里这一份）。
 *      于是 cookie 已是账号 B（有权限）、sessionStorage 还留着账号 A 的 token，
 *      面板拿 A 的 token 吃 403，再告诉用户「当前账号没有权限」——「当前账号」是错的。
 *      401 那条路一直是对的（handleUnauthorized 会用 cookie 重换 token），403 缺同一步。
 *      ⇒ 修法：403 先用 session-login 重新核验一次身份，仍然 403 才竖墙。
 *
 *   C. 「指派给」是让人手填数字用户 ID 的输入框——客户得先知道同事的数字 ID，
 *      而这个数字界面上任何地方都不显示。⇒ 修法：换成「本租户成员」下拉。
 *
 * 与 panel-tenant-header.test.mjs 同源的老教训：这两个面板是**复制关系**
 * （X-Tenant 那次生产事故的根因就是复制时落了一行），所以本测试对两个面板都跑，
 * PANELS 数组不许只留一个。
 *
 * 测什么（读真实源文件断言，不抄一份实现自测自话）
 * ──────────────────────────────────────────────
 *   A. 403 分支先重新核验，而不是直接竖墙；一次性开关真的会翻（不是摆设）
 *   B. soft403 分支不受影响（局部权限错不触发核验、不竖整页墙）—— 防改过头
 *   C. 重新核验走现成端点、不 reload、失败时跳登录而非竖「无权限」墙
 *   D. 指派是下拉（建单 + 改单两处）、成员列表请求带 soft403、
 *      editor 不发这个请求、列表拉不到时提交不带该字段
 *
 * 运行
 * ─────
 *   node tests/js/panel-session-reverify.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));

/** 两个自持 session 的独立面板。新增同类面板时必须加进来。 */
const PANELS = [
    { name: '手动发布', file: 'public/geo-manual-publish.js' },
    { name: 'AI 可见性', file: 'public/geo-visibility.js' },
];

const MANUAL_PUBLISH = 'public/geo-manual-publish.js';
const BLADE = 'resources/views/manual-publish.blade.php';

let failures = 0;
let checks = 0;

function assert(label, actual, expected) {
    checks++;
    if (actual !== expected) {
        failures++;
        console.log(`  ✗ ${label}\n      实际: ${JSON.stringify(actual)}\n      期望: ${JSON.stringify(expected)}`);
    }
    return actual === expected;
}

/**
 * 剥注释。必须**认字符串**——源码里有 'https://example.test/login' 这种含 // 的字面量，
 * 朴素地按 // 切会把它削掉，进而把「调用点静态检查」变成恒真断言（单 7 教训）。
 */
function stripComments(src) {
    let out = '';
    let i = 0;
    while (i < src.length) {
        const c = src[i];
        if (c === '"' || c === "'" || c === '`') {
            const quote = c;
            out += c;
            i++;
            while (i < src.length) {
                out += src[i];
                if (src[i] === '\\') { out += src[i + 1] ?? ''; i += 2; continue; }
                if (src[i] === quote) { i++; break; }
                i++;
            }
            continue;
        }
        if (c === '/' && src[i + 1] === '/') {
            while (i < src.length && src[i] !== '\n') i++;
            continue;
        }
        if (c === '/' && src[i + 1] === '*') {
            i += 2;
            while (i < src.length && !(src[i] === '*' && src[i + 1] === '/')) i++;
            i += 2;
            continue;
        }
        out += c;
        i++;
    }
    return out;
}

/** 按花括号配对，从真实源文件里抠出整段函数体。 */
function extractFunction(source, name) {
    const marker = `function ${name}(`;
    const start = source.indexOf(marker);
    if (start === -1) throw new Error(`源码里找不到 \`${marker}\` —— 函数被改名或删除了？`);
    const bodyStart = source.indexOf('{', start);
    if (bodyStart === -1) throw new Error(`\`function ${name}\` 缺少函数体`);
    let depth = 0;
    for (let i = bodyStart; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
    }
    throw new Error(`\`function ${name}\` 花括号未配对，无法提取`);
}

/** 从 marker 处开始按花括号配对抠出一段（用于 if 块）。 */
function extractBlock(source, marker) {
    const start = source.indexOf(marker);
    if (start === -1) return '';
    const bodyStart = source.indexOf('{', start + marker.length - 1);
    if (bodyStart === -1) return '';
    let depth = 0;
    for (let i = bodyStart; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
    }
    return '';
}

console.log('\n独立面板 · 会话重新核验 + 指派人选择测试\n');

/* ────────────────────────────────────────────────────────────
   A / B / C：两个面板同款
   ──────────────────────────────────────────────────────────── */
for (const panel of PANELS) {
    const path = resolve(HERE, '../..', panel.file);
    const raw = readFileSync(path, 'utf8');
    const src = stripComments(raw);
    const before = failures;
    const tag = panel.name;

    /* ---- A. 403 先核验，再竖墙 ---- */
    const block403 = extractBlock(src, 'if (r.status === 403)');
    assert(`${tag} · apiReq 里存在 403 分支`, block403.length > 0, true);
    assert(`${tag} · 403 分支调用了重新核验`, /reverifyOnce\s*\(/.test(block403), true);
    // 🔴 每个请求最多重试一次：重试后仍 403 才竖墙。没有这个，403 ↔ 核验 会死循环打后端。
    assert(`${tag} · 403 分支有「重试过就不再重试」的判断`, /if\s*\(\s*isRetry\s*\)/.test(block403), true);
    // 语义检查：竖墙只能发生在「核验后重试仍 403」这条路上，不能是无条件竖墙
    // （无条件竖墙 = 退回缺陷 A 原状：拿一份没核验过的身份下结论）。
    const retryBlock = extractBlock(block403, 'if (isRetry)');
    assert(`${tag} · 竖墙只发生在核验重试之后`, retryBlock.length > 0 && /showGuard/.test(retryBlock), true);
    assert(
        `${tag} · 首次 403 走核验而不是直接竖墙`,
        /reverifyOnce\s*\(/.test(block403.replace(retryBlock, '')),
        true,
    );

    const reverify = extractFunction(src, 'reverifyOnce');
    // 🔴 整页只核验一次 + 并发共用。少了这个守卫：boot 同时发的那几个请求会各打一次
    // session-login；换成布尔量卡「已核验过」，又变成第一个去核验、其余直接竖墙
    // ——正是本缺陷要修的那个墙。
    assert(`${tag} · 核验有一次性守卫 if (!reverifyPromise)`, /if\s*\(\s*!\s*reverifyPromise\s*\)/.test(reverify), true);
    assert(`${tag} · 并发 403 共用同一个核验 promise`, /return\s+reverifyPromise\s*;/.test(reverify), true);
    // 走现成端点，不新造接口。
    assert(`${tag} · 重新核验走现成的 session-login`, /\/api\/v1\/auth\/session-login/.test(reverify), true);
    // 同账号可重试；可见性面板仅在核验发现换号时刷新，防止旧账号的付费请求在新身份下重发。
    if (panel.file === 'public/geo-visibility.js') {
        assert(`${tag} · 仅换号保护分支触发页面 reload`,
            /if\s*\(originalOwner\s*&&/.test(reverify) && /window\.location\.reload/.test(reverify), true);
    } else {
        assert(`${tag} · 重新核验不触发页面 reload`, /window\.location\.reload/.test(reverify), false);
    }

    /* ---- B. soft403 分支一个字都不许动（防改过头）---- */
    const softBlock = extractBlock(block403, 'if (opts.soft403)');
    assert(`${tag} · 403 分支里存在 soft403 子分支`, softBlock.length > 0, true);
    assert(`${tag} · soft403 不触发重新核验`, /reverifyOnce/.test(softBlock), false);
    assert(`${tag} · soft403 不竖整页墙`, /showGuard/.test(softBlock), false);

    /* ---- C. 「没登录」和「没权限」是两件事 ---- */
    // session-login 本身失败 = cookie 也没了 = 没登录 → 跳登录页，绝不竖「无权限」墙。
    assert(`${tag} · 核验失败走 handleUnauthorized（跳登录）`, /handleUnauthorized\s*\(/.test(reverify), true);
    assert(`${tag} · 核验失败不竖「无权限」墙`, /showGuard/.test(reverify), false);

    /* ---- 调用点静态检查：函数写对了但没人调用，等于没修（单 7 教训）---- */
    const apiReq = extractFunction(src, 'apiReq');
    assert(`${tag} · apiReq 确实调用了重新核验`, /reverifyOnce\s*\(/.test(apiReq), true);
    // 重试必须能原样重发，且能区分「这是重试」：apiReq 内应有 send(isRetry) 闭包。
    assert(`${tag} · apiReq 里有可复用的 send(isRetry) 重试闭包`, /function\s+send\s*\(\s*isRetry\s*\)/.test(apiReq), true);

    console.log(`  ${failures === before ? '✓' : '✗'} ${tag.padEnd(10)} ${panel.file}`);
}

/* ────────────────────────────────────────────────────────────
   D：指派人下拉（只有手动发布面板）
   ──────────────────────────────────────────────────────────── */
{
    const mpRaw = readFileSync(resolve(HERE, '../..', MANUAL_PUBLISH), 'utf8');
    const mp = stripComments(mpRaw);
    const before = failures;

    /* 改单表单：手填数字框 → 下拉 */
    assert('指派 · 改单表单的 editAssigned 是 <select>', /id="editAssigned"[^>]*>?/.test(mp) && /<select[^>]*id="editAssigned"/.test(mp), true);
    assert('指派 · 改单表单不再有数字输入框', /<input[^>]*type="number"[^>]*id="editAssigned"/.test(mp), false);

    /* 建单表单（blade）：执行书只点名了改单那一处，建单是同一个毛病 —— 一并守住 */
    const bladeRaw = readFileSync(resolve(HERE, '../..', BLADE), 'utf8');
    const blade = stripComments(bladeRaw.replace(/\{\{--[\s\S]*?--\}\}/g, ''));
    assert('指派 · 建单表单的 assignedUserId 是 <select>', /<select[^>]*id="assignedUserId"/.test(blade), true);
    assert('指派 · 建单表单不再有数字输入框', /<input[^>]*type="number"[^>]*id="assignedUserId"/.test(blade), false);

    /* 成员列表：走现成端点 + soft403；editor 根本不发这个请求 */
    const fill = extractFunction(mp, 'fillAssignee');
    assert('指派 · 成员列表走现成的 /api/v1/team/', /\/api\/v1\/team\//.test(fill), true);
    // 🔴 没有 soft403，editor 拉成员吃 403 会被缺陷 A 的新逻辑竖整页墙挡死。
    assert('指派 · 成员列表请求带 soft403', /apiGet\(\s*'\/api\/v1\/team\/'\s*,\s*true\s*\)/.test(fill), true);
    // editor 分支必须在发请求之前就 return，否则等于明知会 403 还去撞一次。
    const beforeTeam = fill.slice(0, fill.indexOf("apiGet('/api/v1/team/'"));
    assert('指派 · 非 admin 分支在请求之前就返回', /isAdmin\s*\(/.test(beforeTeam) && /return/.test(beforeTeam), true);

    /* 调用点静态检查：两处表单都要真的被填充 */
    const boot = extractFunction(mp, 'boot');
    const showEditForm = extractFunction(mp, 'showEditForm');
    assert('指派 · 建单表单调用了 fillAssignee', /fillAssignee\s*\(\s*\$\('#assignedUserId'\)/.test(boot), true);
    assert('指派 · 改单表单调用了 fillAssignee', /fillAssignee\s*\(\s*\$\('#editAssigned'\)/.test(showEditForm), true);

    /* 列表拉不到时必须「禁用 + 不带该字段」：带上 null 会被服务层当成「把指派清空」 */
    const submitNew = extractFunction(mp, 'submitNew');
    const submitEdit = extractFunction(mp, 'submitEdit');
    assert(
        '指派 · 建单提交在下拉禁用时不带 assigned_user_id',
        /if\s*\([^)]*\.disabled[^)]*\)\s*\{\s*payload\.assigned_user_id\s*=/.test(submitNew),
        true,
    );
    assert(
        '指派 · 改单提交在下拉禁用时不带 assigned_user_id',
        /if\s*\([^)]*\.disabled[^)]*\)\s*\{\s*payload\.assigned_user_id\s*=/.test(submitEdit),
        true,
    );
    // 反面：不能保留无条件赋值（那会在成员列表挂掉时静默清空指派）。
    assert(
        '指派 · 提交载荷里没有无条件的 assigned_user_id 赋值',
        /^\s*assigned_user_id:/m.test(submitNew) || /^\s*assigned_user_id:/m.test(submitEdit),
        false,
    );


    /* ────────────────────────────────────────────────────────────
       🔴 2026-08-31 评审实跑挖出的回归防护网

       第一版把「我自己」写成 <option value="">。看着无害，实际是一条完整的杀伤链：
         value="" → 提交送 assigned_user_id=null
                  → mergeWithExisting() 用 array_key_exists，显式 null **不回填**现值
                  → prepare() 的指派校验是 `$assignedUserId !== null && ...`，null 不进校验
                  → null 直接落库
                  → scopeVisibleTo() 对非 admin 是 where('assigned_user_id', 自己)
                  → editor 保存一次改单，这张单就从他列表里消失，且他无权指派回来。
       旧的数字输入框预填现有 id、原样送回，反而不会踩 —— 所以这是换下拉时**新引入**的回归。
       下面四条守住它。
       ──────────────────────────────────────────────────────────── */
    const selfOpt = extractFunction(mp, 'selfOption');
    assert('指派 · 「我自己」这一项带当前用户 id（不是空串）', /value="\s*"\s*\+?\s*>?\s*我自己|value=""\s*>\s*我自己/.test(selfOpt + fill), false);
    assert('指派 · 「我自己」的 value 取自 userInfo() 的 id', /userInfo\(\)/.test(selfOpt) && /\.id/.test(selfOpt), true);

    // editor 只有「我自己」一项：给他「未指派」等于给他一个把自己踢出列表的按钮。
    assert('指派 · 非 admin 分支不提供「未指派」', /未指派/.test(beforeTeam), false);
    // admin 要能如实显示存量的 assigned_user_id=null，并有权清空。
    assert('指派 · admin 的成员列表里有「未指派」', /未指派/.test(fill.slice(fill.indexOf("apiGet('/api/v1/team/'"))), true);

    // 建单默认选中「我自己」：传 null 会建出一张没有指派人的单，editor 建完自己就看不见。
    assert('指派 · 建单默认选中「我自己」（不是传 null）', /fillAssignee\s*\(\s*\$\('#assignedUserId'\)\s*,\s*null\s*\)/.test(boot), false);
    assert('指派 · 建单把当前用户 id 当默认选中项传入', /fillAssignee\s*\(\s*\$\('#assignedUserId'\)\s*,\s*[^)]*userInfo\(\)/.test(boot), true);

    // 标签订正：现在「留空」是显式的「未指派」，不再等于「自己」。
    assert('指派 · 建单标签不再写「留空=自己」', /留空=自己/.test(blade), false);
    assert('指派 · 改单标签不再写「留空=自己」', /留空=自己/.test(mp), false);

    console.log(`  ${failures === before ? '✓' : '✗'} ${'指派下拉'.padEnd(9)} ${MANUAL_PUBLISH} + ${BLADE}`);
}

console.log('');
if (failures === 0) {
    console.log(`✓ 全部通过：${checks} 项断言\n`);
    process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
