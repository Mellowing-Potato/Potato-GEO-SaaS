#!/usr/bin/env node
/**
 * 后端端点 ↔ 前端入口 的对照台账（GEO 侧）
 *
 * 背景：2026-09-22 复核「后端有、前端零调用」时，全量 244 条路由交叉比对后
 * 剩下的其实分两种，必须分开对待：
 *
 *   A. 用户可见但界面到不了 —— 要补入口（本次补了工作区改名、URL 导入详情、
 *      我的权限三条）。
 *   B. 机器/服务端/冻结接口 —— 本来就不该有页面，但要**登记**，
 *      否则下次复核又会把它们当成"漏接的入口"，甚至被顺手删掉。
 *
 * 本用例把 B 类钉成台账：
 *   1) 台账里的端点必须仍然存在于 routes/api.php（登记不会过期）；
 *   2) 它们必须**不出现**在前端语料里（否则说明有人接了却没更新台账）；
 *   3) 本次补的三条必须真在前端代码里（防止"只写台账不接线"）。
 *
 * 运行：node tests/js/backend-endpoint-frontend-parity.test.mjs
 */

import { readFileSync, readdirSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join, resolve } from "node:path";

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, "../..");

let failures = 0;
let checks = 0;

function assert(label, ok, detail = "") {
  checks++;
  if (ok) {
    console.log(`  ✓ ${label}`);
    return;
  }
  failures++;
  console.log(`  ✗ ${label}${detail ? `\n      ${detail}` : ""}`);
}

const routesSource = readFileSync(join(ROOT, "routes/api.php"), "utf8");

/** 前端语料 = 工作台 JS + Blade 模板（平台运营台与公开页都在 public/ 里）。 */
function frontendSources() {
  const files = [];
  for (const name of readdirSync(join(ROOT, "public"))) {
    if (name.endsWith(".js")) files.push(join(ROOT, "public", name));
  }
  const viewsRoot = join(ROOT, "resources/views");
  const walk = (dir) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      const full = join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (entry.name.endsWith(".blade.php")) files.push(full);
    }
  };
  walk(viewsRoot);
  return files.map((file) => ({ file, text: readFileSync(file, "utf8") }));
}

const frontend = frontendSources();
const frontendText = frontend.map((item) => item.text).join("\n");

/**
 * B 类台账：机器/服务端/冻结接口 —— 没有页面是设计如此。
 *
 * routeFragment：routes/api.php 里的原文片段（保证"登记不会过期"这条能真的查到，
 *   materials 那两条写在 `prefix('materials')` 组里，所以登记的是组内相对路径）。
 * pathFragment：前端如果接了它，会出现的 URL 片段（用于反向断言"前端真的没接"）。
 */
const MACHINE_ONLY = [
  {
    routeFragment: "executions/{execution}/progress",
    pathFragment: "executions/${executionId}/progress",
    reason:
      "技能执行的粗档位进度（pending 10 / running 60 / 终态 100）。工作台用 "
      + "GET /status 里的 relay（在写第 N 段 / 共 M 段，比档位细得多）显示进度，"
      + "这条留给轮询型/实时通道客户端。",
  },
  {
    routeFragment: "Route::post('pick', [MaterialController::class, 'pick'])",
    pathFragment: "materials/pick",
    reason:
      "素材自动取用：用户没显式选素材时，服务端在技能执行链路里直接调 "
      + "MaterialLibraryService::pick（SkillExecutionController），前端不需要再调一次。",
  },
  {
    routeFragment: "Route::post('record-usage', [MaterialController::class, 'recordUsage'])",
    pathFragment: "materials/record-usage",
    reason:
      "素材使用计数：同步执行在 SkillExecutionController、异步执行在 ExecuteSkillJob "
      + "里于成功时调用；HTTP 端点面向 PAT 集成方，前端重复上报会重复计数。",
  },
  {
    routeFragment: "geoflow-beacon/v1/visits",
    pathFragment: "geoflow-beacon",
    reason: "外站信标上报入口，调用方是客户站点上的 beacon 插件，不是本工作台。",
  },
];

/** A 类：本次补上的三条，必须真在代码里（不是只写在文档里）。 */
const WIRED = [
  { fragment: "PATCH /api/v1/workspace", needle: 'api("/api/v1/workspace"', file: "public/geo-entry-workspace-settings.js" },
  { fragment: "GET /api/v1/me/permissions", needle: 'api("/api/v1/me/permissions")', file: "public/geo-entry-workspace-settings.js" },
  { fragment: "GET /api/v1/url-imports/{urlImport}", needle: "/api/v1/url-imports/${encodeURIComponent(id)}", file: "public/geo-entry-url-import-detail.js" },
];

console.log("\n[1] 机器/冻结接口台账：登记有效且前端确实没有引用");
for (const entry of MACHINE_ONLY) {
  assert(
    `routes/api.php 里仍有 ${entry.routeFragment}`,
    routesSource.includes(entry.routeFragment),
    "登记过期了：路由已改名/删除，请同步更新台账。",
  );
  assert(
    `前端语料里没有 ${entry.pathFragment}（理由：${entry.reason.slice(0, 18)}…）`,
    !frontendText.includes(entry.pathFragment),
    "前端引用了这条端点：要么把台账里这条删掉，要么说明为什么还留着页面。",
  );
}

console.log("\n[2] 本次补上的三条：前端代码里真有调用");
for (const entry of WIRED) {
  const source = readFileSync(join(ROOT, entry.file), "utf8");
  assert(
    `${entry.file} 调用了 ${entry.fragment}`,
    source.includes(entry.needle),
    `没找到调用点：${entry.needle}`,
  );
}

console.log("\n[3] 补上入口的页面真的被加载（脚本标签在模板里）");
const welcome = readFileSync(join(ROOT, "resources/views/welcome.blade.php"), "utf8");
assert("welcome.blade.php 加载 geo-entry-workspace-settings.js",
  welcome.includes("/geo-entry-workspace-settings.js"));
assert("welcome.blade.php 加载 geo-entry-url-import-detail.js",
  welcome.includes("/geo-entry-url-import-detail.js"));

console.log(`\n${failures === 0 ? "✓ 全部通过" : "✗ 有失败"}：${checks} 项断言，${failures} 项失败\n`);
process.exit(failures === 0 ? 0 : 1);
