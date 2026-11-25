import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import postcss from 'postcss';

const tokens = {};
postcss.parse(fs.readFileSync('public/geo-tokens.css', 'utf8')).walkDecls((decl) => {
  tokens[decl.prop] = decl.value;
});

const expectedPalette = {
  '--md-bg-page': '#F8F8F7',
  '--md-bg-subtle': '#EFEFED',
  '--md-surface': '#FFFFFF',
  '--md-surface-soft': '#F8F8F7',
  '--md-text': '#171A18',
  '--md-text-strong': '#171A18',
  '--md-text-heading': '#171A18',
  '--md-text-secondary': '#484E4A',
  '--md-text-muted': '#5E6560',
  '--md-border': '#DFE1DF',
  '--md-primary': '#2D5F4C',
  '--md-primary-hover': '#214738',
  '--md-primary-soft': '#E7F0EC',
  '--md-success': '#2D5F4C',
  '--md-info': '#484E4A',
  '--md-info-soft': '#F8F8F7',
  '--md-warning': '#8C5811',
  '--md-danger': '#B42318',
};

for (const [name, expected] of Object.entries(expectedPalette)) {
  assert.equal(tokens[name], expected, `${name} must use the converged stone-white & deep-pine palette`);
}

const legacyHexPattern = /#(3F7A61|223E36|F7F4ED|1E6594|D4A438|6B5B8E)/i;
for (const file of [
  'public/geo-tokens.css',
  'public/geo-login.css',
  'public/geo-entry.css',
  'public/geo-analytics.js',
  'public/geo-workbench.js',
]) {
  const source = fs.readFileSync(file, 'utf8');
  assert(!legacyHexPattern.test(source), `${file} must not retain legacy multi-hue palette literals`);
}

const manifest = JSON.parse(fs.readFileSync('public/brand/yanji/v1/manifest.json', 'utf8'));
assert(!/[A-Za-z]:[\\/]/.test(String(manifest.source || '')), 'Brand manifest must not contain local workstation paths');
for (const asset of manifest.assets) {
  const assetPath = `public/brand/yanji/v1/${asset.file}`;
  const data = fs.readFileSync(assetPath);
  const digest = crypto.createHash('sha256').update(data).digest('hex');
  assert.equal(digest, asset.sha256, `${assetPath} sha256 must match manifest.json`);
  if (asset.file.endsWith('.svg')) {
    const svg = data.toString('utf8');
    assert(!/#(3F7A61|223E36)/i.test(svg), `${assetPath} must use updated deep-pine/charcoal fills`);
    assert(svg.includes('#2D5F4C'), `${assetPath} must include deep-pine primary fill #2D5F4C`);
  }
}

console.log(`Stone-pine palette & brand manifest: ${Object.keys(expectedPalette).length} core tokens and ${manifest.assets.length} brand assets verified.`);
