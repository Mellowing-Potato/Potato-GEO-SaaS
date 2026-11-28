import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    CEILINGS, CSS_FILES, audit, splitSelectors, parseCssRules,
    componentClasses, isComponentSelector, componentRuleInventory,
} from '../../scripts/ui-component-audit.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
let checks = 0;
const check = (label, callback) => { callback(); checks++; console.log(`  ✓ ${label}`); };
const count = css => componentRuleInventory(css).length;

check('逗号只切顶层，保留嵌套伪类、属性字符串和转义', () => {
    assert.deepEqual(splitSelectors('.button, :is(.md-btn, :where(.btn, .plain)), [data-x="a,b"], .escaped\\,comma'),
        ['.button', ':is(.md-btn, :where(.btn, .plain))', '[data-x="a,b"]', '.escaped\\,comma']);
});
check('解析器保留注释、字符串与数据 URL 中的标点和真实源偏移', () => {
    const source = '/* .btn {color:red} */\n@supports (display: grid) {\n.button, :is(.md-btn, .plain) { content: "};/*text*/"; background: url("data:a;b,{c}"); color: red; }\n}';
    const rules = parseCssRules(source);
    assert.equal(rules.length, 1);
    const rule = rules[0];
    assert.equal(rule.line, 3);
    assert.equal(rule.endLine, 3);
    assert.deepEqual(rule.contexts, ['@supports (display: grid)']);
    assert.deepEqual(rule.selectors, ['.button', ':is(.md-btn, .plain)']);
    assert.deepEqual(rule.declarations.map(item => item.property), ['content', 'background', 'color']);
    assert.equal(rule.declarations[0].value, '"};/*text*/"');
    assert.equal(source.slice(rule.declarations[2].start, rule.declarations[2].end), 'color: red;');
    assert.equal(source.slice(rule.start, rule.selectorEnd).trim(), rule.selector);
    assert.equal(source[rule.end - 1], '}');
});
check('括号中的共用选择器整项命中，一条规则只计一次', () => {
    const rules = componentRuleInventory('.panel :is(.button, .link-btn), .plain { font-size:14px; color:red; }');
    assert.equal(rules.length, 1);
    assert.deepEqual(rules[0].matchedSelectors, ['.panel :is(.button, .link-btn)']);
    assert.deepEqual(rules[0].otherSelectors, ['.plain']);
    assert.deepEqual(rules[0].appearanceProperties, ['font-size', 'color']);
});
for (const selector of [
    '.md-btn', '.md-btn--primary', '.button.primary', '.btn.mini', '.md-iconbtn',
    '.md-tabs', '.md-tab', '.md-tab-count', '.skill-workspace-tabs button',
    '.mat-seg-tabs button', '.cl-seg-tabs button', '.kb-seg-tabs', '.kb-seg-tab',
    '.md-segmented', '.md-seg', '.segmented', '.segmented-item', '.filter-chip', '.md-chip',
    '.md-alert--danger', '.callout', '.md-empty', '.md-empty--sm',
    '.md-empty-title', '.empty', '.cl-state-empty .st-icon', '.lead-drawer-empty',
    '.md-panel', '.md-panel-head', '.md-panel-body', '.md-badge--primary',
    '.md-stat', '.billing-metric', '.billing-metric-label', '.usage-card strong',
]) {
    check(`授权组件计入：${selector}`, () => assert.equal(count(`${selector} { color:red; }`), 1));
}
for (const selector of [
    '.cl-state-tpl', '.link-btn', '.format-link', '.form-message', 'label', 'input',
    '.upgrade-message', '.project-item', '.history-item', '.skill-card', '.stage-node',
    '.ops-state-box', '.state-box', '.state-tpl', '.result-tab', '.report-slider-tab',
    '.project-nav-empty', '.workspace-command-empty', '.billing-metric-grid',
    '.small', '.mini', '.button-row', '.usage-cards', '.buttonish',
    '[data-url=".button"]', '.plain:not(.button)', '.plain:has(.button)',
]) {
    check(`未授权/外壳/布局类不误计：${selector}`, () => {
        assert.equal(isComponentSelector(selector), false);
        assert.equal(count(`${selector} { color:red; display:flex; }`), 0);
    });
}
check('只读布局声明不计；border/font/outline等子属性与display都不能漏过', () => {
    assert.equal(count('.button {margin:4px; width:100%; min-width:0; max-width:90%; flex:1; align-self:center; justify-content:start; grid-column:1; order:2; white-space:nowrap; overflow:auto; text-align:left;}'), 0);
    for (const property of ['height', 'min-height', 'padding-block', 'border-bottom-color', 'background-image', 'font-family', 'line-height', 'letter-spacing', 'box-shadow', 'opacity', 'outline-offset', 'cursor', 'display']) {
        assert.equal(count(`.button { ${property}:initial; }`), 1, property);
    }
});
check('组件后代的display不当作组件本体display', () => {
    assert.equal(count('.md-alert p {display:block;}'), 0);
    assert.equal(count('.md-alert p {font-size:14px;}'), 1);
    assert.equal(count('.panel :is(.button, .btn) {display:inline-flex;}'), 1);
    assert.equal(count('.panel :is(.md-alert p, .plain) {display:block;}'), 0);
    assert.equal(count(':is(.md-alert p, .btn) {display:block;}'), 1);
});
check('隐藏行为保留，但混入的其他外观声明仍会被抓到', () => {
    assert.equal(count('.button[hidden] {display:none !important;} .callout:empty {display:none;}'), 0);
    assert.equal(count('.button[hidden] {display:none; color:red;}'), 1);
    assert.equal(count('.button[hidden], .btn {display:flex;}'), 1);
    assert.equal(count('.button:not([hidden]) {display:flex;}'), 1);
    assert.equal(count('.button:not([hidden]) {display:none;}'), 1);
});
check('仅排除max-width手机media，嵌套media/supports也正确；min-width不豁免', () => {
    assert.equal(count('@media (max-width:640px) { @supports (display:grid) { .button {font-size:20px;} } }'), 0);
    assert.equal(count('@supports (display:grid) { @media screen and (max-width:960px) { .btn {color:red;} } }'), 0);
    assert.equal(count('@media (min-width:960px) {.button {color:red;}}'), 1);
    assert.equal(count('@media (pointer:coarse) {.button {min-height:44px;}}'), 1);
});
check('移除共用选择器中的授权项后只剩非授权外观不会误报', () => {
    assert.equal(count('.button, .format-link {color:red;}'), 1);
    assert.equal(count('.format-link {color:red;}'), 0);
    assert.deepEqual(componentClasses('.panel :is(.button, .link-btn)'), ['button']);
});
check('未闭合CSS不能静默审计成0', () => assert.throws(() => parseCssRules('.button {color:red;'), SyntaxError));

const fixture = mkdtempSync(join(tmpdir(), 'geo-component-audit-'));
try {
    mkdirSync(join(fixture, 'public'));
    mkdirSync(join(fixture, 'resources/views'), { recursive: true });
    for (const file of CSS_FILES) writeFileSync(join(fixture, 'public', file), '');
    writeFileSync(join(fixture, 'public/geo-entry.css'), '.button {color:red;padding:2px 0;} @media (max-width:640px) {.btn {padding:3px;box-shadow:0 0 1px red;}}');
    writeFileSync(join(fixture, 'public/geo-components.css'), '.geo-app .button {padding:var(--md-space-2);box-shadow:var(--md-shadow-xs);}');
    writeFileSync(join(fixture, 'resources/views/test.blade.php'), '<p style="color:red"></p>');
    writeFileSync(join(fixture, 'public/geo-entry.js'), 'x.style.display="none";x.style.width="1px";');
    check('原五项仍含手机规则/新组件文件；组件归属排除新组件文件', () => {
        const report = audit(fixture);
        assert.equal(report.metrics.componentOwnershipCount, 1);
        assert.equal(report.metrics.spaceLit, 2);
        assert.equal(report.metrics.shadowCount, 2);
        assert.equal(report.spaceTok, 1);
        assert.equal(report.metrics.bladeInline, 1);
        assert.equal(report.metrics.jsInline, 1);
    });
} finally {
    rmSync(fixture, { recursive: true, force: true });
}

const report = audit(root);
for (const [metric, ceiling] of Object.entries(CEILINGS)) {
    check(`${metric}: ${report.metrics[metric]} ≤ ${ceiling}`, () => assert(report.metrics[metric] <= ceiling,
        `${metric} increased to ${report.metrics[metric]} (ceiling ${ceiling}); inspect the listed rules instead of widening the scope or ceiling`));
}
check('spaceTok只作信息，不设置限制替换令牌的上限', () => assert.equal(Object.hasOwn(CEILINGS, 'spaceTok'), false));
console.log(`\n组件审计通过：${checks} 项断言；${JSON.stringify(report.metrics)}；spaceTok=${report.spaceTok}（信息）。`);
