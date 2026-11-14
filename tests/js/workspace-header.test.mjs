import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { readEntrySource } from './helpers/entry-source.mjs';
const source = readEntrySource();
function fn(name) {
  const start = source.search(new RegExp(`  function ${name}\\(`));
  assert.ok(start >= 0);
  return source.slice(start, source.indexOf('\n  }', start) + 4);
}
class Node {
  open = false; textContent = ''; attrs = {}; handlers = {};
  setAttribute(key, value) { this.attrs[key] = value; }
  addEventListener(event, handler) { this.handlers[event] = handler; }
  contains(target) { return target === this || target === elements.accountLink; }
  focus() { focused = this; }
}
let focused;
const elements = { userLabel: new Node(), accountLink: new Node() };
const nodes = Object.fromEntries(['accountMenu', 'accountMenuButton', 'accountMenuIdentity', 'accountMenuWorkspace', 'accountSecurityLink', 'workspaceIdentity'].map(id => [id, new Node()]));
const document = { handlers: {}, getElementById: id => nodes[id], addEventListener(event, handler) { this.handlers[event] = handler; } };
const window = { location: { href: 'https://app.example.test/#projects' }, handlers: {}, addEventListener(event, handler) { this.handlers[event] = handler; } };
const config = { accountUrl: 'https://accounts.example.test/account' };
const funcs = ['displayUser', 'syncAccountHeader', 'initAccountMenu'];
const { syncAccountHeader, initAccountMenu } = new Function('elements', 'document', 'window', 'config', `${funcs.map(fn).join('\n')}; return { syncAccountHeader, initAccountMenu };`)(elements, document, window, config);
const identity = '<img src=x> 超长显示名称';
syncAccountHeader({ account_user: { name: identity }, user: { name: 'internal' }, tenant: { subdomain: 'geo-abc123def0' } });
assert.equal(elements.userLabel.textContent, identity);
assert.equal(elements.userLabel.title, identity);
assert.equal(nodes.accountMenuIdentity.textContent, identity);
assert.equal(nodes.workspaceIdentity.textContent, '工作区 geo-abc123def0');
assert.equal(nodes.workspaceIdentity.hidden, false);
assert.equal(nodes.accountMenuWorkspace.textContent, '工作区 geo-abc123def0');
assert.ok(nodes.accountMenuButton.attrs['aria-label'].includes('工作区 geo-abc123def0'));
assert.equal(elements.accountLink.href, '#personal-settings');
assert.equal(nodes.accountSecurityLink.href, '#account-security');
assert.ok(nodes.accountMenuButton.attrs['aria-label'].includes(identity));
syncAccountHeader({ user: { email: 'fixture-user-22@example.com' } });
assert.equal(elements.userLabel.textContent, 'fixture-user-22@example.com');
assert.equal(nodes.workspaceIdentity.hidden, true);
assert.equal(nodes.accountMenuWorkspace.hidden, true);
initAccountMenu();
const menu = nodes.accountMenu;
menu.open = true;
document.handlers.pointerdown({ target: elements.accountLink });
assert.equal(menu.open, true);
document.handlers.pointerdown({ target: new Node() });
assert.equal(menu.open, false);
menu.open = true;
let prevented = false;
menu.handlers.keydown({ key: 'Escape', preventDefault() { prevented = true; } });
assert.ok(prevented && !menu.open);
assert.equal(focused, nodes.accountMenuButton);
menu.open = true;
document.handlers.focusin({ target: new Node() });
assert.equal(menu.open, false);
menu.open = true;
window.handlers.hashchange();
assert.equal(menu.open, false);
menu.open = true;
elements.accountLink.handlers.click();
assert.equal(menu.open, false);
assert.equal(elements.accountLink.href, '#personal-settings');
console.log('Account header: identity rendering, native account links and menu dismissal/focus passed.');
