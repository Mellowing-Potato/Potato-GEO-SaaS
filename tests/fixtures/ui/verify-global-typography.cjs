// Isolated preview browser check. Set GEO_PLAYWRIGHT_MODULE to an available playwright-core
// installation, and GEO_CDP_URL to a dedicated local Chromium debug endpoint.
const {chromium}=require(process.env.GEO_PLAYWRIGHT_MODULE || 'playwright-core');
const fs=require('node:fs');
const assert=require('node:assert/strict');
(async()=>{
 const browser=process.env.GEO_CDP_URL ? await chromium.connectOverCDP(process.env.GEO_CDP_URL) : await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1050}});
 const page=await context.newPage();
 const errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 const fontRequests=[];
 page.on('request',r=>{if(r.resourceType()==='font')fontRequests.push(r.url());});
 const results=[];
 const routes=['/#result/101','/#projects','/#skill','/#asset','/#usage','/visibility/','/manual-publish/','/theme-package/','/analytics/','/platform-ops/','/workbench/','/lead-form/','/reading/'];
 const reviewHtml=fs.readFileSync(process.env.TEMP+'/geo-workspace-ui-preview/review/index.html','utf8');
 // Standalone preview routes are taken from the generated review index.
 const available=[...reviewHtml.matchAll(/href="([^"]+)"/g)].map(m=>m[1]);
 const paths=[...new Set(routes.slice(0,6).concat(available.filter(p=>p.startsWith('/')&&p.endsWith('/')&&!p.includes('#'))))];
 for(const path of paths){
   const response=await page.goto('http://127.0.0.1:8766'+path);
   if(response) assert.equal(response.status(),200,path);
   await page.evaluate(()=>document.fonts.ready);
   await page.waitForTimeout(60);
   const data=await page.evaluate(()=>({family:getComputedStyle(document.body).fontFamily,app:document.body.classList.contains('geo-app'),overflow:document.documentElement.scrollWidth>innerWidth+1,loaded:[...document.fonts].filter(f=>f.status==='loaded').length}));
   assert(data.app,path+' missing global typography');
   assert(data.family.includes('GEO Sans'),path+' wrong body font');
   assert(data.loaded>0,path+' has no downloaded font');
   assert(!data.overflow,path+' desktop horizontal overflow');
   results.push({path,...data});
   if(process.env.GEO_SCREENSHOT_DIR && ['/#skill','/#usage','/manual-publish/'].includes(path)) await page.screenshot({path:process.env.GEO_SCREENSHOT_DIR+'/global-'+path.replace(/[^a-z0-9]/gi,'')+'.png'});
 }
 await page.goto('http://127.0.0.1:8766/#result/101');
 await page.waitForSelector('#resultPageBody p');
 await page.evaluate(()=>document.fonts.ready);
 const cdp=await context.newCDPSession(page);
 await cdp.send('DOM.enable');await cdp.send('CSS.enable');
 const {root}=await cdp.send('DOM.getDocument');
 const fonts=[];
 await page.evaluate(()=>{const code=document.createElement('code');code.id='fontProbe';code.textContent='const GEO = 123; 中文';document.body.append(code);});
 await page.evaluate(()=>document.fonts.ready);
 for(const selector of ['.workspace-menu-link','#resultPageTitle','.reader-toc-link','#resultPageBody .report-lead','#resultPageBody td','#fontProbe']){
   const {nodeId}=await cdp.send('DOM.querySelector',{nodeId:root.nodeId,selector});
   const {fonts:used}=await cdp.send('CSS.getPlatformFontsForNode',{nodeId});
   assert(used.length&&used.every(f=>f.isCustomFont),selector+' fell back to an installed font');
   fonts.push({selector,fonts:used});
 }
 await page.evaluate(()=>document.getElementById('fontProbe').remove());
 const readerCheck=fs.readFileSync('tests/fixtures/ui/report-reader-check.js','utf8');
 const desktopReader=await page.evaluate(readerCheck);
 if(process.env.GEO_SCREENSHOT_DIR) await page.screenshot({path:process.env.GEO_SCREENSHOT_DIR+'/global-report.png'});
 await page.setViewportSize({width:390,height:844});
 await page.reload();await page.waitForSelector('#resultPageBody p');await page.evaluate(()=>document.fonts.ready);
 const mobileReader=await page.evaluate(readerCheck);
 if(process.env.GEO_SCREENSHOT_DIR) await page.screenshot({path:process.env.GEO_SCREENSHOT_DIR+'/global-report-mobile.png'});
 assert(fontRequests.length>0&&fontRequests.every(url=>url.startsWith('http://127.0.0.1:8766/fonts/')),'External font request');
 assert.equal(errors.length,0,errors.join('\n'));
 console.log(JSON.stringify({pages:results,fonts,desktopReader:desktopReader.passed,mobileReader:mobileReader.passed,allFontsSameOrigin:true,errors},null,2));
 await cdp.detach();await context.close();await browser.close();
})().catch(error=>{console.error(error);process.exit(1)});
