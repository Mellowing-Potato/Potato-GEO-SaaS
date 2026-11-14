import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { audit, CEILINGS, parseCssRules, splitSelectors, componentClasses } from '../../scripts/ui-component-audit.mjs';

const root = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const read = path => readFileSync(resolve(root, path), 'utf8');
const css = read('public/geo-components.css');
const rules = parseCssRules(css);
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
for (const rule of rules) {
    check(splitSelectors(rule.selector).every(selector => /^(?:body)?\.geo-app\s/.test(selector)), `scope: ${rule.selector}`);
}
const hidden = rules[0];
check(hidden.selector.includes('[hidden]'), 'hidden reset is first');
check(hidden.declarations.some(d => d.property === 'display' && d.value === 'none !important'), 'hidden wins against component display');
for (const component of ['md-btn', 'md-link', 'button', 'btn', 'md-iconbtn', 'md-tab', 'md-seg', 'filter-chip', 'callout', 'md-panel', 'md-empty', 'billing-metric', 'usage-card']) {
    check(hidden.selector.includes('.' + component), `hidden reset includes ${component}`);
}
for (const page of ['welcome', 'analytics', 'visibility', 'manual-publish', 'theme-package']) {
    const source = read(`resources/views/${page}.blade.php`);
    const files = [...source.matchAll(/<link\b[^>]*href="\/([^?"/]+\.css)/g)].map(m => m[1]);
    const tokens = files.indexOf('geo-tokens.css');
    check(tokens >= 0 && files[tokens + 1] === 'geo-shell.css' && files[tokens + 2] === 'geo-components.css', `${page}: tokens → shell → components`);
    const body = [...source.matchAll(/<body\s[^>]*class=[^>]*>/g)].at(-1)?.[0] || '';
    check(/\bgeo-app\b/.test(body) && (page === 'theme-package' || !/\bmd-app\b/.test(body)), `${page}: geo-app scope, no newly added md-app`);
}
const componentNames = [...new Set(rules.slice(1).flatMap(rule => rule.selectors.flatMap(componentClasses)))];
for (const name of componentNames) {
    check(hidden.selector.includes('.' + name), `explicit hidden coverage: ${name}`);
}
for (const rule of rules.slice(1)) {
    for (const declaration of rule.declarations) {
        check(!/(?:#[0-9a-f]{3,8}\b|rgba?\(|hsla?\()/i.test(declaration.value), `no literal color in ${rule.selector}: ${declaration.property}`);
    }
}
for (const alias of ['.button.primary', '.btn.primary', '.filter-chip', '.callout', '.segmented-item']) {
    check(css.includes(alias), `legacy alias retained: ${alias}`);
}
check(!css.includes('md-btn--soft'), 'soft variant removed after authorized usage migration');
check(CEILINGS.componentOwnershipCount === 0 && audit(root).metrics.componentOwnershipCount === 0, 'all authorized component appearance lives in one file');
const tokens = read('public/geo-tokens.css');
for (const name of ['focus-shadow', 'chart-1', 'chart-2', 'chart-3']) {
    check((tokens.match(new RegExp(`--md-${name}\\s*:`, 'g')) || []).length === 1, `${name}: declared once`);
}
check(/--md-focus-shadow:\s*0 0 0 3px var\(--md-focus-ring\)/.test(tokens), 'focus token uses existing ring');
console.log(`UI components: ${checks} checks passed`);
