#!/usr/bin/env node
/**
 * 独立面板 · X-Tenant 请求头 前端回归测试
 *
 * 为什么要这个测试
 * ────────────────
 * 2026-08-30 生产实测：/manual-publish 页面所有下拉框显示「加载人设失败 /
 * 加载文章失败」。根因不在手动发布模块本身，而在租户识别——
 *   - 生产 TENANT_DOMAIN = app.example.test，正是这些面板所在的域名；
 *   - IdentifyTenant::extractSubdomain() 在 host === baseDomain 时，
 *     只认 X-Tenant 请求头（或 ?tenant= 查询参数），否则返回 null；
 *   - null → abort(404) → 整组 /api/v1/* 全部 404。
 * 工作台 geo-entry.js 一直带着这个头，所以工作台没事；独立壳页各自实现
 * 请求函数，必须逐页检查。2026-10-15 线上验收发现 analytics 也漏了该头。
 *
 * 这个 bug 在本地开发环境**测不出来**：extractSubdomain 里有一段
 * `if (app()->isLocal() || app()->environment('testing'))` 的旁路，
 * 本地无条件接受 X-Tenant / ?tenant=，甚至不带也可能命中别的分支。
 * 所以只能在前端侧断言「这个头一定发出去了」。
 *
 * 测什么
 * ──────
 *   A. 从真实源文件抠出 tenantSubdomain() 执行，喂桩 sessionStorage：
 *      有租户 → 返回 subdomain；无租户 / 坏 JSON → 返回空串（不抛异常）。
 *   B. 源码静态断言：面板请求函数的请求头里确实写了 X-Tenant。
 *      —— A 过 B 不过 = 函数写了但没人调用，正是本次 bug 的形态。
 *
 * 运行
 * ────
 *   node tests/js/panel-tenant-header.test.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));

/** 租户侧自持 session 的独立面板。新增同类面板时必须加进来。 */
const PANELS = [
    { name: '手动发布', file: 'public/geo-manual-publish.js', request: 'apiReq' },
    { name: 'AI 可见性', file: 'public/geo-visibility.js', request: 'apiReq' },
    { name: 'AI 爬虫访问', file: 'public/geo-analytics.js', request: 'apiGet' },
];

/** 与时区测试同款：按花括号配对，从真实源文件里抠函数，避免抄一份实现自测自话。 */
function extractFunction(source, name) {
    const marker = `function ${name}(`;
    const start = source.indexOf(marker);
    if (start === -1) {
        throw new Error(`源码里找不到 \`${marker}\` —— 函数被改名或删除了？`);
    }
    const bodyStart = source.indexOf('{', start);
    if (bodyStart === -1) throw new Error(`\`function ${name}\` 缺少函数体`);
    let depth = 0;
    for (let i = bodyStart; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
    }
    throw new Error(`\`function ${name}\` 花括号未配对，无法提取`);
}

/** 抠出 apiReq 的头构造块：从 `function apiReq(` 到它的闭合括号。 */
function apiReqSource(source, name) {
    return extractFunction(source, name);
}

/**
 * 把 tenantSubdomain（及它依赖的 userInfo，如果有）装进一个带桩 sessionStorage
 * 的沙箱里执行。桩只实现 getItem —— 被测函数只读不写。
 */
function loadTenantSubdomain(source, store) {
    const deps = [];
    /* 被测函数会引用闭包顶部的字符串常量（如 var UK = 'geo_manual_publish_user'），
       不一起注入的话沙箱里会 ReferenceError。全量收编简单字符串常量声明。 */
    for (const m of source.matchAll(/^\s*var\s+([A-Za-z_$][\w$]*)\s*=\s*('[^']*'|"[^"]*")\s*;/gm)) {
        deps.push(`var ${m[1]} = ${m[2]};`);
    }
    if (source.includes('function userInfo(')) deps.push(extractFunction(source, 'userInfo'));
    deps.push(extractFunction(source, 'tenantSubdomain'));

    const factory = new Function('sessionStorage', `
        ${deps.join('\n')}
        return tenantSubdomain;
    `);
    return factory({ getItem: (k) => (k in store ? store[k] : null) });
}

let failures = 0;
let checks = 0;

function assert(label, actual, expected) {
    checks++;
    const ok = actual === expected;
    if (!ok) {
        failures++;
        console.log(`  ✗ ${label}\n      实际: ${JSON.stringify(actual)}\n      期望: ${JSON.stringify(expected)}`);
    }
    return ok;
}

console.log('\n独立面板 · X-Tenant 请求头测试\n');

for (const panel of PANELS) {
    const path = resolve(HERE, '../..', panel.file);
    const source = readFileSync(path, 'utf8');
    const before = failures;

    /* 面板各自的 user 存储键不同，从源码里认出来，别硬编码 */
    const userKey = (source.match(/sessionStorage\.setItem\(\s*(?:'([^']+)'|([A-Za-z_$][\w$]*))\s*,\s*JSON\.stringify/) || []);
    const keyLiteral = userKey[1]
        || (userKey[2] && (source.match(new RegExp(`var\\s+${userKey[2]}\\s*=\\s*'([^']+)'`)) || [])[1]);
    if (!keyLiteral) {
        failures++;
        console.log(`  ✗ ${panel.name}：认不出 user 的 sessionStorage 键名`);
        continue;
    }

    /* A. 有租户 → 返回 subdomain */
    const withTenant = loadTenantSubdomain(source, {
        [keyLiteral]: JSON.stringify({ id: 2, role: 'admin', tenant: { id: 4, subdomain: 'geo-b11cadee76' } }),
    });
    assert(`${panel.name} · 有租户时返回 subdomain`, withTenant(), 'geo-b11cadee76');

    /* A. 没有租户 / 空 / 坏 JSON → 空串，且不抛异常 */
    const noTenant = loadTenantSubdomain(source, { [keyLiteral]: JSON.stringify({ id: 2, role: 'admin' }) });
    assert(`${panel.name} · 无 tenant 字段时返回空串`, noTenant(), '');

    const empty = loadTenantSubdomain(source, {});
    assert(`${panel.name} · sessionStorage 为空时返回空串`, empty(), '');

    const broken = loadTenantSubdomain(source, { [keyLiteral]: '{不是合法 JSON' });
    assert(`${panel.name} · 坏 JSON 时返回空串而不是抛异常`, broken(), '');

    const nullTenant = loadTenantSubdomain(source, { [keyLiteral]: JSON.stringify({ tenant: null }) });
    assert(`${panel.name} · tenant 为 null 时返回空串`, nullTenant(), '');

    /* B. 静态断言：这个头真的被写进了 apiReq 的请求头 —— 防「写了函数没人调」 */
    const req = apiReqSource(source, panel.request);
    assert(`${panel.name} · ${panel.request} 里调用了 tenantSubdomain()`, /tenantSubdomain\s*\(\s*\)/.test(req), true);
    assert(`${panel.name} · ${panel.request} 里设置了 X-Tenant 头`, /['"]X-Tenant['"]\s*\]?\s*[=:]/.test(req), true);

    console.log(`  ${failures === before ? '✓' : '✗'} ${panel.name.padEnd(10)} ${panel.file}`);
}

// 线上 404 的真实触发路径：analytics 首屏 loadAll() 通过 apiGet() 发请求。
// 执行源码里的请求函数，确认 header 真到 fetch，而不只停留在字符串匹配。
const analytics = readFileSync(resolve(HERE, '../..', 'public/geo-analytics.js'), 'utf8');
const requests = [];
const runtimeStore = {
    geo_analytics_token: 'test-token',
    geo_analytics_user: JSON.stringify({ tenant: { subdomain: 'geo-33bd458d90' } }),
};
const analyticsApiGet = new Function('sessionStorage', 'fetch', [
    extractFunction(analytics, 'token'),
    extractFunction(analytics, 'tenantSubdomain'),
    extractFunction(analytics, 'apiGet'),
    'return apiGet;',
].join('\n'))(
    { getItem: key => runtimeStore[key] || null },
    async (path, options) => {
        requests.push({ path, options });
        return { status: 200, ok: true, json: async () => ({ data: [] }) };
    },
);
await analyticsApiGet('/api/v1/analytics/traffic-trend?days=30');
assert('AI 爬虫访问 · 真正发往 fetch 的请求携带租户', requests[0]?.options?.headers?.['X-Tenant'], 'geo-33bd458d90');

console.log('');
if (failures === 0) {
    console.log(`✓ 全部通过：${PANELS.length} 个面板与实际请求，共 ${checks} 项断言\n`);
    process.exit(0);
}
console.log(`✗ ${failures} 项失败（共 ${checks} 项）\n`);
process.exit(1);
