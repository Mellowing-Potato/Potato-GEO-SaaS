import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const ROOT = resolve(import.meta.dirname, '../../..');
const cache = new Map();
const render = String.raw`
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$options = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
foreach (['manual_publish_enabled', 'visibility_panel_enabled', 'analytics_panel_enabled'] as $flag) {
    config(['geo.'.$flag => $options['flags'][$flag] ?? true]);
}
$html = view('partials.workspace-nav', ['mode' => $options['mode']])->render();
$dom = new DOMDocument;
$previous = libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
libxml_clear_errors();
libxml_use_internal_errors($previous);
$xpath = new DOMXPath($dom);
$groups = [];
foreach ($xpath->query('//*[@data-nav-group]') as $group) {
    $links = [];
    foreach ($xpath->query('.//a[@href]', $group) as $link) {
        $attributes = [];
        foreach ($link->attributes as $attribute) $attributes[$attribute->name] = $attribute->value;
        $links[] = ['label' => trim($link->textContent), 'attributes' => $attributes];
    }
    $label = $xpath->query('./button/text()[1]', $group)->item(0);
    $groups[] = ['key' => $group->getAttribute('data-nav-group'), 'label' => trim($label?->textContent ?? ''), 'links' => $links];
}
echo json_encode(['definition' => \App\Support\WorkspaceNavigation::menus(), 'html' => $html, 'groups' => $groups], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
`;

/** Read the production static definition and its real Blade-rendered navigation.
 * No HTTP request, database mutation, copied menu, or synthesized HTML is used.
 * Gates default to enabled so callers can inspect the entire published contract.
 */
export function workspaceNavigationSource({ mode = 'spa', flags = {} } = {}) {
  assert(['spa', 'page'].includes(mode), 'Supported workspace navigation mode');
  const key = JSON.stringify({ mode, flags });
  if (!cache.has(key)) {
    const result = spawnSync(process.env.PHP_BINARY || 'php', ['-r', render, ROOT, key], {
      cwd: ROOT, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024,
    });
    assert.equal(result.status, 0, `Real workspace navigation rendering failed: ${result.error?.message || result.stderr || result.stdout}`);
    const output = JSON.parse(result.stdout);
    output.topbarSource = readFileSync(resolve(ROOT, 'resources/views/partials/workspace-topbar.blade.php'), 'utf8');
    cache.set(key, output);
  }
  return cache.get(key);
}
