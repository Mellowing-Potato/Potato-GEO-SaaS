// EXEC-09A / §8-7、EXEC-09B / B0：组件归属、原静态指标与桌面 token 卫生护栏。
// 用法：node scripts/ui-component-audit.mjs [仓库根目录]
// 无第三方依赖；导出的解析器保留源文件偏移，便于逐声明审查。
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

export const CSS_FILES = Object.freeze([
    'geo-base.css', 'geo-shell.css', 'geo-page-navigation.css', 'geo-entry.css',
    'geo-workspace-ui.css', 'geo-report-reader.css', 'geo-analytics.css',
    'geo-visibility.css', 'geo-manual-publish.css',
    'geo-components.css', 'geo-project-home.css',
]);

// 61ae322 实测：30 种阴影、8 处超限圆角、1385 个间距 px、26 个别名、
// Blade/JS 内联 46/94；组件外观归属 178 条规则（§8-7，含 display）。
// spaceTok=292 仅作信息，
// 换用令牌会使它增长，故不设上限。上限只许下调；上调必须同提交说明原因。
export const CEILINGS = Object.freeze({
    shadowCount: 8,
    radiiOverCount: 0,
    spaceLit: 93,
    aliasCount: 0,
    bladeInline: 28,
    jsInline: 35,
    componentOwnershipCount: 0, // A4：基线 178 → 135 → 79 → 38 → 0。
    // B0：e02ba96 实测；只计桌面声明，批次 B 终点四项均为 0。
    shadowDisallowed: 0,
    radiusLiteral: 0,
    aliasDefs: 0,
    spaceLitDesktop: 0,
});

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** 去注释但保留长度/换行；字符串里的注释标记不受影响。 */
function maskComments(source) {
    let out = '', quote = '';
    for (let i = 0; i < source.length; i++) {
        const c = source[i];
        if (quote) {
            out += c;
            if (c === '\\' && i + 1 < source.length) out += source[++i];
            else if (c === quote) quote = '';
        } else if (c === '"' || c === "'") {
            quote = c; out += c;
        } else if (c === '/' && source[i + 1] === '*') {
            out += '  '; i += 2;
            while (i < source.length && !(source[i] === '*' && source[i + 1] === '/')) {
                out += source[i] === '\n' ? '\n' : ' '; i++;
            }
            if (i < source.length) { out += '  '; i++; }
        } else out += c;
    }
    return out;
}

function splitRanges(source, delimiter) {
    const ranges = [];
    let start = 0, parens = 0, brackets = 0, quote = '';
    for (let i = 0; i < source.length; i++) {
        const c = source[i];
        if (quote) {
            if (c === '\\') i++;
            else if (c === quote) quote = '';
        } else if (c === '"' || c === "'") quote = c;
        else if (c === '\\') i++;
        else if (c === '(') parens++;
        else if (c === ')') parens--;
        else if (c === '[') brackets++;
        else if (c === ']') brackets--;
        else if (c === delimiter && parens === 0 && brackets === 0) {
            ranges.push([start, i]); start = i + 1;
        }
    }
    ranges.push([start, source.length]);
    return ranges;
}

/** 只在顶层逗号切分，不拆 :is/:where/:not、属性值或转义逗号。 */
export function splitSelectors(selector) {
    return splitRanges(maskComments(selector), ',').map(([a, b]) => selector.slice(a, b).trim()).filter(Boolean);
}

export function parseCssRules(source) {
    const clean = maskComments(source);
    const rules = [];
    const lineAt = offset => clean.slice(0, offset).split('\n').length;
    function scan(start, end, contexts) {
        let i = start;
        while (i < end) {
            while (i < end && /\s/.test(clean[i])) i++;
            if (i >= end) return;
            const headStart = i;
            let parens = 0, brackets = 0, quote = '';
            for (; i < end; i++) {
                const c = clean[i];
                if (quote) {
                    if (c === '\\') i++;
                    else if (c === quote) quote = '';
                } else if (c === '"' || c === "'") quote = c;
                else if (c === '\\') i++;
                else if (c === '(') parens++;
                else if (c === ')') parens--;
                else if (c === '[') brackets++;
                else if (c === ']') brackets--;
                else if (!parens && !brackets && (c === '{' || c === ';')) break;
            }
            if (i >= end) return;
            if (clean[i] === ';') { i++; continue; }
            const open = i;
            let depth = 1; quote = ''; i++;
            for (; i < end && depth; i++) {
                const c = clean[i];
                if (quote) {
                    if (c === '\\') i++;
                    else if (c === quote) quote = '';
                } else if (c === '"' || c === "'") quote = c;
                else if (c === '\\') i++;
                else if (c === '{') depth++;
                else if (c === '}') depth--;
            }
            if (depth) throw new SyntaxError(`Unclosed CSS block at line ${lineAt(headStart)}`);
            const close = i - 1;
            const selector = clean.slice(headStart, open).trim();
            if (selector.startsWith('@')) {
                // @font-face/page 的声明不是组件规则；keyframe 选择器也不是组件。
                if (/^@(media|supports|layer|container|scope|document)\b/i.test(selector)) {
                    scan(open + 1, close, [...contexts, selector]);
                }
                continue;
            }
            const body = clean.slice(open + 1, close);
            const declarations = [];
            for (const [a, b] of splitRanges(body, ';')) {
                const text = body.slice(a, b);
                const match = text.match(/^\s*([\w-]+)\s*:\s*([\s\S]*?)\s*$/);
                if (!match) continue;
                const declarationStart = open + 1 + a + text.search(/\S/);
                declarations.push({
                    property: match[1].toLowerCase(), value: match[2],
                    start: declarationStart, end: open + 1 + b + (body[b] === ';' ? 1 : 0),
                    line: lineAt(declarationStart),
                });
            }
            rules.push({
                selector, selectors: splitSelectors(selector), declarations, contexts,
                start: headStart, selectorEnd: open, bodyStart: open + 1,
                bodyEnd: close, end: i, line: lineAt(headStart), endLine: lineAt(close),
            });
        }
    }
    scan(0, clean.length, []);
    return rules;
}

// 只认授权组件的完整类名；.small/.mini 只有与 .button/.btn 同项时才是按钮。
// .cl-state-tpl、.link-btn、.format-link、表单/守卫态等未授权族明确不包含。
const COMPONENT_CLASS = /^(?:md-btn(?:--[\w-]+)?|md-iconbtn|md-link|button|btn|md-tabs?|md-tab-count|skill-workspace-tabs|mat-seg-tabs|cl-seg-tabs|kb-seg-tabs?|md-segmented|md-seg|segmented(?:-item)?|md-chip|filter-chip|md-alert(?:--[\w-]+)?|callout|md-empty(?:--[\w-]+|-title)?|empty|cl-state-empty|md-panel(?:-[\w-]+)?|md-stat(?:-[\w-]+)?|md-badge(?:--[\w-]+)?|billing-metric(?:-label)?|usage-card)$/;

// :not 内的类表示排除，:has 内的类表示后代；都不是本项的组件类。
function positiveSelector(selector, keepAttributes = false) {
    let out = '';
    for (let i = 0; i < selector.length; i++) {
        if (selector.slice(i).match(/^:(?:not|has)\(/i)) {
            i += 5;
            let depth = 1, quote = '';
            for (; i < selector.length && depth; i++) {
                const c = selector[i];
                if (quote) {
                    if (c === '\\') i++;
                    else if (c === quote) quote = '';
                } else if (c === '"' || c === "'") quote = c;
                else if (c === '(') depth++;
                else if (c === ')') depth--;
            }
            i--; continue;
        }
        out += selector[i];
    }
    // 属性里的 class 字符串或 href=".button" 不构成类选择器。
    return keepAttributes ? out : out.replace(/\[(?:[^\]"']|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')*\]/g, '');
}

export function componentClasses(selector) {
    return [...positiveSelector(selector).matchAll(/\.([A-Za-z_][\w-]*)/g)]
        .map(match => match[1]).filter(name => COMPONENT_CLASS.test(name));
}

export function isComponentSelector(selector) {
    return componentClasses(selector).length > 0;
}

export function targetsComponentBody(selector) {
    const positive = positiveSelector(selector);
    // 找最后一个顶层复合选择器；伪类内部空格不是后代连接符。
    let start = 0, depth = 0;
    for (let i = 0; i < positive.length; i++) {
        if (positive[i] === '(') depth++;
        else if (positive[i] === ')') depth--;
        else if (!depth && /[\s>+~]/.test(positive[i])) start = i + 1;
    }
    const compound = positive.slice(start);
    // :is(.md-alert p, .plain) 的主体是 p/plain，不能因为祖先类而计 display。
    for (let i = 0; i < compound.length; i++) {
        const pseudo = compound.slice(i).match(/^:([\w-]+)\(/);
        if (pseudo) {
            const open = i + pseudo[0].length;
            let end = open, nesting = 1, quote = '';
            for (; end < compound.length && nesting; end++) {
                const c = compound[end];
                if (quote) {
                    if (c === '\\') end++;
                    else if (c === quote) quote = '';
                } else if (c === '"' || c === "'") quote = c;
                else if (c === '(') nesting++;
                else if (c === ')') nesting--;
            }
            if (/^(is|where)$/i.test(pseudo[1])
                && splitSelectors(compound.slice(open, end - 1)).some(targetsComponentBody)) return true;
            i = end - 1;
        } else if (compound[i] === '.') {
            const name = compound.slice(i + 1).match(/^[A-Za-z_][\w-]*/)?.[0];
            if (name && COMPONENT_CLASS.test(name)) return true;
        }
    }
    return false;
}

export function isAppearanceProperty(property) {
    return /^(?:height$|min-height$|padding(?:-|$)|border(?:-|$)|background(?:-|$)|color$|font(?:-|$)|line-height$|letter-spacing$|box-shadow$|opacity$|outline(?:-|$)|cursor$)/.test(property);
}

export function componentRuleInventory(source, file = '') {
    return parseCssRules(source).flatMap(rule => {
        if (rule.contexts.some(context => /^@media\b/i.test(context) && /\bmax-width\s*:/i.test(context))) return [];
        const matchedSelectors = rule.selectors.filter(selector => componentClasses(selector).length);
        if (!matchedSelectors.length) return [];
        const appearances = rule.declarations.filter(({ property, value }) => {
            if (isAppearanceProperty(property)) return true;
            if (property !== 'display') return false;
            return matchedSelectors.some(selector => targetsComponentBody(selector)
                && !(/(?:\[hidden(?:\s*=[^\]]+)?\]|:empty\b)/i.test(positiveSelector(selector, true))
                    && /^none(?:\s*!important)?$/i.test(value)));
        });
        if (!appearances.length) return [];
        return [{ ...rule, file, matchedSelectors, otherSelectors: rule.selectors.filter(selector => !matchedSelectors.includes(selector)), appearanceProperties: [...new Set(appearances.map(item => item.property))] }];
    });
}

/** B0：仅排除祖先 max-width media；保留文件、选择器和声明偏移作审查证据。 */
export function desktopTokenInventory(source, file = '') {
    const inventory = { shadowDisallowed: [], radiusLiteral: [], aliasDefs: [], spaceLitDesktop: [], spacingExceptions: [] };
    const allowedShadow = /^(?:none|var\(--md-(?:shadow-(?:xs|sm|modal)|focus-shadow)\)|inset 3px 0 0 (?:transparent|var\(--md-(?:danger|warning|primary|success)\)))$/;
    const pxTokens = value => [...value.matchAll(/(?<![\w.-])-?(?:\d+(?:\.\d+)?|\.\d+)px\b/g)]
        .map(match => ({ token: match[0], px: Number.parseFloat(match[0]) }));
    for (const rule of parseCssRules(source)) {
        if (rule.contexts.some(context => /^@media\b/i.test(context) && /\bmax-width\s*:/i.test(context))) continue;
        for (const declaration of rule.declarations) {
            const { property, value } = declaration;
            const row = { file, selector: rule.selector, ...declaration };
            const normalized = value.replace(/\s*!important\s*$/i, '').trim().replace(/\s+/g, ' ');
            if (property === 'box-shadow' && !allowedShadow.test(normalized)) inventory.shadowDisallowed.push(row);
            if (/^border(?:-(?:top|bottom)-(?:left|right))?-radius$/.test(property)
                && pxTokens(value).some(({ px }) => px !== 0)) inventory.radiusLiteral.push(row);
            if (/^--(?!md-)/.test(property)) inventory.aliasDefs.push(row);
            if (/^(?:margin|padding|gap|row-gap|column-gap)(?:-[a-z]+)*$/.test(property)) {
                for (const token of pxTokens(value)) {
                    // B4 明确例外：0、±1px 细线补偿、绝对值大于 48px 的版面尺寸。
                    const isException = Math.abs(token.px) === 0 || Math.abs(token.px) === 1 || Math.abs(token.px) > 48;
                    inventory[isException ? 'spacingExceptions' : 'spaceLitDesktop'].push({ ...row, ...token });
                }
            }
        }
    }
    return inventory;
}

export function audit(root = ROOT) {
    const shadows = new Map(), radiiOver = [], spacing = new Map(), aliasDefs = new Map(), aliasUses = new Map();
    const componentRules = [];
    const tokenInventory = { shadowDisallowed: [], radiusLiteral: [], aliasDefs: [], spaceLitDesktop: [], spacingExceptions: [] };
    let spaceTok = 0, spaceLit = 0;
    for (const file of CSS_FILES) {
        const path = join(root, 'public', file);
        if (!existsSync(path)) {
            if (file === 'geo-components.css') continue;
            throw new Error(`Missing audit CSS: ${path}`);
        }
        const source = readFileSync(path, 'utf8');
        for (const [metric, rows] of Object.entries(desktopTokenInventory(source, file))) tokenInventory[metric].push(...rows);
        // 原五项逐字沿用 ui-static-audit 的口径，包含手机 media 和新组件文件。
        const text = source.replace(/\/\*[\s\S]*?\*\//g, '');
        for (const match of text.matchAll(/box-shadow\s*:\s*([^;}]+)/g)) {
            const value = match[1].trim().replace(/\s+/g, ' ');
            if (value !== 'none') shadows.set(value, [...(shadows.get(value) || []), file]);
        }
        for (const match of text.matchAll(/border(?:-[a-z]+)?-radius\s*:\s*([^;}]+)/g)) {
            for (const px of match[1].matchAll(/(\d+(?:\.\d+)?)px/g)) {
                if (+px[1] > 8 && +px[1] < 999) radiiOver.push({ file, value: `${px[1]}px` });
            }
        }
        for (const match of text.matchAll(/(?:^|[;{\s])(margin|padding|gap|row-gap|column-gap)(?:-[a-z]+)?\s*:\s*([^;}]+)/g)) {
            spaceTok += (match[2].match(/var\(--md-space/g) || []).length;
            for (const px of match[2].matchAll(/(?<![\w-])(\d+(?:\.\d+)?)px/g)) {
                if (px[1] === '0') continue;
                spaceLit++; spacing.set(px[1], (spacing.get(px[1]) || 0) + 1);
            }
        }
        for (const match of text.matchAll(/(?:^|[{;])\s*(--(?!md-)[a-z][a-z0-9-]*)\s*:/g)) {
            aliasDefs.set(match[1], new Set([...(aliasDefs.get(match[1]) || []), file]));
        }
        for (const match of text.matchAll(/var\((--(?!md-)[a-z][a-z0-9-]*)/g)) aliasUses.set(match[1], (aliasUses.get(match[1]) || 0) + 1);
        if (file !== 'geo-components.css') componentRules.push(...componentRuleInventory(source, file));
    }
    const walk = directory => readdirSync(directory, { withFileTypes: true }).flatMap(entry => entry.isDirectory() ? walk(join(directory, entry.name)) : [join(directory, entry.name)]);
    const blades = walk(join(root, 'resources/views')).filter(file => file.endsWith('.blade.php'));
    const bladeInline = blades.reduce((count, file) => count + (readFileSync(file, 'utf8').match(/\sstyle="/g) || []).length, 0);
    const js = readdirSync(join(root, 'public')).filter(file => file.endsWith('.js') && !/^geo-(workbench|platform-ops|login)/.test(file));
    const jsInline = js.reduce((count, file) => count + (readFileSync(join(root, 'public', file), 'utf8').match(/style="|\.style\.(?!display\b)[a-zA-Z]+\s*=|\.style\.cssText|setProperty\(/g) || []).length, 0);
    return {
        metrics: { shadowCount: shadows.size, radiiOverCount: radiiOver.length, spaceLit, aliasCount: aliasDefs.size, bladeInline, jsInline, componentOwnershipCount: componentRules.length,
            shadowDisallowed: tokenInventory.shadowDisallowed.length, radiusLiteral: tokenInventory.radiusLiteral.length,
            aliasDefs: tokenInventory.aliasDefs.length, spaceLitDesktop: tokenInventory.spaceLitDesktop.length },
        spaceTok,
        details: {
            shadows: [...shadows].map(([value, files]) => ({ value, files: [...new Set(files)] })),
            radiiOver, spacingValues: Object.fromEntries(spacing),
            aliases: [...aliasDefs].map(([name, files]) => ({ name, files: [...files], uses: aliasUses.get(name) || 0 })),
            componentRules, desktopTokens: tokenInventory,
        },
    };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    console.log(JSON.stringify(audit(process.argv[2] || ROOT), null, 2));
}
