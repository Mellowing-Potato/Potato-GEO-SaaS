import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../../public/geo-login.js', import.meta.url), 'utf8');
const page = readFileSync(new URL('../../resources/views/login.blade.php', import.meta.url), 'utf8');
assert.match(page, /InstanceConfiguration::agreementsRequired\(\)/);
assert.match(page, /config\('instance\.terms_url'\)/);
assert.match(page, /config\('instance\.privacy_url'\)/);
assert.doesNotMatch(source, /sms-code|login-phone|register-phone/);
function element(dataset = {}) {
  const listeners = new Map(), attributes = new Map();
  return {dataset, value: '', checked: false, hidden: false, disabled: false, textContent: '',
    addEventListener: (type, fn) => listeners.set(type, fn),
    dispatch: (type, event = {}) => listeners.get(type)?.(event),
    setAttribute: (key, value) => attributes.set(key, value), removeAttribute: key => attributes.delete(key),
    getAttribute: key => attributes.get(key), focus() {this.focused = true;},
    reportValidity() {this.validityChecked = true; return true;}};
}
function setup({terms = true, redirect = '', reset = false} = {}) {
  const ids = Object.fromEntries(['email','name','password','confirmPassword','passwordFields','confirmFields','nameFields','backToLogin','forgotPassword','registerAccount','submitLogin','loginTitle','loginMessage','geoLoginForm',...(terms ? ['terms'] : [])].map(id => [id, element()]));
  const modes = ['login','register','forgot'].map(mode => element({mode}));
  const calls = [], stored = new Map(); let redirected = '', removedQuery = false;
  const query = reset ? '?mode=reset&token=test-reset-token&email=local%40example.com' : '?redirect_to='+encodeURIComponent(redirect);
  vm.runInNewContext(source, {document: {getElementById: id => ids[id], querySelectorAll: () => modes},
    location: {href: 'https://instance.example.test/login'+query, origin: 'https://instance.example.test', pathname: '/login', replace: target => {redirected=target;}},
    history: {replaceState() {removedQuery=true;}}, URL, AbortSignal,
    sessionStorage: {setItem: (key,value) => stored.set(key,value)},
    fetch: async (path, options) => {calls.push({path,body:JSON.parse(options.body)}); return {ok:true,json:async()=>path.endsWith('password') ? {message:'已处理'} : {token:'test-token'}};}});
  ids.email.value = 'local@example.com'; ids.password.value = ids.confirmPassword.value = 'test-password';
  return {ids,modes,calls,stored,redirected:()=>redirected,removedQuery:()=>removedQuery,submit:()=>ids.geoLoginForm.dispatch('submit',{preventDefault(){}})};
}
const gated=setup();
await gated.submit(); assert.equal(gated.calls.length,0); assert.equal(gated.ids.terms.focused,true);
assert.equal(gated.ids.terms.getAttribute('aria-invalid'),'true'); assert.match(gated.ids.loginMessage.textContent,/请先勾选/);
gated.ids.terms.checked=true; gated.ids.terms.dispatch('change'); await gated.submit();
assert.equal(gated.calls[0].path,'/api/v1/account/login'); assert.equal(gated.calls[0].body.email,'local@example.com');
assert.equal(gated.calls[0].body.terms_accepted,true); assert.equal(gated.stored.get('geo_token'),'test-token');
const open=setup({terms:false,redirect:'https://foreign.example.test/stolen'}); await open.submit();
assert.equal(open.calls.length,1); assert.equal(open.redirected(),'/');
const register=setup({terms:false}); register.modes[1].dispatch('click');
register.ids.password.value='test-password'; register.ids.confirmPassword.value='different';
await register.submit(); assert.equal(register.calls.length,0);
register.ids.confirmPassword.value='test-password'; register.ids.name.value='本地用户'; await register.submit();
assert.equal(register.calls[0].path,'/api/v1/account/register');
const reset=setup({terms:false,reset:true}); assert.equal(reset.removedQuery(),true); await reset.submit();
assert.equal(reset.calls[0].path,'/api/v1/account/reset-password'); assert.equal(reset.calls[0].body.token,'test-reset-token');
assert.equal(reset.redirected(),''); assert.equal(reset.ids.password.value,''); assert.equal(reset.stored.size,0);
console.log('Local login: optional agreements, credential submission, confirmation, safe redirect and reset-token handling passed.');
