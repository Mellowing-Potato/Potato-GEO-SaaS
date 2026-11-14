import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(dirname(dirname(fileURLToPath(import.meta.url)))));

/**
 * 工作台入口脚本的**全部源码**：`public/geo-entry.js` + 计划 §7 拆出去的所有块
 * （`public/geo-entry-*.js`，主文件永远排第一）。
 *
 * 契约测试关心的是"这条行为在不在"，不关心它住在哪个文件里。统一走这个 helper 之后，
 * geo-entry.js 每拆一块出去，测试都不必跟着改路径 —— 否则每次拆分都要动十几个测试文件，
 * 拆分本身也会被"改测试"这件事拖住。
 */
export function readEntrySource() {
    const dir = join(ROOT, 'public');
    const files = readdirSync(dir)
        .filter((name) => /^geo-entry.*\.js$/.test(name))
        .sort((a, b) => {
            if (a === 'geo-entry.js') return -1;
            if (b === 'geo-entry.js') return 1;

            return a.localeCompare(b);
        });

    if (!files.includes('geo-entry.js')) {
        throw new Error('找不到 public/geo-entry.js —— helper 的根目录推算错了');
    }

    return files.map((name) => readFileSync(join(dir, name), 'utf8')).join('\n');
}

/** 拆出去的文件清单（需要逐个点名时用）。 */
export function entryFiles() {
    return readdirSync(join(ROOT, 'public'))
        .filter((name) => /^geo-entry.*\.js$/.test(name))
        .sort();
}
