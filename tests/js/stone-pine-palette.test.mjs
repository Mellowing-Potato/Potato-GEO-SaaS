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

// Refined desktop workbench type scale & sidebar hierarchy (1st-level > 2nd-level)
assert.equal(tokens['--md-fs-compact'], '13px', '--md-fs-compact must stay 13px for refined desktop density');
assert.match(tokens['--md-type-section'], /^600 16px\//, 'Section h3 headings must stay crisp 16px/600 (not oversized 20px)');
assert.match(tokens['--md-type-ui'], /^400 13px\//, 'Base UI copy must use refined 13px scale');
assert.match(tokens['--md-type-caption'], /^400 12px\//, 'Captions/hints must stay compact 12px');

const shellCss = fs.readFileSync('public/geo-shell.css', 'utf8');
assert.match(shellCss, /\.workspace-menu-section \+ \.workspace-menu-section\s*\{[^}]*border-top:/s, 'Sidebar categories must have clear visual separation');
assert.match(shellCss, /\.workspace-menu-group\s*\{[^}]*font-size:\s*var\(--md-fs-sm\)[^}]*font-weight:\s*var\(--md-weight-semibold\)/s, '1st-level sidebar group headers must be larger and bolder than 2nd-level links');
assert.match(shellCss, /\.workspace-menu-group \+ \.workspace-menu-items \.workspace-menu-link\s*\{[^}]*font-size:\s*var\(--md-fs-compact\)/s, '2nd-level sidebar items must use compact 13px type smaller than 1st-level');

const entryCss = fs.readFileSync('public/geo-entry.css', 'utf8');
assert.match(entryCss, /\.workspace-main > \*\s*\{[^}]*max-width:\s*1440px[^}]*margin-inline:\s*auto/s, 'Workspace main views must expand cleanly to 1440px and center on wide displays');
assert.match(entryCss, /#aikeysPanel\.panel\s*\{[^}]*max-width:\s*960px[^}]*margin-inline:\s*auto[^}]*background:\s*transparent/s, '#aikeysPanel must center cleanly and avoid outer card nesting');

const typographyCss = fs.readFileSync('public/geo-typography.css', 'utf8');
assert.match(typographyCss, /#resultPageBody :is\(\.report-metric-note,\s*\.report-section-note,\s*\.report-note\)\s*\{[^}]*font:\s*var\(--md-type-ui\)[^}]*color:\s*var\(--md-text-secondary\)/s, 'Report metric notes must use secondary compact UI scale instead of colliding with body prose');
assert.doesNotMatch(typographyCss, /#resultPageBody :is\([^)]*\.report-metric-note[^)]*\)[^{]*\{[^}]*--md-type-body/s, 'Report metric notes must not inherit full 15px body prose styling');

const readerCss = fs.readFileSync('public/geo-report-reader.css', 'utf8');
assert.match(readerCss, /\.report-reader\s*\{[^}]*max-width:\s*1160px[^}]*margin-inline:\s*auto[^}]*background:\s*transparent[^}]*border:\s*0/s, 'Report reader must render as a seamless centered reading surface without a redundant outer box border');
assert.match(readerCss, /\.workspace-shell:has\(#resultPanel\.is-active\) \.workspace-main\s*\{[^}]*background:\s*var\(--md-surface\)/s, 'Report reader main surface must be continuous white paper without a trapped gutter strip');
assert.doesNotMatch(readerCss, /grid-template-columns:\s*212px/, 'Report reader must keep the shared 248px sidebar width instead of shifting columns');
assert.match(readerCss, /\.report-reader > \.report-detail-options \.asset-next-card\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/s, 'Report detail options must stack .asset-next-card in a single column so text never crushes beside actions');

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
