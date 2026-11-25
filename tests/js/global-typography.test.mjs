import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import postcss from 'postcss';

const manifest = JSON.parse(fs.readFileSync('public/fonts/manifest.json','utf8'));
const faces = fs.readFileSync('public/geo-fonts.css','utf8');
assert(!/https?:\/\/|local\(/.test(faces), 'Runtime font loading must use same-origin files, never installed fonts or external CDNs');
for(const family of manifest){
  const license=fs.readFileSync(`public/fonts/${family.family==='Noto Sans SC'?'notosanssc':'robotomono'}/OFL.txt`,'utf8');
  assert(license.includes('SIL OPEN FONT LICENSE'));
  for(const file of family.files){
    const data=fs.readFileSync('public/'+file.path);
    assert.equal(data.subarray(0,4).toString(),'wOF2');
    assert.equal(crypto.createHash('sha256').update(data).digest('hex'),file.sha256);
    assert(faces.includes('/'+file.path));
  }
}
const tokens={};
postcss.parse(fs.readFileSync('public/geo-tokens.css','utf8')).walkDecls(d=>tokens[d.prop]=d.value);
assert.equal(tokens['--md-font-sans'],'"GEO Sans"');
assert.equal(tokens['--md-font-mono'],'"GEO Mono", "GEO Sans"');
const value=k=>tokens[k].startsWith('var(')?value(tokens[k].slice(4,-1)):tokens[k];
const luminance=h=>h.slice(1).match(/../g).map(n=>parseInt(n,16)/255).map(n=>n<=.04045?n/12.92:((n+.055)/1.055)**2.4).reduce((sum,n,i)=>sum+n*[.2126,.7152,.0722][i],0);
for(const ink of ['--md-text','--md-text-strong','--md-text-heading','--md-text-secondary','--md-text-muted','--md-text-group']){
  for(const surface of ['--md-bg-page','--md-surface','--md-surface-soft','--md-primary-soft']){
    const a=luminance(value(ink)),b=luminance(value(surface));
    assert((Math.max(a,b)+.05)/(Math.min(a,b)+.05)>=4.5, `${ink} on ${surface} must remain readable`);
  }
}
// Collect every violation before failing, so one run lists all offending declarations (same rule, same files).
const scaleViolations=[];
for(const file of fs.readdirSync('public').filter(f=>/^geo-.*\.css$/.test(f)&&!['geo-fonts.css','geo-tokens.css'].includes(f))){
  postcss.parse(fs.readFileSync('public/'+file,'utf8')).walkDecls(d=>{
    if(['font-size','font-weight','line-height','font'].includes(d.prop)){
      if(/\b\d+(?:\.\d+)?px\b/.test(d.value))scaleViolations.push(`${file}:${d.source.start.line}: ${d.prop} must use the global scale (${d.value})`);
      if(/^\d+(?:\.\d+)?$/.test(d.value)&&d.value!=='0')scaleViolations.push(`${file}:${d.source.start.line}: ${d.prop} must use a global token (${d.value})`);
    }
  });
}
assert.deepEqual(scaleViolations,[],`Typography literals outside the global scale:\n${scaleViolations.join('\n')}`);
function walk(dir){return fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?walk(dir+'/'+e.name):[dir+'/'+e.name]);}
const pages=walk('resources/views').filter(f=>f.endsWith('.blade.php')&&fs.readFileSync(f,'utf8').includes('geo-tokens.css'));
assert.equal(pages.length,11); // The desktop launch page joins the ten existing pages.
assert(pages.includes('resources/views/local-start.blade.php'), 'The desktop launch page must use the shared typography');
for(const file of pages){
  const html=fs.readFileSync(file,'utf8').replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/<!--[\s\S]*?-->/g,'');
  assert(html.includes('/geo-fonts.css'),file);
  assert(/<body[^>]*class="[^"]*\bgeo-app\b/.test(html),file);
  const styles=[...html.matchAll(/<link[^>]*href="\/([^"?]+\.css)/g)].map(m=>m[1]);
  // geo-typography.css must win the cascade: nothing loaded after it may declare anything.
  // A stylesheet after it is allowed only if it is comment-only (e.g. the geo-page-navigation.css URL placeholder).
  const at=styles.lastIndexOf('geo-typography.css');
  assert(at>=0,`${file}: geo-typography.css is not loaded`);
  for(const later of styles.slice(at+1)){
    const nodes=postcss.parse(fs.readFileSync('public/'+later,'utf8')).nodes.filter(n=>n.type!=='comment');
    assert.equal(nodes.length,0,`${file}: ${later} loads after geo-typography.css and declares rules, so it can override the global scale`);
  }
}
console.log(`Global typography: ${pages.length} pages, ${manifest.flatMap(f=>f.files).length} local font files, licenses, hashes, shared scale and color contrast passed.`);
