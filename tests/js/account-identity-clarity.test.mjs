import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../public/geo-visibility.js', import.meta.url), 'utf8');

function extractFunction(name) {
  const start = source.indexOf(`function ${name}(`);
  assert.ok(start >= 0, `${name} exists`);
  const bodyStart = source.indexOf('{', source.indexOf(')', start));
  let depth = 0;
  for (let index = bodyStart; index < source.length; index++) {
    if (source[index] === '{') depth += 1;
    else if (source[index] === '}' && --depth === 0) return source.slice(start, index + 1);
  }
  throw new Error(`${name} body is incomplete`);
}

const getWho = new Function('sessionStorage', `${extractFunction('who')}; return who;`);
const first = getWho({ getItem: () => JSON.stringify({ name: '138****0001', tenant: { subdomain: 'geo-1111111111' } }) });
const second = getWho({ getItem: () => JSON.stringify({ name: '138****0001', tenant: { subdomain: 'geo-2222222222' } }) });
assert.notEqual(first(), second(), 'same masked phone must expose different workspace identities');
assert.equal(first(), '138****0001 · 工作区 geo-1111111111');

function projectLoader({ projects, requested, projectError }) {
  const message = { textContent: '', className: '' };
  const projectState = { list: [] };
  let selected;
  const apiGet = async path => {
    if (path === '/api/v1/projects?per_page=100') return { data: projects };
    if (projectError) throw Object.assign(new Error('lookup failed'), { status: projectError });
    throw new Error(`unexpected lookup: ${path}`);
  };
  const load = new Function('$', 'apiGet', 'projectState', 'requestedProjectId', 'rememberedProjectId', 'renderProjectChoices',
    `${extractFunction('loadProjectChoices')}; return loadProjectChoices;`)(
    selector => selector === '#formMsg' ? message : { disabled: false, innerHTML: '' },
    apiGet, projectState, () => requested, () => null, value => { selected = value; },
  );
  return load().then(() => ({ message, selected, projects: projectState.list }));
}

const empty = await projectLoader({ projects: [], requested: 17, projectError: 404 });
assert.match(empty.message.textContent, /项目 #17.*当前登录账号下无法访问/);
assert.match(empty.message.textContent, /核对页面右上角的工作区标识/);
assert.equal(empty.message.className, 'form-msg warn');
assert.equal(empty.selected, undefined);

const fallback = await projectLoader({ projects: [{ id: 5 }], requested: 17, projectError: 404 });
assert.equal(fallback.selected, 5);
assert.match(fallback.message.textContent, /已选择当前账号下的项目/);

const failure = await projectLoader({ projects: [{ id: 5 }], requested: 17, projectError: 500 });
assert.match(failure.message.textContent, /项目加载失败/);
assert.doesNotMatch(failure.message.textContent, /当前登录账号下无法访问/);

console.log('Cross-browser account identity and inaccessible project feedback passed.');
