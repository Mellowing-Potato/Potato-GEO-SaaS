/**
 * 拆分文件的作用域护栏（计划 §7）。
 *
 * 为什么需要它：`geo-entry.js` 按区块搬到 `geo-entry-*.js` 之后，那些文件是**新的 IIFE**，
 * 主文件里闭包内的名字在那里并不存在。这一类错误有个共同点：静态语法检查（`node --check`）
 * 与页面加载都不报错，只有**真跑到那一行**才炸 —— 而且异常常常被 `try/catch` 吞掉，
 * 表现为"某个面板数据不加载"这种极难定位的现象。
 *
 * 2026-09-21 实测踩过两次（都是拆分时被真浏览器指纹抓出来的）：
 *   1. 第二刀：`formatDate` / `statusLabel` 是主文件里的 `function` 声明，拆出的文件没解构 →
 *      余额卡渲染成 "formatDate is not defined"；
 *   2. 第四刀：`kbDocPollers` 是主文件里的 `const … = new Map()`，拆出的 KB 轮询代码直接引用 →
 *      `stopKbPolling()` 抛 ReferenceError 把 `selectProject` 带崩，前端整体停在"等待项目"。
 *
 * 判据：**主文件里声明过的名字，拆分文件要么自己声明、要么从 `GEO` 解构**。
 * 这条判据不需要维护"浏览器全局变量白名单"，因为只拿两份源码对比 —— 精确、零误报风险。
 */
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(dirname(fileURLToPath(import.meta.url))));
const PUBLIC = join(ROOT, 'public');
const OK = [], FAIL = [];
const ok = (label, pass, detail = '') => {
    (pass ? OK : FAIL).push(label + (detail ? ` —— ${detail}` : ''));
    console.log(`  ${pass ? '✓' : '✗'} ${label}${detail ? ` —— ${detail}` : ''}`);
};

/** JS 关键字/字面量：它们不是"名字"，误判会让跨文件检查报出 `const` 这种假问题。 */
const KEYWORDS = new Set([
    'const', 'let', 'var', 'function', 'return', 'if', 'else', 'for', 'while', 'switch', 'case',
    'break', 'continue', 'new', 'typeof', 'instanceof', 'in', 'of', 'this', 'class', 'extends',
    'async', 'await', 'yield', 'try', 'catch', 'finally', 'throw', 'delete', 'void', 'null',
    'undefined', 'true', 'false', 'default', 'export', 'import', 'super', 'static', 'do',
]);

/**
 * 源码里声明的名字。
 *
 * `topLevelOnly` 只认**缩进两空格**的声明 —— 也就是主文件 IIFE 顶层的那些：
 * 只有它们在拆分文件里是不可见的。主文件函数体内的参数/局部变量（`p`、`span`、`a`…）
 * 与拆分文件无关，把它们算进来会造成成片误报（第一版就是这么翻车的）。
 */
function declaredNames(source, { topLevelOnly = false } = {}) {
    const names = new Set();
    const fnRe = topLevelOnly ? /^ {2}(?:async\s+)?function\s+([A-Za-z_$][\w$]*)/gm : /\bfunction\s+([A-Za-z_$][\w$]*)/g;
    const varRe = topLevelOnly ? /^ {2}(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=/gm : /\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=/g;
    const classRe = topLevelOnly ? /^ {2}class\s+([A-Za-z_$][\w$]*)/gm : /\bclass\s+([A-Za-z_$][\w$]*)/g;
    for (const m of source.matchAll(fnRe)) names.add(m[1]);
    for (const m of source.matchAll(varRe)) names.add(m[1]);
    for (const m of source.matchAll(classRe)) names.add(m[1]);
    if (topLevelOnly) {
        // 顶层的解构声明：`  const { a, b } = …` / `  const [a, b] = …`
        for (const m of source.matchAll(/^ {2}(?:const|let|var)\s*[{[][\s\S]*?[}\]]\s*=/gm)) {
            for (const part of m[0].split(/[,\s{}[\]:]+/).slice(1)) {
                const name = part.trim();
                if (/^[A-Za-z_$][\w$]*$/.test(name)) names.add(name);
            }
        }

        for (const keyword of KEYWORDS) names.delete(keyword);

        return names;
    }

    // 函数参数 / catch 参数 / 箭头函数参数
    for (const m of source.matchAll(/(?:function\s*[A-Za-z_$\w]*\s*|\)\s*=>\s*|\bcatch\s*)\(([^)]*)\)/g)) {
        for (const part of m[1].split(',')) {
            const name = part.trim().split(/[\s=:]/)[0].replace(/^\.\.\./, '');
            if (/^[A-Za-z_$][\w$]*$/.test(name)) names.add(name);
        }
    }
    // 解构声明 `const { a, b } = …` / `const [a, b] = …`
    for (const m of source.matchAll(/\b(?:const|let|var)\s*[{[][\s\S]*?[}\]]\s*=/g)) {
        for (const part of m[0].replace(/^[^{[]*[{[{]/, '').split(/[,\s{}[\]:]+/)) {
            const name = part.trim();
            if (/^[A-Za-z_$][\w$]*$/.test(name)) names.add(name);
        }
    }

    for (const keyword of KEYWORDS) names.delete(keyword);

    return names;
}

/**
 * 只留下“代码”，把注释 / 字符串 / 模板字面量 / 正则字面量的内容抹成空格。
 *
 * 必须走状态机而不是几个正则：模板串可以跨行、里面还能嵌 `${ … }`（甚至嵌套模板），
 * 正则字面量里也有 `$`、`token` 这类词 —— 用正则剥很容易漏（第一版就漏了，
 * 把字符串里的 "Sanctum token" 和正则里的 `$` 当成标识符，报了一堆假问题）。
 *
 * ⚠️ 模板插值 `${ … }` 里是**代码**，必须照常保留（例如 `${formatDate(x)}` 里的
 *    `formatDate` 正是护栏要检查的引用）；只有字面量文字部分才抹掉。
 */
function codeOnly(source) {
    const out = [];
    let i = 0;
    const n = source.length;
    let prevSignificant = '';
    const keep = (ch) => { out.push(ch); if (!/\s/.test(ch)) prevSignificant = ch; };
    const blank = (ch) => out.push(ch === '\n' ? '\n' : ' ');
    // 上下文栈：'code' 是普通代码；模板字面量与它的插值各自压一帧。
    const stack = [{ type: 'code' }];
    const isTemplate = () => stack[stack.length - 1].type === 'template';

    while (i < n) {
        const ch = source[i], next = source[i + 1];
        if (isTemplate()) {
            // 模板字面量的文字部分：抹掉；遇到 ${ 回到代码上下文
            if (ch === '\\') { blank(ch); blank(next ?? ''); i += 2; continue; }
            if (ch === '`') { blank(ch); stack.pop(); i++; prevSignificant = '`'; continue; }
            if (ch === '$' && next === '{') { blank(ch); blank(next); stack.push({ type: 'interp', depth: 0 }); i += 2; continue; }
            blank(ch); i++; continue;
        }

        if (stack[stack.length - 1].type === 'interp') {
            // 插值体内是代码，但要靠花括号配对找出它的结束
            if (ch === '{') { stack[stack.length - 1].depth++; keep(ch); i++; continue; }
            if (ch === '}') {
                if (stack[stack.length - 1].depth === 0) { blank(ch); stack.pop(); i++; continue; }
                stack[stack.length - 1].depth--; keep(ch); i++; continue;
            }
        }

        if (ch === '/' && next === '/') { while (i < n && source[i] !== '\n') { blank(source[i]); i++; } continue; }
        if (ch === '/' && next === '*') { blank(ch); blank(next); i += 2; while (i < n && !(source[i] === '*' && source[i + 1] === '/')) { blank(source[i]); i++; } if (i < n) { blank(source[i]); blank(source[i + 1]); i += 2; } continue; }
        if (ch === '"' || ch === "'") { blank(ch); i++; while (i < n && source[i] !== ch) { if (source[i] === '\\') { blank(source[i]); i++; } if (i < n) { blank(source[i]); i++; } } if (i < n) { blank(source[i]); i++; } continue; }
        if (ch === '`') { blank(ch); stack.push({ type: 'template' }); i++; prevSignificant = '`'; continue; }
        // 正则字面量：只在“表达式开头”的位置才可能是正则（前面是 = ( , : [ ! & | ? { ; return 等）
        if (ch === '/' && /[=(,:\[!&|?{};+\-*%<>~^]|^$/.test(prevSignificant)) {
            blank(ch); i++;
            let inClass = false;
            while (i < n) {
                const c = source[i];
                if (c === '\\') { blank(c); i++; if (i < n) { blank(source[i]); i++; } continue; }
                if (c === '[') inClass = true;
                else if (c === ']') inClass = false;
                else if (c === '/' && !inClass) { blank(c); i++; break; }
                else if (c === '\n') break;
                blank(c); i++;
            }
            while (i < n && /[a-z]/.test(source[i])) { blank(source[i]); i++; }
            continue;
        }
        keep(ch); i++;
    }

    return out.join('');
}

/** 源码里被引用的名字（排除属性访问 `a.b` 与对象字面量的键）。 */
function referencedNames(source) {
    const names = new Set();
    for (const m of source.matchAll(/(^|[^.\w$])([A-Za-z_$][\w$]*)/g)) names.add(m[2]);

    return names;
}

const mainSource = codeOnly(readFileSync(join(PUBLIC, 'geo-entry.js'), 'utf8'));
const mainDeclared = declaredNames(mainSource, { topLevelOnly: true });

/**
 * 主文件通过 `globalThis.GEO?.name` 取用、但声明在拆出文件里的名字（"薄转发"与跨文件调用）。
 *
 * 为什么也要查：`globalThis.GEO?.foo?.()` 这种写法**取不到就是 undefined**，调用会静默变成
 * 空操作 —— 页面不报错、什么都不发生（第九刀起主文件用薄转发承接二十多处技能工作区的调用，
 * 一旦拆出文件忘了把某个名字挂回 GEO，就会是这个形态）。所以：主文件取用的每个名字，
 * 必须能由**主文件自己的 GEO 导出表**或**某个拆出文件的 Object.assign(GEO, {…})** 提供。
 */
function geoReferencedInMain(mainCode) {
    const names = new Set();
    for (const m of mainCode.matchAll(/(?:globalThis|window)\.GEO\?\.([A-Za-z_$][\w$]*)/g)) names.add(m[1]);

    return names;
}

/** 拆出文件挂回 GEO 的名字（`Object.assign(GEO, { a, b: c, … })` 或 `GEO.a = …` 两种写法都有）。 */
function geoProvidedBySplit(raw) {
    const names = new Set();
    for (const m of raw.matchAll(/Object\.assign\(GEO,\s*\{([\s\S]*?)\}\s*\)/g)) {
        for (const part of m[1].split(',')) {
            const name = part.split(':')[0].trim();
            if (/^[A-Za-z_$][\w$]*$/.test(name)) names.add(name);
        }
    }
    for (const m of raw.matchAll(/GEO\.([A-Za-z_$][\w$]*)\s*=/g)) names.add(m[1]);

    return names;
}

/**
 * 主文件 `window.GEO` 导出表的键。
 *
 * 为什么还要查这个：上面那条判据只保证"名字在拆分文件里可见"（自己声明或从 GEO 解构），
 * 但**解构一个 GEO 里根本没有的键**同样会炸 —— 解构不报错，拿到的是 `undefined`，
 * 直到真调用它才 `xxx is not a function`。第六刀（发布与渠道）就是这么写出来的：
 * `copyText` / `statusText` / `statusVariant` 三个名字主文件里都有，但当时没进导出表。
 * 这条判据把"导出表和 destructuring 必须对得上"钉死，代价是零（还是两份源码对比）。
 */
function geoExportedKeys(mainCode) {
    const start = mainCode.indexOf('const GEO = {');
    if (start < 0) throw new Error('找不到主文件的 window.GEO 定义');
    const keys = new Set();
    const shorthand = [];
    for (const line of mainCode.slice(start).split('\n').slice(1)) {
        if (/^ {2}\};/.test(line)) break; // GEO 对象字面量收尾
        const m = line.match(/^ {4}([A-Za-z_$][\w$]*)\s*[,:(]/);
        if (!m) continue;
        keys.add(m[1]);
        // 简写导出 `name,` = 引用主文件作用域里的那个名字；`name: expr,` / 方法定义不算。
        if (/^ {4}[A-Za-z_$][\w$]*\s*,$/.test(line)) shorthand.push(m[1]);
    }

    return { keys, shorthand };
}

const { keys: geoKeys, shorthand: geoShorthand } = geoExportedKeys(mainSource);

const splitFiles = readdirSync(PUBLIC).filter((name) => /^geo-entry-.+\.js$/.test(name)).sort();
ok('找到了拆出去的入口文件', splitFiles.length >= 4, splitFiles.join(', '));
ok('主文件的 window.GEO 导出表读到了', geoKeys.size >= 20, `${geoKeys.size} 个键`);

/** 每个拆出文件顶层声明的名字（跨文件引用检查用）。 */
const splitDeclared = new Map();
for (const file of splitFiles) {
    splitDeclared.set(file, declaredNames(codeOnly(readFileSync(join(PUBLIC, file), 'utf8')), { topLevelOnly: true }));
}

/**
 * 反向那条：简写导出的每个名字，主文件里必须**真的声明过**。
 *
 * 为什么：简写 `copyText,` 等价于 `copyText: copyText` —— 名字不存在就是构建 GEO 时
 * `ReferenceError`，**整个 window.GEO 都建不出来**，六个拆分文件全体报"未就绪"，界面全空。
 * 第六刀真踩了：`statusText` / `statusVariant` 只是主文件里两个函数内的局部变量
 * （grep `const statusText` 会命中，但它们不是顶层声明），顺手写进导出表就炸了。
 */
{
    const orphans = geoShorthand.filter((name) => !mainDeclared.has(name));
    ok('window.GEO 简写导出的名字主文件都有声明', orphans.length === 0,
        orphans.length ? `主文件里没有顶层声明：${orphans.join(', ')}` : `${geoShorthand.length} 个简写键逐个对上`);
}

for (const file of splitFiles) {
    const raw = readFileSync(join(PUBLIC, file), 'utf8');
    const code = codeOnly(raw);
    const own = declaredNames(code);
    // 从 GEO 解构出来的名字也算"本文件可见"
    const geoBlock = raw.match(/const\s*\{([\s\S]*?)\}\s*=\s*GEO;/);
    // ⚠️ 解构块里带注释（"// 这两个是 KB…"），先剔注释再切词，否则注释里的
    //    英文单词会被当成解构出来的名字（第一版就报出了 "is" / "not" / "KB"）。
    const geoBlockCode = (geoBlock ? geoBlock[1] : '')
        .replace(/\/\/[^\n]*/g, ' ')
        .replace(/\/\*[\s\S]*?\*\//g, ' ');
    const fromGeo = new Set(geoBlockCode.split(/[,\s]+/).map((s) => s.trim()).filter((s) => /^[A-Za-z_$][\w$]*$/.test(s)));

    const offenders = [...referencedNames(code)].filter((name) => mainDeclared.has(name) && !own.has(name) && !fromGeo.has(name));
    ok(`${file}：没有引用主文件里未解构的名字`, offenders.length === 0,
        offenders.length ? `缺：${offenders.join(', ')}` : `${fromGeo.size} 个名字从 GEO 解构`);

    const notExported = [...fromGeo].filter((name) => !geoKeys.has(name));
    ok(`${file}：解构的每个名字 GEO 都真的导出了`, notExported.length === 0,
        notExported.length ? `GEO 里没有：${notExported.join(', ')}` : '与导出表逐一对上');

    // 跨拆出文件的引用：另一个拆出文件的顶层名字在这里也是不可见的，必须解构或走 GEO。
    const foreign = new Map();
    for (const [other, names] of splitDeclared) {
        if (other === file) continue;
        for (const name of names) foreign.set(name, other);
    }
    const crossOffenders = [...referencedNames(code)].filter((name) => foreign.has(name) && !own.has(name) && !fromGeo.has(name));
    ok(`${file}：没有裸引用其它拆出块里的名字`, crossOffenders.length === 0,
        crossOffenders.length ? `缺（住在 ${[...new Set(crossOffenders.map((n) => foreign.get(n)))].join('/')}）：${crossOffenders.join(', ')}` : '跨块引用都走 GEO');
}

/**
 * 主文件取用（`globalThis.GEO?.name`）的每个名字，必须有人提供。
 * 提供方 = 主文件自己的 GEO 导出表，或某个拆出文件的 `Object.assign(GEO, {…})`。
 */
{
    const provided = new Set(geoKeys);
    for (const file of splitFiles) {
        for (const name of geoProvidedBySplit(readFileSync(join(PUBLIC, file), 'utf8'))) provided.add(name);
    }
    const missing = [...geoReferencedInMain(mainSource)].filter((name) => !provided.has(name)).sort();
    ok('主文件 globalThis.GEO?.x 取用的名字都有人挂回 GEO', missing.length === 0,
        missing.length ? `没人提供：${missing.join(', ')}` : `${geoReferencedInMain(mainSource).size} 个名字都对上了提供方`);
}

console.log(`\n${FAIL.length ? '✗' : '✓'} ${OK.length} 项通过${FAIL.length ? `，${FAIL.length} 项失败` : ''}`);
if (FAIL.length) {
    console.error('\n失败项：\n' + FAIL.map((f) => '  ✗ ' + f).join('\n'));
    console.error('\n修法：在主文件里把该名字加到 window.GEO 的导出列表，并在拆分文件的 `const { … } = GEO;` 里解构它；' +
        '或者把该声明一起搬进拆分文件（如果主文件已经不需要它）。');
    process.exit(1);
}
assert.equal(FAIL.length, 0);
