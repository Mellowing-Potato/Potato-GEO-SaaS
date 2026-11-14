#!/usr/bin/env node
/** 主题包结果页：字体名称、时区和耗时提示回归。 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(HERE, '../../public/geo-theme-package.js'), 'utf8');

function extractFunction(name) {
  const start = source.indexOf(`function ${name}(`);
  if (start < 0) throw new Error(`找不到 ${name}`);
  const bodyStart = source.indexOf('{', source.indexOf(')', start));
  let depth = 0;
  for (let i = bodyStart; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
  }
  throw new Error(`${name} 花括号未闭合`);
}

const displayFontFamilies = new Function(
  `${extractFunction('displayFontFamilies')}; return displayFontFamilies;`,
)();
const formatChinaDateTime = new Function(
  `${extractFunction('formatChinaDateTime')}; return formatChinaDateTime;`,
)();

const fonts = displayFontFamilies(["'inter'", "'jetbrains mono'", "'noto sans sc'", "'noto serif sc'"]);
if (JSON.stringify(fonts) !== JSON.stringify(['Inter', 'JetBrains Mono', 'Noto Sans SC', 'Noto Serif SC'])) {
  throw new Error(`字体显示仍含 CSS 原始噪声：${JSON.stringify(fonts)}`);
}

const localTime = formatChinaDateTime('2026-09-08T14:21:53+00:00', false);
if (!localTime.includes('22:21:53')) {
  throw new Error(`UTC 没有转换成北京时间：${localTime}`);
}

if (!source.includes('通常 1 分钟内完成')) {
  throw new Error('主题包仍显示不真实的 3~8 分钟耗时');
}

console.log('主题包字体、北京时间与耗时提示检查通过。');
