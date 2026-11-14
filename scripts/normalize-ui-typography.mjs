// One-time migration of legacy literal declarations to the shared type scale.
// Uses the CSS AST so geometry, selectors, media rules and content remain untouched.
import fs from 'node:fs';
import postcss from 'postcss';
const sizes = [[12,'xs'],[14,'base'],[16,'md'],[18,'lg'],[20,'xl'],[24,'2xl'],[30,'3xl']];
const nearest = (number, values) => values.reduce((a,b) => Math.abs(b[0]-number)<Math.abs(a[0]-number)?b:a);
const sizeToken = n => `var(--md-fs-${nearest(Number(n),sizes)[1]})`;
const weightToken = n => `var(--md-weight-${Number(n)<450?'normal':Number(n)<550?'medium':Number(n)<650?'semibold':'bold'})`;
const leadToken = n => `var(--md-leading-${nearest(Number(n),[[1,'none'],[1.35,'tight'],[1.5,'snug'],[1.6,'ui'],[1.75,'body'],[1.85,'reading']])[1]})`;
let changes = 0;
for (const file of fs.readdirSync('public').filter(f=>/^geo-.*\.css$/.test(f)&&!['geo-tokens.css','geo-fonts.css'].includes(f))) {
  const path = `public/${file}`;
  const tree = postcss.parse(fs.readFileSync(path,'utf8'));
  tree.walkDecls(d => {
    let value = d.value;
    if(d.prop==='font-size') value = value.replace(/\b(\d+(?:\.\d+)?)px\b/g, (_,n)=>sizeToken(n));
    if(d.prop==='font-weight' && /^\d+$/.test(value)) value = weightToken(value);
    if(d.prop==='line-height' && /^\d+(?:\.\d+)?$/.test(value)) value = leadToken(value);
    if(d.prop==='line-height' && /^\d+(?:\.\d+)?px$/.test(value)) {
      const sibling = d.parent.nodes.find(n=>n.prop==='font-size');
      const match = sibling?.value.match(/--md-fs-(\w+)/);
      const size = match ? sizes.find(s=>s[1]===match[1])?.[0] : parseFloat(sibling?.value);
      value = leadToken(parseFloat(value)/(size||14));
    }
    if(d.prop==='font') {
      value = value.replace(/\b(\d+(?:\.\d+)?)px\b/g,(_,n)=>sizeToken(n));
      value = value.replace(/^(400|500|600|700|800)\s/,(_,n)=>weightToken(n)+' ');
      value = value.replace(/\/(\d+(?:\.\d+)?)(?=\s|$)/,(_,n)=>'/'+leadToken(n));
    }
    if(d.prop==='font-family' && !value.includes('var(') && value!=='inherit') value = /mono/i.test(value)?'var(--md-font-mono)':'var(--md-font-sans)';
    if(value!==d.value){d.value=value;changes++;}
  });
  let css = tree.toString();
  css = css.replace(/  --reader-body-font:.*\r?\n/g,'').replace(/  --reader-caption-font:.*\r?\n/g,'')
    .replaceAll('var(--reader-body-font)','var(--md-type-body)').replaceAll('var(--reader-caption-font)','var(--md-type-caption)');
  fs.writeFileSync(path,css);
}
console.log(`${changes} typography declarations now reference shared tokens.`);
// Runtime-generated UI styles must use the same scale as stylesheet declarations.
for(const file of fs.readdirSync('public').filter(f=>/^geo-.*\.js$/.test(f))){
  const path=`public/${file}`;
  let js=fs.readFileSync(path,'utf8');
  js=js.replace(/font-size:\s*(\d+(?:\.\d+)?)px/g,(_,n)=>'font-size:'+sizeToken(n))
    .replace(/font-weight:\s*(400|500|600|700|800)\b/g,(_,n)=>'font-weight:'+weightToken(n))
    .replace(/line-height:\s*(\d+(?:\.\d+)?)(?=[;"' ])/g,(_,n)=>'line-height:'+leadToken(n));
  fs.writeFileSync(path,js);
}
