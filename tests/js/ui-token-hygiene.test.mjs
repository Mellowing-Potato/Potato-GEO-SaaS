// EXEC-09B B0：新测试只验证桌面 token 卫生口径，不改写批次 A 的既有断言。
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { CSS_FILES, parseCssRules, desktopTokenInventory } from '../../scripts/ui-component-audit.mjs';

let checks = 0;
const check = (label, callback) => { callback(); checks++; console.log(`  ✓ ${label}`); };
const count = (source, metric) => desktopTokenInventory(source)[metric].length;

check('阴影允许清单逐项通过，状态色不能接受未授权语气', () => {
    const allowed = ['none', 'var(--md-shadow-xs)', 'var(--md-shadow-sm)', 'var(--md-shadow-modal)', 'var(--md-focus-shadow)',
        'inset 3px 0 0 transparent', ...['danger', 'warning', 'primary', 'success'].map(tone => `inset 3px 0 0 var(--md-${tone})`)];
    for (const value of allowed) assert.equal(count(`.sample {box-shadow:${value};}`, 'shadowDisallowed'), 0, value);
    assert.equal(count('.sample {box-shadow:var(--md-focus-shadow) !important;}', 'shadowDisallowed'), 0);
    assert.equal(count('.sample {box-shadow:inset 3px 0 0 var(--md-info);}', 'shadowDisallowed'), 1);
    assert.equal(count('.sample {box-shadow:0 0 0 3px var(--md-focus-ring);}', 'shadowDisallowed'), 1);
    assert.equal(count('.sample {box-shadow:var(--md-shadow-xs), var(--md-shadow-sm);}', 'shadowDisallowed'), 1);
});
check('阴影按声明计数，重复字面量也分别计数，注释不计', () => {
    assert.equal(count('/* box-shadow:bad; */ .a {box-shadow:0 0 1px red;} .b {box-shadow:0 0 1px red;}', 'shadowDisallowed'), 2);
});
check('圆角按声明计数；各角、分数、calc中的px不漏；0和50%不计', () => {
    assert.equal(count('.sample {border-radius:2px 2px 0 0;border-top-left-radius:6px; border-bottom-right-radius:calc(1px + 50%);}', 'radiusLiteral'), 3);
    assert.equal(count('.sample {border-radius:.5px;}', 'radiusLiteral'), 1);
    assert.equal(count('.sample {border-radius:0px 50%;border-top-right-radius:var(--md-radius-sm);}', 'radiusLiteral'), 0);
    assert.equal(count('.sample {border-width:4px;}', 'radiusLiteral'), 0);
});
check('别名统计非md声明，不把使用或白名单md令牌算成定义', () => {
    assert.equal(count(':root {--panel:var(--md-surface);--reader-width:1280px;--md-font-serif:serif;} .a{color:var(--panel);}', 'aliasDefs'), 2);
});
check('间距按px token计数，含负值/逻辑属性/calc，例外按数值而非文件豁免', () => {
    const source = '.sample {padding:2px 4px;margin-inline-start:-6px;gap:calc(100% - 8px);row-gap:.5px;column-gap:48px;padding-block:1.5px;}';
    assert.equal(count(source, 'spaceLitDesktop'), 7);
    const inventory = desktopTokenInventory('.sample {margin:0px 1px -1px 49px; padding:84px -60px;gap:48px;}');
    assert.deepEqual(inventory.spacingExceptions.map(row => row.px), [0, 1, -1, 49, 84, -60]);
    assert.deepEqual(inventory.spaceLitDesktop.map(row => row.px), [48]);
});
check('任意深度max-width media整块排除；min-width/pointer规则仍计桌面', () => {
    const declarations = 'box-shadow:0 0 2px red;border-radius:7px;--panel:white;margin:2px;';
    for (const source of [`@media (max-width:640px){@supports(display:grid){.a{${declarations}}}}`,
        `@supports(display:grid){@media screen and (max-width:960px){.a{${declarations}}}}`]) {
        for (const metric of ['shadowDisallowed', 'radiusLiteral', 'aliasDefs', 'spaceLitDesktop']) assert.equal(count(source, metric), 0, metric);
    }
    const source = `@media(min-width:960px){.a{${declarations}}} @media(pointer:coarse){.b{${declarations}}}`;
    for (const metric of ['shadowDisallowed', 'radiusLiteral', 'aliasDefs', 'spaceLitDesktop']) assert.equal(count(source, metric), 2, metric);
});
check('审计证据保留原文件与声明偏移，便于核对具体换值', () => {
    const source = '/* ignored */\n:is(.a,.b) { margin: -2px; }';
    const [row] = desktopTokenInventory(source, 'example.css').spaceLitDesktop;
    assert.equal(row.file, 'example.css');
    assert.equal(row.selector, ':is(.a,.b)');
    assert.equal(row.line, 2);
    assert.equal(source.slice(row.start, row.end), 'margin: -2px;');
    assert.equal(row.px, -2);
});

// B3b / §11 裁定 11.4：所有 var 引用都要有定义，兜底值不构成豁免。
function references(value) {
    const found = [];
    let quote = '';
    for (let i = 0; i < value.length; i++) {
        const char = value[i];
        if (quote) {
            if (char === '\\') i++;
            else if (char === quote) quote = '';
        } else if (char === '"' || char === "'") quote = char;
        else {
            const match = (i === 0 || !/[\w-]/.test(value[i - 1]))
                && value.slice(i).match(/^var\(\s*(--[A-Za-z_][\w-]*)\s*(?=[,)])/);
            if (match) found.push(match[1]);
        }
    }
    return found;
}

check('变量引用解析包含嵌套兜底，字符串内容不冒充var函数', () => {
    assert.deepEqual(references('var( --first, var(--second, var(--third, #fff)))'), ['--first', '--second', '--third']);
    assert.deepEqual(references('"var(--literal)" notvar(--ignored) var(--actual)'), ['--actual']);
});
check('范围内每个var都解析到共享令牌或ENTRY_ONLY定义，不留未定义豁免', () => {
    const root = fileURLToPath(new URL('../..', import.meta.url));
    const read = path => readFileSync(resolve(root, path), 'utf8');
    const tokenRules = parseCssRules(read('public/geo-tokens.css'));
    const sharedDefinitions = new Set(tokenRules.filter(rule => rule.selectors.includes(':root'))
        .flatMap(rule => rule.declarations.filter(row => row.property.startsWith('--md-')).map(row => row.property)));
    assert(sharedDefinitions.size > 0, '共享令牌定义不能为空');
    const php = read('tests/Feature/DesignTokenSingleCopyTest.php');
    const entryList = php.match(/private const ENTRY_ONLY\s*=\s*\[([\s\S]*?)\]/)?.[1];
    assert.notEqual(entryList, undefined, 'ENTRY_ONLY 白名单必须从既有测试中读取');
    const entryOnly = [...entryList.matchAll(/['"](--[\w-]+)['"]/g)].map(match => match[1]);
    const entryDefinitions = new Set(parseCssRules(read('public/geo-entry.css'))
        .filter(rule => rule.selectors.includes(':root'))
        .flatMap(rule => rule.declarations.map(row => row.property)));
    for (const variable of entryOnly) assert(entryDefinitions.has(variable), `${variable} 白名单也必须实际定义`);
    const defined = new Set([...sharedDefinitions, ...entryOnly]);
    const dangling = [];
    for (const file of [...CSS_FILES, 'geo-tokens.css']) {
        for (const rule of parseCssRules(read(`public/${file}`))) {
            for (const declaration of rule.declarations) {
                for (const variable of references(declaration.value)) {
                    if (!defined.has(variable)) dangling.push(`${file}:${declaration.line} ${variable} (${rule.selector})`);
                }
            }
        }
    }
    assert.deepEqual(dangling, [], `未定义的变量引用（含嵌套fallback）：\n${dangling.join('\n')}`);
});

console.log(`\nToken 卫生边界通过：${checks} 项断言。`);
