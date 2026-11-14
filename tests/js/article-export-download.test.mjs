#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { readEntrySource } from './helpers/entry-source.mjs';

const source = readEntrySource();
const start = source.indexOf('  async function clDownload(article, kind) {');
const end = source.indexOf('\n  function clDownloadById(', start);
assert.ok(start >= 0 && end > start);
const downloads = [];
const requests = [];
const notices = [];
let response;
const clDownload = new Function('fetch', 'authHeaders', 'articleDisplayTitle', 'escapeHtml', 'downloadBlob', 'clToast',
  `${source.slice(start, end)}; return clDownload;`)(
  async (url, options) => { requests.push({ url, options }); return response; },
  (headers) => ({ ...headers, Authorization: 'Bearer test-token', 'X-Tenant': 'test-tenant' }),
  (article) => article.title,
  (text) => String(text).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'),
  (filename, blob) => downloads.push({ filename, blob }),
  (message, variant) => notices.push({ message, variant }),
);
const article = { id: 31, title: '中文 <标题>' };
const mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

response = new Response(new Uint8Array([80, 75, 3, 4, 5]), { headers: { 'Content-Type': mime } });
await clDownload(article, 'docx');
assert.equal(requests.at(-1).url, '/api/v1/articles/31/export/docx');
assert.equal(requests.at(-1).options.credentials, 'include');
assert.equal(requests.at(-1).options.headers.Authorization, 'Bearer test-token');
assert.equal(requests.at(-1).options.headers['X-Tenant'], 'test-tenant');
assert.equal(downloads.at(-1).filename, '中文 -标题-.docx');
assert.deepEqual(new Uint8Array(await downloads.at(-1).blob.arrayBuffer()), new Uint8Array([80, 75, 3, 4, 5]));

response = Response.json({ object: 'wechat_article_export', html: '<section><h1>正文标题</h1><p><strong>真实正文</strong></p></section>', plain_text: '正文标题\n真实正文' });
await clDownload(article, 'wechat');
assert.equal(requests.at(-1).url, '/api/v1/articles/31/export/wechat');
assert.equal(downloads.at(-1).filename, '中文 -标题-.html');
const html = await downloads.at(-1).blob.text();
assert.ok(html.startsWith('<!doctype html>'));
assert.ok(html.includes('<meta charset="utf-8">'));
assert.ok(html.includes('<title>中文 &lt;标题&gt;</title>'));
assert.ok(html.includes('<p><strong>真实正文</strong></p>'));
assert.ok(!html.includes('wechat_article_export'));

response = Response.json({ html: '', plain_text: '纯文本正文 & 备用内容' });
await clDownload(article, 'wechat');
assert.equal(downloads.at(-1).filename, '中文 -标题-.txt');
assert.equal(await downloads.at(-1).blob.text(), '纯文本正文 & 备用内容');

const count = downloads.length;
response = Response.json({ error: 'not a document' });
await clDownload(article, 'docx');
assert.equal(downloads.length, count, 'JSON must never be saved as DOCX');
assert.equal(notices.at(-1).variant, 'danger');
response = Response.json({ html: '', plain_text: '' });
await clDownload(article, 'wechat');
assert.equal(downloads.length, count, 'empty exports must not create files');
assert.equal(notices.at(-1).variant, 'danger');
response = new Response('Unauthorized', { status: 401 });
await clDownload(article, 'docx');
assert.equal(downloads.length, count, 'authentication failures must not create files');
assert.equal(notices.at(-1).variant, 'danger');

console.log('文章导出：Word 文件、微信 HTML/纯文本、鉴权头与错误响应检查通过。');
