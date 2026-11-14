import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import { readEntrySource } from './helpers/entry-source.mjs';
const src=readEntrySource();
const extract=name=>{const start=src.indexOf(`  ${name==='bootstrap'?'async ':''}function ${name}(`);return src.slice(start,src.indexOf('\n  }',start)+4)};
let redirected='',visible='',message={textContent:''},status=401;const store=new Map();
const deps={window:{location:{href:'https://app.example.test/#result/47',replace:url=>redirected=url}},config:{accountLoginUrl:'https://account.example.test/login'},sessionStorage:{getItem:k=>store.get(k),setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)},fetch:async()=>({status,ok:status===200,json:async()=>({})}),showState:s=>visible=s,elements:{errorMessage:message},persistSession:()=>{},state:{},syncAccountHeader:()=>{},applyManualPublishVisibility:()=>{},restoreWorkspaceProjects:async()=>{},setActiveWorkspaceView:()=>{},currentWorkspaceRoute:()=>'projects',loadSkills:async()=>{}};
const bootstrap=new Function(...Object.keys(deps),'let bootstrapPending=false, projectsReady=false;'+extract('accountLoginUrl')+extract('bootstrap')+';return bootstrap;')(...Object.values(deps));
await bootstrap();assert.equal(new URL(redirected).searchParams.get('redirect_to'),deps.window.location.href);assert.notEqual(visible,'signedOutState');
// Return to the homepage and click again within a minute: still sign in normally.
redirected='';await bootstrap();assert(redirected);assert.notEqual(visible,'errorState');
// Browsers can retain the old release's guard value. It must not block entry.
store.set('geo_auth_redirect_at',String(Date.now()));redirected='';await bootstrap();assert(redirected);assert(!store.has('geo_auth_redirect_at'));
status=200;await bootstrap();assert.equal(visible,'workspaceState');assert(!store.has('geo_auth_redirect_at'));
status=503;redirected='';await bootstrap();assert.equal(visible,'errorState');assert.equal(redirected,'');
store.clear();status=419;await bootstrap();assert(redirected);
console.log('Anonymous redirect, deep-link return, repeat entry and stale guard recovery, connected session and service error checks passed.');
