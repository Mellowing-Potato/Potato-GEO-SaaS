#!/usr/bin/env node
/**
 * 手动发布面板 · 计划时间时区往返 前端回归测试
 *
 * 为什么要这个测试
 * ────────────────
 * 2026-08-29 人工验收第 7 条发现：计划时间填写 14:30，保存后重开编辑显示 22:30，
 * 整整差 8 小时。根因是「读侧修了、写侧没修」的半边工程——
 *   - 读侧 isoToLocalInput() 早就用 new Date() 按本地时区渲染（注释里写着 A 坑1）；
 *   - 写侧却直接把 datetime-local 的裸串「2026-09-01T14:30」丢给后端，
 *     而后端 app.timezone=UTC，当成 UTC 存了。存读两套基准 → 漂移。
 * 这类 bug 靠人眼极难发现（页面看起来一切正常），必须有自动断言盯着。
 *
 * 为什么是「提取源码」而不是「复制实现」
 * ──────────────────────────────────
 * 面板 JS（public/geo-manual-publish.js）是 IIFE 闭包 + 原生脚本，没有模块导出，
 * 也无法引入打包器（项目红线：不引 UI 框架 / 打包器）。若在本文件里抄一份实现来测，
 * 生产代码一改测试就失真，等于没测。
 * 所以这里用括号配对从**真实源文件**里把两个函数抠出来执行，测的就是线上跑的那份代码。
 *
 * 为什么要在多个时区下跑
 * ──────────────────────
 * 时区 bug 的本质就是「换个基准就露馅」。只在 UTC 下往返一致，不代表在 UTC+8 下也对。
 * 本脚本对每个候选时区 fork 一个子进程重跑，任何一个时区挂掉就算失败。
 *
 * 运行
 * ────
 *   node tests/js/manual-publish-timezone.test.mjs
 *   npm run test:js
 */

import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));

/**
 * 被测源文件路径。默认指向仓库里的真实文件；
 * 设 MP_SOURCE 可指向任意副本——用于「把代码改回 buggy 版本，确认本测试确实会失败」的自检。
 */
const SOURCE_PATH = process.env.MP_SOURCE
    ? resolve(process.env.MP_SOURCE)
    : resolve(HERE, '../../public/geo-manual-publish.js');

/** 覆盖有代表性的时区：正偏移 / 零偏移 / 负偏移 / 半小时偏移 / 有夏令时的地区 */
const TIMEZONES = [
    'Asia/Shanghai',      // UTC+8  （验收实测环境，无夏令时）
    'UTC',                // UTC+0  （后端 app.timezone，最容易「看起来对」）
    'Asia/Kolkata',       // UTC+5:30（半小时偏移，整点算法容易错）
    'America/New_York',   // UTC-5/-4（负偏移 + 夏令时）
    'Europe/London',      // UTC+0/+1（夏令时切换）
    'Pacific/Kiritimati', // UTC+14 （全球最早，跨天幅度最大）
];

/** 往返一致性用例：value 是 datetime-local 的裸值（无时区） */
const ROUND_TRIP_CASES = [
    '2026-09-01T00:00', // 午夜
    '2026-09-01T00:30', // ← 最易露馅：本地凌晨，UTC 需退回前一日
    '2026-09-01T08:05',
    '2026-09-01T09:15',
    '2026-09-01T14:30', // 验收实测用例
    '2026-09-01T23:45', // ← 深夜，UTC 可能进到次日
    '2026-01-01T00:00', // 年初
    '2026-12-31T20:00', // 年末
    '2026-02-28T23:59', // 平年 2 月末
    '2028-02-29T12:00', // 闰日
    '2026-11-01T01:30', // 美国夏令时回拨日（该时刻出现两次，JS 取第一次，往返稳定）
    // 注：2026-03-08T02:30（美国夏令时切换日，该时刻不存在）不在此列，
    //     见下方 DST_GAP_CASES——它必然被折算，只能要求「同日」，不能要求完全一致。
];

/**
 * 夏令时「空洞」时刻：本地时钟会从 02:00 直接跳到 03:00，02:30 这一天根本不存在。
 * 这是 JS Date 的固有行为（自动折到 03:30），纯函数层无法也不应强行纠正——
 * 要真正解决必须在 UI 层拦截并提示用户「该时刻不存在」。
 *
 * 但必须守住一条底线：**折算后不得跨天**。
 * 因为修复前的表现正是跨天灾难（America/New_York 下 02:30 会回显成 03-07T21:30），
 * 修复后已收敛为同日 03:30。这条断言就是防止那种跨天回归再次发生。
 */
const DST_GAP_CASES = ['2026-03-08T02:30'];

// ───────────────────────── 从真实源码提取函数 ─────────────────────────

/** 去掉块注释与行注释，用于后续静态分析（避免注释里的字样干扰断言） */
function stripComments(src) {
    return src
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

/**
 * 用花括号配对从源码中抠出一个具名函数的完整定义。
 * 只适用于函数体内不含「字符串/正则里出现未配对花括号」的写法——
 * 本测试针对的两个函数都是纯日期逻辑，满足该前提；若将来不满足，此处会抛错提示。
 */
function extractFunction(source, name) {
    const marker = `function ${name}(`;
    const start = source.indexOf(marker);
    if (start === -1) {
        throw new Error(`源码里找不到 \`${marker}\` —— 函数被改名或删除了？`);
    }

    const bodyStart = source.indexOf('{', start);
    if (bodyStart === -1) {
        throw new Error(`\`function ${name}\` 缺少函数体`);
    }

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

/** 把两个函数从源码抠出来，组装成可直接调用的对象 */
function loadTimeHelpers() {
    const source = readFileSync(SOURCE_PATH, 'utf8');
    const factory = new Function(`
        ${extractFunction(source, 'isoToLocalInput')}
        ${extractFunction(source, 'scheduledPayload')}
        return { isoToLocalInput, scheduledPayload };
    `);
    return factory();
}

// ───────────────────────── 迷你断言器 ─────────────────────────

const results = [];

function check(name, fn) {
    try {
        fn();
        results.push({ name, ok: true });
    } catch (error) {
        results.push({ name, ok: false, message: error.message });
    }
}

function assertEqual(actual, expected, hint = '') {
    if (actual !== expected) {
        throw new Error(`${hint}期望 ${JSON.stringify(expected)}，实际 ${JSON.stringify(actual)}`);
    }
}

function assertTrue(value, hint = '') {
    if (!value) throw new Error(hint || '期望为真');
}

// ───────────────────────── 子进程：跑单个时区下的全部断言 ─────────────────────────

function runInThisTimezone() {
    const { isoToLocalInput, scheduledPayload } = loadTimeHelpers();
    const tz = process.env.TZ || Intl.DateTimeFormat().resolvedOptions().timeZone;

    // A. 往返一致：填入 → 提交 → 回显，必须回到原值
    for (const value of ROUND_TRIP_CASES) {
        check(`往返一致 ${value}`, () => {
            const submitted = scheduledPayload(value);
            assertTrue(submitted !== null, `提交值不应为 null`);
            assertTrue(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(submitted),
                `提交值应是带 Z 的 ISO 串，实际 ${submitted}`);
            assertEqual(isoToLocalInput(submitted), value, `回显漂移：`);
        });
    }

    // B. 夏令时空洞时刻：允许折算，但必须同日（不得重演修复前的跨天灾难）
    for (const value of DST_GAP_CASES) {
        check(`夏令时空洞 ${value} 允许折算但须同日`, () => {
            const submitted = scheduledPayload(value);
            assertTrue(submitted !== null && /Z$/.test(submitted),
                `应返回合法 ISO，实际 ${submitted}`);
            const back = isoToLocalInput(submitted);
            assertEqual(back.slice(0, 10), value.slice(0, 10),
                `夏令时折算后跨天了（${value} → ${back}），重演了修复前的 bug：`);
        });
    }

    // C. 空值与非法值：不能产生 1970-01-01 / Invalid Date 这类脏值
    check('空值处理', () => {
        assertEqual(scheduledPayload(''), null, '空串应提交 null：');
        assertEqual(scheduledPayload(null), null, 'null 应提交 null：');
        assertEqual(scheduledPayload(undefined), null, 'undefined 应提交 null：');
        assertEqual(isoToLocalInput(''), '', '空串回显应是空串：');
        assertEqual(isoToLocalInput(null), '', 'null 回显应是空串：');
        assertEqual(isoToLocalInput(undefined), '', 'undefined 回显应是空串：');
    });

    check('非法日期不产生脏值', () => {
        assertEqual(scheduledPayload('不是日期'), null, '非法值应提交 null：');
        assertEqual(isoToLocalInput('不是日期'), '', '非法值回显应是空串：');
        assertEqual(isoToLocalInput('2026-13-45T99:99'), '', '越界日期回显应是空串：');
    });

    // D. 存值语义：提交值转回本地时间后，必须与用户填的一致（换个角度再验一次）
    check('提交值可被后端正确还原', () => {
        const value = '2026-09-01T14:30';
        const stored = scheduledPayload(value);
        const back = new Date(stored);
        const pad = (n) => String(n).padStart(2, '0');
        const local = `${back.getFullYear()}-${pad(back.getMonth() + 1)}-${pad(back.getDate())}`
            + `T${pad(back.getHours())}:${pad(back.getMinutes())}`;
        assertEqual(local, value, 'UTC 存值还原回本地时间后不一致：');
    });

    // E. 防回归：第 3 条并发修复——409 分支不得再调用 showDetail 把提示冲掉
    check('并发冲突提示未被 showDetail 冲掉（第 3 条）', () => {
        const source = stripComments(readFileSync(SOURCE_PATH, 'utf8'));
        const idx = source.indexOf('已被其他人修改');
        assertTrue(idx !== -1, '源码里找不到 409 冲突分支，「已被其他人修改」提示被删了？');
        const window = source.slice(idx, idx + 400);
        assertTrue(!window.includes('showDetail('),
            '409 分支内又出现了 showDetail( —— 它会把刚写入的提示当场冲掉，用户零感知');
    });

    // F. 防回归：两处调用点都必须走 scheduledPayload，不能有人绕过
    check('新建/编辑两处调用点都用了 scheduledPayload', () => {
        const source = stripComments(readFileSync(SOURCE_PATH, 'utf8'));
        const calls = source.match(/scheduled_at:\s*[^,\n]+/g) || [];
        assertEqual(calls.length, 2, `应恰好有 2 处 scheduled_at 赋值（新建 + 编辑），实际 ${calls.length} 处：`);
        for (const call of calls) {
            assertTrue(call.includes('scheduledPayload('),
                `调用点「${call.trim()}」没有走 scheduledPayload，会重新引入时区漂移`);
        }
    });

    return { tz, results };
}

// ───────────────────────── 父进程：逐时区 fork 汇总 ─────────────────────────

function main() {
    const failures = [];
    let total = 0;

    console.log(`\n计划时间时区往返测试  源文件: public/geo-manual-publish.js\n`);

    for (const tz of TIMEZONES) {
        const child = spawnSync(process.execPath, [fileURLToPath(import.meta.url)], {
            env: { ...process.env, TZ: tz, MP_TZ_CHILD: '1' },
            encoding: 'utf8',
        });

        if (child.status !== 0) {
            console.log(`  ✗ ${tz.padEnd(20)} 子进程异常（退出码 ${child.status}）`);
            if (child.stderr) console.log(child.stderr.trim().split('\n').map((l) => `      ${l}`).join('\n'));
            failures.push({ tz, name: '子进程异常', message: child.stderr || '' });
            continue;
        }

        let payload;
        try {
            payload = JSON.parse(child.stdout.trim().split('\n').pop());
        } catch {
            console.log(`  ✗ ${tz.padEnd(20)} 无法解析子进程输出`);
            failures.push({ tz, name: '输出解析失败', message: child.stdout });
            continue;
        }

        const bad = payload.results.filter((r) => !r.ok);
        total += payload.results.length;
        if (bad.length === 0) {
            console.log(`  ✓ ${tz.padEnd(20)} ${payload.results.length} 项断言全过`);
        } else {
            console.log(`  ✗ ${tz.padEnd(20)} ${bad.length}/${payload.results.length} 项失败`);
            for (const f of bad) failures.push({ tz, ...f });
        }
    }

    console.log('');
    if (failures.length > 0) {
        console.log(`失败明细（共 ${failures.length} 项）：`);
        for (const f of failures) {
            console.log(`  · [${f.tz}] ${f.name}`);
            console.log(`      ${f.message}`);
        }
        console.log(`\n✗ 时区回归测试未通过（${total} 项断言，${failures.length} 项失败）\n`);
        process.exit(1);
    }

    console.log(`✓ 全部通过：${TIMEZONES.length} 个时区 × 各 ${total / TIMEZONES.length} 项断言 = ${total} 项\n`);
}

if (process.env.MP_TZ_CHILD === '1') {
    process.stdout.write(JSON.stringify(runInThisTimezone()));
} else {
    main();
}
