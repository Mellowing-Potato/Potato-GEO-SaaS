#!/usr/bin/env node
/**
 * 线索表单管理台 · 渲染转义 前端回归测试（单 8 第 5 步）
 *
 * 为什么要这个测试
 * ────────────────
 * 公开页那侧的 XSS 已有 L8 挡着（Blade 的 {{ }} 自动转义 + JS 只用 textContent）。
 * 但管理台这侧是**另一条路径**：表单名称、字段标签、访客填的内容都要拼进 innerHTML
 * 的表格与抽屉里，Blade 帮不上忙，全靠每一处都记得过 escapeHtml。
 * 漏一处的后果比公开页更重——公开页只坑访客自己，管理台是**租户 A 填的文案打到
 * 本租户运营的浏览器里**，而线索内容还是任何匿名访客都能提交的。
 * 这类「少写一个 escapeHtml」人眼极难复查，必须有自动断言盯着。
 *
 * 为什么是「提取源码」而不是「复制实现」
 * ──────────────────────────────────
 * 沿用 manual-publish-timezone.test.mjs 立的规矩：geo-entry.js 是 IIFE 闭包、
 * 无模块导出，项目红线又不许引打包器。抄一份实现来测等于没测（生产代码一改就失真），
 * 所以用括号配对从**真实源文件**里把函数抠出来跑，测的就是线上那份代码。
 *
 * 运行
 * ────
 *   node tests/js/lead-admin-ui.test.mjs
 *   npm run test:js
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { readEntrySource } from './helpers/entry-source.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));

/**
 * 设 LEAD_SOURCE 可指向副本 —— 用于「把代码改回漏转义的版本，确认本测试确实会红」的自检。
 *
 * 不设时读 **"主文件 + 所有拆出块"的合集**（tests/js/helpers/entry-source.mjs）：
 * 2026-09-21 §7 第七刀把线索管理台整块搬进了 public/geo-entry-leads.js，
 * 只读 geo-entry.js 会找不到 LEAD_STATUS_LABEL 这些常量。自检时如果只想盯某一份文件，
 * 把 LEAD_SOURCE 指向那份文件即可（那时不再拼合集）。
 */
const SOURCE_PATH = process.env.LEAD_SOURCE ? resolve(process.env.LEAD_SOURCE) : null;

/** 攻击载荷：闭合属性 + 起新标签，两种常见形态一起试 */
const XSS = '"><script>alert(1)</script>';

function extractFunction(source, name) {
    const marker = `function ${name}(`;
    const start = source.indexOf(marker);
    if (start === -1) throw new Error(`源码里找不到 \`${marker}\` —— 函数被改名或删除了？`);
    const bodyStart = source.indexOf('{', start);
    if (bodyStart === -1) throw new Error(`\`function ${name}\` 缺少函数体`);
    let depth = 0;
    for (let i = bodyStart; i < source.length; i++) {
        const ch = source[i];
        if (ch === '{') depth++;
        else if (ch === '}') {
            depth--;
            if (depth === 0) return source.slice(start, i + 1);
        }
    }
    throw new Error(`\`function ${name}\` 花括号未配对，无法提取`);
}

function extractConst(source, name) {
    const marker = `const ${name} = `;
    const start = source.indexOf(marker);
    if (start === -1) throw new Error(`源码里找不到 \`${marker}\` —— 常量被改名或删除了？`);
    const open = source.indexOf(source[source.indexOf('=', start) + 2] === '[' ? '[' : '{', start);
    const openCh = source[open];
    const closeCh = openCh === '[' ? ']' : '}';
    let depth = 0;
    for (let i = open; i < source.length; i++) {
        const ch = source[i];
        if (ch === openCh) depth++;
        else if (ch === closeCh) {
            depth--;
            if (depth === 0) return source.slice(start, i + 1) + ';';
        }
    }
    throw new Error(`\`const ${name}\` 括号未配对，无法提取`);
}

function loadRenderers() {
    const source = SOURCE_PATH ? readFileSync(SOURCE_PATH, 'utf8') : readEntrySource();
    const factory = new Function(`
        ${extractConst(source, 'LEAD_STATUS_LABEL')}
        ${extractConst(source, 'LEAD_STATUS_VARIANT')}
        ${extractConst(source, 'LEAD_FIELD_TYPES')}
        ${extractConst(source, 'LEAD_NOTIFY_LABEL')}
        ${extractConst(source, 'LEAD_NOTIFY_VARIANT')}
        ${extractFunction(source, 'escapeHtml')}
        ${extractFunction(source, 'formatDate')}
        ${extractFunction(source, 'leadSummaryText')}
        ${extractFunction(source, 'leadNotifyCellHtml')}
        ${extractFunction(source, 'leadRowHtml')}
        ${extractFunction(source, 'leadFieldRowHtml')}
        ${extractFunction(source, 'validateLeadFields')}
        ${extractFunction(source, 'leadDrawerContentHtml')}
        return { escapeHtml, leadRowHtml, leadNotifyCellHtml, leadFieldRowHtml, validateLeadFields, leadDrawerContentHtml, LEAD_STATUS_LABEL };
    `);
    return factory();
}

// ───────────────────────── 迷你断言器（沿用单 7 那份的形状）─────────────────────────

const results = [];

function check(name, fn) {
    try { fn(); results.push({ name, ok: true }); }
    catch (error) { results.push({ name, ok: false, message: error.message }); }
}

function assertNoRawScript(html, hint) {
    if (html.includes('<script>')) throw new Error(`${hint}：渲染结果里出现了未转义的 <script> 标签`);
    if (/<\/script>/.test(html)) throw new Error(`${hint}：渲染结果里出现了未转义的 </script> 闭合标签`);
}

function assertEscapedPresent(html, hint) {
    if (!html.includes('&lt;script&gt;')) throw new Error(`${hint}：没有找到转义后的 &lt;script&gt;，说明这段内容压根没被渲染或被丢了`);
}

function assertContains(html, needle, hint) {
    if (!html.includes(needle)) throw new Error(`${hint}：期望包含 ${JSON.stringify(needle)}`);
}

// ───────────────────────── 用例 ─────────────────────────

const R = loadRenderers();

check('线索行：表单名称里的 <script> 被转义', () => {
    const html = R.leadRowHtml({ id: 1, status: 'new', created_at: '2026-08-29T10:00:00+08:00', form: { name: XSS }, source_url: null, handler_name: null });
    assertNoRawScript(html, '线索行表单名');
    assertEscapedPresent(html, '线索行表单名');
});

check('线索行：来源 URL 里的 <script> 被转义（含 title 属性那一份）', () => {
    const html = R.leadRowHtml({ id: 2, status: 'converted', created_at: '2026-08-29T10:00:00+08:00', form: { name: '正常表单' }, source_url: XSS, handler_name: null });
    assertNoRawScript(html, '线索行来源');
    // 这个值同时进了 title 属性和单元格文本，两处都必须转义
    if ((html.match(/&lt;script&gt;/g) || []).length < 2) throw new Error('来源 URL 只转义了一处：title 属性或单元格文本漏了');
});

check('线索行：处理人姓名里的 <script> 被转义', () => {
    const html = R.leadRowHtml({ id: 3, status: 'new', created_at: '2026-08-29T10:00:00+08:00', form: { name: 'x' }, source_url: null, handler_name: XSS });
    assertNoRawScript(html, '线索行处理人');
});

check('线索行：未知状态回落成原值，不是空白', () => {
    const html = R.leadRowHtml({ id: 4, status: 'brand_new_status', created_at: '2026-08-29T10:00:00+08:00', form: { name: 'x' }, source_url: null, handler_name: null });
    assertContains(html, 'brand_new_status', '未知状态');
});

check('通知格：旧线索（notify_status 为空）显示占位，不显示「未配置」', () => {
    // 通知功能上线之前的线索一律没有这个字段。写成「未配置」是撒谎——
    // 那些线索当年根本没有通知这回事。
    const html = R.leadNotifyCellHtml({ notify_status: null });
    if (html !== '-') throw new Error(`旧线索通知格应为 "-"，实际：${html}`);
});

check('通知格：失败原因进 title 属性时被转义', () => {
    const html = R.leadNotifyCellHtml({ notify_status: 'failed', notify_error: '"><script>alert(1)</script>' });
    assertContains(html, '通知失败', '失败标签');
    if (html.includes('<script>')) throw new Error('失败原因未转义，闭合 title 属性后可注入标签');
});

check('通知格：成功与失败用不同的 badge 变体，失败必须显眼', () => {
    const sent = R.leadNotifyCellHtml({ notify_status: 'sent' });
    const failed = R.leadNotifyCellHtml({ notify_status: 'failed' });
    assertContains(sent, 'data-variant="success"', '已通知');
    assertContains(failed, 'data-variant="danger"', '通知失败');
});

check('线索行：表单被删（form=null）显示占位，不炸', () => {
    const html = R.leadRowHtml({ id: 5, status: 'new', created_at: null, form: null, source_url: null, handler_name: null });
    assertContains(html, '表单已删除', '表单已删除占位');
});

check('字段编辑器：字段标签与选项里的 <script> 被转义', () => {
    const html = R.leadFieldRowHtml({ name: XSS, label: XSS, type: 'select', required: true, options: [XSS] }, 0);
    assertNoRawScript(html, '字段编辑器');
    assertEscapedPresent(html, '字段编辑器');
});

check('字段编辑器：非选择类字段的选项框隐藏', () => {
    const text = R.leadFieldRowHtml({ name: 'a', label: 'A', type: 'text', required: false, options: [] }, 0);
    assertContains(text, 'lead-field-options', '选项框存在');
    if (!/lead-field-options[^>]*hidden/.test(text)) throw new Error('单行文本字段的选项框应当 hidden');
    const select = R.leadFieldRowHtml({ name: 'b', label: 'B', type: 'select', required: false, options: ['x'] }, 1);
    if (/lead-field-options[^>]*hidden/.test(select)) throw new Error('下拉字段的选项框不该 hidden');
});

check('字段编辑器：明确区分访客可见标题和系统标识', () => {
    const html = R.leadFieldRowHtml({ name: 'name', label: '姓名', type: 'text', required: true, options: [] }, 0);
    assertContains(html, '字段标题（访客可见）', '字段标题说明');
    assertContains(html, '系统标识（可留空）', '系统标识说明');
    assertContains(html, '填写方式', '字段类型说明');
});

check('字段编辑器：错误的系统标识在发请求前给出可操作提示', () => {
    const chinese = R.validateLeadFields([{ label: '姓名', name: '赢', type: 'text', required: true }]);
    assertContains(chinese, '请留空让系统自动生成', '中文系统标识');
    const phoneNumber = R.validateLeadFields([{ label: '电话', name: '13800000028', type: 'phone', required: true }]);
    assertContains(phoneNumber, '格式不正确', '数字开头系统标识');
    const valid = R.validateLeadFields([{ label: '电话', name: 'phone', type: 'phone', required: true }]);
    if (valid !== '') throw new Error(`合法字段不应报错，实际：${valid}`);
});

check('详情抽屉：访客提交的字段名与内容里的 <script> 被转义', () => {
    // 字段名由租户定义、内容由匿名访客提交——两侧都是不可信输入
    const html = R.leadDrawerContentHtml({
        payload: { [XSS]: XSS },
        form: { name: XSS },
        created_at: '2026-08-29T10:00:00+08:00',
        source_url: XSS,
        ip_address: '1.2.3.4',
        handled_at: null,
        handler_name: null,
    });
    assertNoRawScript(html, '详情抽屉');
    assertEscapedPresent(html, '详情抽屉');
});

check('详情抽屉：布尔值渲染成是/否，不是 true/false', () => {
    const html = R.leadDrawerContentHtml({ payload: { agree: true, deny: false }, form: null, created_at: null, source_url: null, ip_address: null, handled_at: null, handler_name: null });
    assertContains(html, '是', '布尔真');
    assertContains(html, '否', '布尔假');
    if (html.includes('>true<')) throw new Error('布尔值不该原样渲染成 true');
});

check('详情抽屉：空 payload 不炸，给出明确提示', () => {
    const html = R.leadDrawerContentHtml({ payload: {}, form: null, created_at: null, source_url: null, ip_address: null, handled_at: null, handler_name: null });
    assertContains(html, '没有字段内容', '空 payload 提示');
});

// ───────────────────────── 报告 ─────────────────────────

const failed = results.filter((r) => !r.ok);
for (const r of results) {
    console.log(`${r.ok ? '  ✓' : '  ✗'} ${r.name}${r.ok ? '' : `\n      ${r.message}`}`);
}
console.log(`\n线索管理台渲染转义：${results.length - failed.length}/${results.length} 通过`);
process.exit(failed.length === 0 ? 0 : 1);
