import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.resolve(here, '../../public/geo-manual-publish.js'), 'utf8');
const styles = fs.readFileSync(path.resolve(here, '../../public/geo-manual-publish.css'), 'utf8');

test('详情页从接口顶层读取允许的下一状态', () => {
  assert.match(source, /Array\.isArray\(j\.allowed_next\)\s*\?\s*j\.allowed_next\s*:\s*\[\]/);
  assert.doesNotMatch(source, /p\.allowed_next/);
});

test('同一发布单加载中会忽略重复详情请求并保持详情区高度', () => {
  assert.match(source, /if \(detailLoadingId === id\) return;/);
  assert.match(source, /host\.style\.minHeight = retainedHeight \+ 'px'/);
  assert.match(source, /\.finally\(function \(\) \{[\s\S]*detailLoadingId = null;/);
});

test('流转记录的起止状态都使用中文状态标签', () => {
  assert.match(source, /t\.from_status \? statusPill\(t\.from_status\) : '—'/);
  assert.match(source, /statusPill\(t\.to_status\)/);
});

test('完成表单的 hidden 属性不会被 grid 样式覆盖', () => {
  assert.match(styles, /\.mp \[hidden\]\s*\{\s*display:\s*none\s*!important;/);
});

test('异常结束与重新打开会先收集原因并写入流转记录', () => {
  assert.match(source, /var terminalStatuses = \['failed', 'skipped', 'cancelled'\]/);
  assert.match(source, /st === 'ready' && isReopen/);
  assert.match(source, /id="transitionReasonForm" hidden/);
  assert.match(source, /请填写原因，作为流转记录。/);
  assert.match(source, /if \(resultNote\) payload\.result_note = resultNote/);
});
