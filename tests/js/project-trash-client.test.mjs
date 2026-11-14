import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { readEntrySource } from './helpers/entry-source.mjs';
import { ROOT, workbenchTemplateSource } from './helpers/workbench-templates.mjs';

/**
 * 项目回收站 · 前端契约（计划 §9.5；用户口径 2026-09-21「删项目保留 7 天，7 天之后无法恢复」）。
 *
 * 客户最容易误解的两件事必须写在页面上、也钉在测试里：
 *  ① 删除**不是**立刻永久消失（有回收站、几天内可恢复）；
 *  ② 但**会过期**（超过保留期自动永久删除、无法恢复）。
 * 服务端口径在 tests/Feature/ProjectTrashTest.php。
 *
 * 2026-10-01 起（1a82e9c）回收站是**全局回收站**：列表读 /api/v1/trash（项目与文章混排），
 * 恢复地址由服务端每条返回的 restore_url 给出，计数挂在侧栏底部的「回收站」入口上。
 */
const entry = readEntrySource();
const template = workbenchTemplateSource();
const controller = readFileSync(resolve(ROOT, 'app/Http/Controllers/Api/V1/ProjectController.php'), 'utf8');
const routes = readFileSync(resolve(ROOT, 'routes/api.php'), 'utf8');

// 1. 端点与动作
assert.match(entry, /api\(['"]\/api\/v1\/trash['"]\)/, '回收站列表端点丢了');
assert.doesNotMatch(entry, /api\(['"]\/api\/v1\/projects\/trash['"]\)/, '前端又读回了旧的项目回收站端点（应只读全局 /api/v1/trash）');
assert.match(routes, /Route::get\('trash', \[ProjectController::class, 'trash'\]\)/, '全局回收站路由丢了');
// 恢复：按服务端给的 restore_url 发 POST（项目 → projects/{id}/restore，文章 → articles/{id}/undelete）
assert.match(entry, /data-trash-restore-url="\$\{escapeHtml\(row\.restore_url \|\| ""\)\}"/, '恢复按钮没有带上服务端给的 restore_url');
assert.match(entry, /restoreTrashedItem\([^)]*button\.dataset\.trashRestoreUrl/, '点击恢复时没有把 restore_url 传下去');
assert.match(entry, /await api\(restoreUrl,\s*\{\s*method:\s*['"]POST['"]\s*\}\)/, '恢复没有 POST 到服务端给的 restore_url');
assert.match(controller, /'restore_url' => '\/api\/v1\/projects\/'\.\$project->id\.'\/restore'/, '服务端项目条目的 restore_url 不是项目恢复端点');
assert.match(controller, /'restore_url' => '\/api\/v1\/articles\/'\.\$article->id\.'\/undelete'/, '服务端文章条目的 restore_url 不是文章恢复端点');
assert.match(routes, /Route::post\('projects\/\{project\}\/restore'/, '项目恢复路由丢了');
assert.match(routes, /Route::post\('articles\/\{article\}\/undelete'/, '文章恢复路由丢了');

// 2. 保留天数只认服务端的 retention_days（前端写死就会与服务端 config 漂）
assert.match(entry, /setTrashRetention\(body\.retention_days\)/, '没有读服务端返回的 retention_days');

// 3. 页面上的回收站槽位
// 计数从回收站页头（旧 #projectTrashCount）搬到了侧栏底部的全局入口 #globalTrashCount。
for (const id of ['projectTrashSection', 'projectTrashList', 'globalTrashCount', 'globalTrashNav', 'projectTrashMessage', 'projectTrashRetention', 'openProjectTrash']) {
  assert.ok(template.includes(`id="${id}"`), `项目回收站缺少 #${id}`);
}

// 4. 删除确认文案必须同时说清"能恢复"和"会过期"（原来那句只说永久删除，已经不成立）
assert.match(entry, /它会进全局回收站保留 \$\{trashRetentionDays\(\)\} 天/, '删除确认文案没说回收站保留期');
assert.match(entry, /它会进全局回收站保留[^`]*超过保留期后无法恢复/, '删除确认文案没说过期不可恢复');
assert.match(template, /会进回收站保留 <b id="deleteProjectRetention">7<\/b> 天[^<]*超过保留期后无法恢复/, '删除说明没有同时写保留期与过期不可恢复');

// 5. 权限：看不到（403）就整块藏起来，不能把"读不到"显示成"空桶"
assert.match(entry, /error\?\.status === 403[\s\S]{0,120}section\.hidden = true/, '没有处理回收站的 403（无权账号会看到读不出来的列表）');
assert.match(entry, /回收站是空的/, '空桶没有明确文案');

// 6. 恢复要二次确认 + 刷新列表（只在弹窗里改状态、列表不刷会让人以为没恢复）
assert.match(entry, /恢复项目「\$\{name/, '恢复没有二次确认');
assert.match(entry, /state\.projects = await fetchProjectList\(\)/, '恢复后没有刷新项目列表');

// 7. 外站撤稿待办（计划 §9.5）：撤不下来的必须显示在回收站里，并给重试入口
// 项目与文章各走自己的撤稿端点，统一 POST。
assert.match(entry, /const url = type === "article" \? `\/api\/v1\/articles\/\$\{id\}\/takedown` : `\/api\/v1\/projects\/\$\{id\}\/takedown`;\s*const body = await api\(url,\s*\{\s*method:\s*['"]POST['"]\s*\}\)/, '撤稿重试端点丢了');
assert.match(entry, /外站还有 \$\{online\} 篇没撤下来/, '回收站没有把"外站没撤下来"亮出来');
assert.match(entry, /data-trash-takedown=/, '回收站没有撤稿重试按钮');
assert.match(template, /projectTrashSection/, '回收站区块不存在');

console.log('项目回收站前端契约成立：保留期/过期口径/权限/恢复刷新/撤稿待办与服务端一致。');
