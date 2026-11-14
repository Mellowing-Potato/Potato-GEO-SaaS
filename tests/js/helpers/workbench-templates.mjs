/**
 * 工作台模板读取器（计划 §7 第 2 步：`welcome.blade.php` 按面板拆 @include 之后）。
 *
 * 背景：面板拆进 `resources/views/workbench/panels/*.blade.php` 之后，任何"只读父模板"
 * 的断言都会找不到面板内容（实测：`team-menu-frozen` 当场红）。
 * 这些测试真正想断言的是"页面里有这个 id / 这个表单 / 这个顺序"，不是"它写在哪个文件里"，
 * 所以统一改成读**整组模板**。
 *
 * 顺序很关键：本模块按父模板里 `@include('workbench.panels.X')` 的**出现顺序**拼接分部，
 * 于是拼接结果与拆分前的单文件顺序一致 —— `indexOf` 之类的位置断言（如
 * report-history-layout）才能继续成立。
 */

import { readFileSync, readdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
export const ROOT = resolve(HERE, '../../..');
export const WORKBENCH_VIEW = 'resources/views/welcome.blade.php';
export const PANEL_DIR = 'resources/views/workbench/panels';

/** 父模板源码；按原位置只展开统一导航和顶栏，面板 include 保留。 */
export function parentTemplateSource() {
  return readFileSync(resolve(ROOT, WORKBENCH_VIEW), 'utf8').replace(
    /@include\('partials\.(workspace-nav|workspace-topbar)'[^)\n]*\)/g,
    (_include, name) => readFileSync(resolve(ROOT, `resources/views/partials/${name}.blade.php`), 'utf8'),
  );
}

/**
 * 面板分部源码，按父模板里 @include 的出现顺序（= 拆分前的文档顺序）。
 *
 * @returns {{name:string, path:string, source:string}[]}
 */
export function panelSources() {
  const parent = parentTemplateSource();
  const names = [...parent.matchAll(/@include\('workbench\.panels\.([A-Za-z0-9_-]+)'\)/g)]
    .map((match) => match[1]);

  // 兜底：万一有人在父模板里漏了 @include，也要把目录里剩下的分部带上，
  // 免得测试因为"文件没被引用"而看不到内容（那种情况本身该由别的测试抓）。
  const onDisk = readdirSync(resolve(ROOT, PANEL_DIR))
    .filter((name) => name.endsWith('.blade.php'))
    .map((name) => name.replace(/\.blade\.php$/, ''));
  const ordered = [...names, ...onDisk.filter((name) => !names.includes(name))];

  return ordered.map((name) => ({
    name,
    path: `${PANEL_DIR}/${name}.blade.php`,
    source: readFileSync(resolve(ROOT, PANEL_DIR, `${name}.blade.php`), 'utf8'),
  }));
}

/** 整组模板（父模板 + 全部分部），顺序与拆分前一致。 */
export function workbenchTemplateSource() {
  return [parentTemplateSource(), ...panelSources().map((panel) => panel.source)].join('\n');
}
