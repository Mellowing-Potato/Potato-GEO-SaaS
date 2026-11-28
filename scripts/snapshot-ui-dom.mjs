#!/usr/bin/env node
/**
 * 前端等价性指纹（计划 §7：`public/geo-entry.js` 拆分时的"行为一字未改"判据）。
 *
 * 为什么需要它：`geo-entry.js` 是一个 1.4 万行的 IIFE，按区块搬到独立文件时，
 * **没有任何单元测试能证明"搬完还一样"** —— 现有的 JS 测试读的是源码文本（grep 契约），
 * 读不出"某个视图渲染出来的东西变了"。这个工具用真 Chrome 把每个视图**跑一遍**，
 * 记下三样东西当指纹：
 *   1. 可见 DOM 结构（每个可见元素的 tag#id.class… 串起来取 sha256）；
 *   2. 这一趟发出的 API 请求（路径 + 方法，排序去重）—— 对应计划"API 请求契约不变"；
 *   3. 元素计数与 JS 异常数 —— 结构指纹太粗时它们能先报警。
 *
 * 用法：
 *   # 1) 先存基线（拆分前）
 *   node scripts/snapshot-ui-dom.mjs --cdp=http://127.0.0.1:9222 --base=http://127.0.0.1:8766 --write
 *   # 2) 拆完之后比对（不同就非零退出，并逐视图打印差异）
 *   node scripts/snapshot-ui-dom.mjs --cdp=http://127.0.0.1:9222 --base=http://127.0.0.1:8766
 *
 * 基线文件默认 `tests/fixtures/ui/dom-fingerprint.json`（跟着代码一起提交，
 * 这样"哪天有人改了渲染"在 CI 或本地都能被同一份基线抓住）。
 */
import fs from 'node:fs';
import path from 'node:path';

const args = Object.fromEntries(
    process.argv.slice(2).map((a) => {
        const [k, ...rest] = a.replace(/^--/, '').split('=');

        return [k, rest.join('=') || true];
    }),
);
const cdpEndpoint = String(args.cdp ?? 'http://127.0.0.1:9222');
const base = String(args.base ?? 'http://127.0.0.1:8766').replace(/\/$/, '');
const baselinePath = String(args.baseline ?? 'tests/fixtures/ui/dom-fingerprint.json');
const width = Number(args.width ?? 1440);

/** 与 verify-ui-panels 同一份视图清单：工作台 22 个视图 + 8 个独立壳。 */
const TARGETS = [
    ...['projects', 'skill', 'asset', 'content-link', 'content-library', 'categories', 'materials', 'automation', 'url-imports', 'knowledge', 'publishing', 'leads', 'analytics', 'usage', 'api-tokens', 'aikeys', 'sensitive-words', 'skill-prompts', 'platform']
        // api-tokens 于 2026-09-22 撤出租户工作台（用户口径：租户不得创建/吊销凭据），
        // 面板与侧栏入口都已移除 —— 这里同步去掉，否则会去点一个不存在的视图。
        .filter((view) => view !== 'content-link' && view !== 'api-tokens')
        // ⚠️ 与 verify-ui-panels 同因：项目页根节点没有 `panel` 类，写 `section.panel[...]` 会落空，
        //    下面那段 `|| document.body` 兜底会让整页（含侧栏）混进"项目屏"的指纹里 ——
        //    指纹看着有差异，其实量的不是这一屏。用 `section[data-workspace-view]` 才落到正确的范围。
        .map((view) => ({ name: `#${view}`, url: `${base}/#${view}`, scope: 'section[data-workspace-view]' })),
    { name: '可见性看板', url: `${base}/visibility/?project_id=901`, scope: 'body' },
    { name: '爬虫统计', url: `${base}/analytics/`, scope: 'body' },
    { name: '手动发布', url: `${base}/manual-publish/`, scope: 'body' },
    { name: '公开文章', url: `${base}/read-preview/`, scope: 'body' },
    { name: '公开表单', url: `${base}/form-preview/`, scope: 'body' },
    { name: '运营台', url: `${base}/platform/ops/?preview-platform-admin`, scope: 'body' },
];

const version = await (await fetch(`${cdpEndpoint}/json/version`)).json();
const socket = new WebSocket(version.webSocketDebuggerUrl);
let messageId = 0;
const pending = new Map();
const pageErrors = [];
let sessionId = null;
socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.id && pending.has(message.id)) {
        pending.get(message.id)(message);
        pending.delete(message.id);

        return;
    }
    if (message.method === 'Runtime.exceptionThrown' && message.sessionId === sessionId) {
        pageErrors.push(message.params?.exceptionDetails?.exception?.description ?? message.params?.exceptionDetails?.text ?? 'unknown');
    }
});
await new Promise((resolve) => socket.addEventListener('open', resolve));

const send = (method, params = {}) =>
    new Promise((resolve, reject) => {
        const id = ++messageId;
        pending.set(id, (message) => (message.error ? reject(new Error(`${method}: ${JSON.stringify(message.error)}`)) : resolve(message.result)));
        // sessionId 还没拿到（建目标那一步）时不能带这个字段：CDP 只接受字符串。
        socket.send(JSON.stringify(sessionId ? { id, method, params, sessionId } : { id, method, params }));
    });

const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
({ sessionId } = await send('Target.attachToTarget', { targetId, flatten: true }));
await send('Page.enable');
await send('Runtime.enable');
await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false });

// CSP 违规单独计数（2026-09-21）：预览沙盘现在按**强制模式**下发策略（见 preview-workspace-ui.php），
// 被拦下的内联脚本既不抛异常也不进 unhandledrejection —— 只在控制台留一句
// "Refused to execute inline script"，页面静默地少一段逻辑（最坏是白页）。
// 所以这里把 securitypolicyviolation 记进 pageErrors：指纹一比就会发现"这一屏变了"。
await send('Page.addScriptToEvaluateOnNewDocument', {
    source: `
      window.__cspViolations = window.__cspViolations || [];
      document.addEventListener('securitypolicyviolation', (e) => {
        window.__cspViolations.push((e.violatedDirective || '') + ' blocked=' + (e.blockedURI || '') + ' @' + (e.sourceFile || '') + ':' + (e.lineNumber || ''));
      });
    `,
});

const evaluate = async (expression) => {
    const { result, exceptionDetails } = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (exceptionDetails) throw new Error(`evaluate failed: ${exceptionDetails.exception?.description ?? exceptionDetails.text}`);

    return result.value;
};

const snapshot = {};

for (const target of TARGETS) {
    const errorsBefore = pageErrors.length;
    await send('Page.navigate', { url: target.url });
    await new Promise((resolve) => setTimeout(resolve, 1400));
    // 等"请求集合稳定"再取指纹。
    //
    // 为什么：视图的数据是几个加载器并行打出去的，其中技能目录由拆出块注册、要等
    // 会话建立后才发车。固定 sleep 偶尔会在它发出去之前就拍照 —— 指纹会因为
    // "少一次 GET /api/v1/skills"报差异，看起来像拆分把加载弄丢了（2026-09-21 遇到一次）。
    // 判据是"连续 3 次取样没有新请求"（最多再等 6 秒），比 sleep 长一点但只会更长不会更短，
    // 所以不会掩盖"真的少了一个请求"。
    {
        let last = -1;
        let stable = 0;
        const deadline = Date.now() + 6000;
        while (stable < 3 && Date.now() < deadline) {
            await new Promise((resolve) => setTimeout(resolve, 150));
            const count = await evaluate('(window.__uiPreviewRequests || []).length');
            if (count === last) stable++;
            else {
                stable = 0;
                last = count;
            }
        }
    }

    const facts = await evaluate(`(() => {
      const scope = ${JSON.stringify(target.scope)} === 'body'
        ? document.body
        : ([...document.querySelectorAll(${JSON.stringify(target.scope)})].find((s) => !s.hidden) || document.body);
      const describe = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') +
        (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\\s+/).join('.') : '');
      const visible = [...scope.querySelectorAll('*')].filter((el) => el.getClientRects().length > 0);
      const shape = visible.map(describe).join('|');
      let hash = 0;
      for (let i = 0; i < shape.length; i++) { hash = (Math.imul(31, hash) + shape.charCodeAt(i)) | 0; }
      // 文本也要进指纹：结构一样、文案变了（例如把"解析中"写成"提取中"）是最容易漏的一类回归。
      // 只取叶子元素的文本，父节点的文本是子节点的拼接，重复计算没有额外信息。
      const leafText = visible.map((el) => (el.children.length === 0 ? (el.textContent || '').trim() : '')).join('|');
      let textHash = 0;
      for (let i = 0; i < leafText.length; i++) { textHash = (Math.imul(31, textHash) + leafText.charCodeAt(i)) | 0; }
      const requests = (window.__uiPreviewRequests || []).map((r) => r.method + ' ' + r.path);

        const cspViolations = (window.__cspViolations || []).slice();

        return {
        hash: (hash >>> 0).toString(16) + ':' + shape.length,
        textHash: (textHash >>> 0).toString(16) + ':' + leafText.length,
        elementCount: visible.length,
        tables: visible.filter((el) => el.tagName === 'TABLE').length,
        buttons: visible.filter((el) => el.tagName === 'BUTTON').length,
        inputs: visible.filter((el) => ['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)).length,
        requests: [...new Set(requests)].sort(),
        cspViolations,
      };
    })()`);

    snapshot[target.name] = { ...facts, errors: pageErrors.length - errorsBefore };
}

await send('Target.closeTarget', { targetId });
socket.close();

const current = { base, width, capturedAt: new Date().toISOString(), views: snapshot };

if (args.write) {
    fs.mkdirSync(path.dirname(baselinePath), { recursive: true });
    fs.writeFileSync(baselinePath, JSON.stringify(current, null, 2) + '\n');
    console.log(`已写入基线：${baselinePath}（${Object.keys(snapshot).length} 个视图）`);
    process.exit(0);
}

if (!fs.existsSync(baselinePath)) {
    console.error(`找不到基线文件 ${baselinePath}；先跑一次 --write。`);
    process.exit(2);
}

const baseline = JSON.parse(fs.readFileSync(baselinePath, 'utf8'));
const diffs = [];
for (const [name, expected] of Object.entries(baseline.views)) {
    const actual = snapshot[name];
    if (!actual) { diffs.push(`${name}：新快照里没有这个视图`); continue; }
    for (const key of ['hash', 'textHash', 'elementCount', 'tables', 'buttons', 'inputs']) {
        if (expected[key] !== actual[key]) diffs.push(`${name}：${key} 期望 ${expected[key]}，实际 ${actual[key]}`);
    }
    const before = expected.requests.join('\n');
    const after = actual.requests.join('\n');
    if (before !== after) {
        const added = actual.requests.filter((r) => !expected.requests.includes(r));
        const removed = expected.requests.filter((r) => !actual.requests.includes(r));
        diffs.push(`${name}：API 请求变了（新增 ${added.length}：${added.slice(0, 3).join(' / ') || '—'}；减少 ${removed.length}：${removed.slice(0, 3).join(' / ') || '—'}）`);
    }
    if (actual.errors > 0) diffs.push(`${name}：这一趟抛了 ${actual.errors} 个 JS 异常`);
    // CSP 违规（2026-09-21）：预览沙盘按强制模式下发策略，被拦下的内联脚本是静默失效，
    // 不比对这一项就会"看着 0 差异、其实少跑了一段脚本"。基线里没有这个键时也按"应为空"判。
    const violations = actual.cspViolations || [];
    if (violations.length > 0) {
        diffs.push(`${name}：CSP 拦下 ${violations.length} 处（${violations.slice(0, 2).join(' / ')}）`);
    }
}

console.log(JSON.stringify({
    baseline: baseline.capturedAt,
    views: Object.keys(baseline.views).length,
    diffs: diffs.length,
    diffList: diffs,
}, null, 2));

process.exit(diffs.length ? 1 : 0);
