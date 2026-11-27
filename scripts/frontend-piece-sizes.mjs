// 大文件拆分基线：量出"哪一块有多大"，顺便当水位棘轮挡住继续膨胀。
//
// 计划 §7（P2：前端大文件拆分）把四个文件点名为维护热点：
//   public/geo-entry.js                          单文件承载全部工作台视图
//   resources/views/welcome.blade.php            全部视图的 HTML 壳
//   app/Services/SkillAgent/SkillAgentRunnerService.php
//   routes/console.php                           调度声明 + 命令实现混在一起
//
// 拆分不能靠感觉切：先要知道**每一块**占多少行、边界在哪，拆完再量一次对比。
// 这个脚本按文件类型各自的分段标记切块并输出行数；`--check` 模式拿记录的
// CEILINGS 当棘轮 —— 超了就让 CI 红，逼"边拆边加"变成"要么拆、要么显式抬水位"。
//
// 用法：
//   node scripts/frontend-piece-sizes.mjs            # 打印盘点
//   node scripts/frontend-piece-sizes.mjs --check     # 超水位即退出码 1
//   node scripts/frontend-piece-sizes.mjs --json      # 机读

import { readFileSync } from 'node:fs';
import { dirname, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
export const ROOT = resolve(HERE, '..');

/**
 * 水位棘轮（2026-09-21 基线，只许降）。
 * 拆掉一块就把对应数字调下来；**加功能导致上涨必须在同一提交里显式抬高**，
 * 并在提交信息里说明为什么这次不拆 —— 否则这个棘轮就变成摆设。
 */
export const CEILINGS = Object.freeze({
  // 2026-09-21：第一块拆出去 —— 管理面板五视图（URL 导入 / 文章分类 / API 令牌 /
  // 账户中心概览 / 订阅运营）→ public/geo-entry-management.js。
  // 14528 → 14045 行（少 483 行）。拆分方式照仓库先例：主文件暴露 window.GEO
  // （共享 state/elements/辅助函数 + 视图加载器与附加加载器两个注册表），
  // 拆出的文件消费它。行为等价性用真 Chrome 的逐视图 DOM/API 指纹证明
  // （tests/fixtures/ui/dom-fingerprint.json + scripts/snapshot-ui-dom.mjs）。
  // 2026-09-21：第二块同样搬出去 —— 财务面五视图（账户与用量 / 订阅套餐 / 充值与账单 /
  // 发票 / 用量明细）→ public/geo-entry-billing.js。14045 → 13340 行。
  // 2026-09-21：第三块搬出去 —— 企业知识库（EK）草稿工作流
  // （建项目 → 草稿 → 校验 → 生成/发布轮询 → 修订与回滚 + 来源增删）
  // → public/geo-entry-enterprise-knowledge.js。13342 → 12840 行。
  // 2026-09-21：第四块搬出去 —— 知识库文档工作流
  // （详情开关 → 文档列表与 2500ms 轮询 → 上传/URL 摄入 → 切片与分屏对照 → 检索测试
  // + 删除文档/知识库）→ public/geo-entry-knowledge-base.js。12840 → 11837 行。
  // 2026-09-21：第五块搬出去 —— 内容库 + 素材库（两块在源文件里本就相邻）
  // → public/geo-entry-content-library.js。11837 → 10485 行。
  // 2026-09-21：第六块搬出去 —— 发布与渠道（发布面 + 渠道密钥/Agent 包 + 站点文件）
  // → public/geo-entry-publishing.js。10485 → 10073 行。
  // 2026-09-21：第七块搬出去 —— 线索表单管理台（三个页签 + 两条前端可见性）
  // → public/geo-entry-leads.js。10073 → 9243 行。
  // 2026-09-21：第八块搬出去 —— 平台运营台（概览/租户/商业/健康/运营四页签）
  // → public/geo-entry-platform.js。9243 → 9069 行。
  // 2026-09-21：第九块搬出去 —— 技能工作区（技能目录 / AI 建议 / 执行表单 / 轮询 / 结果卡）
  // → public/geo-entry-skills.js。9069 → 7636 行。
  // 这一刀起主文件对搬走的块改走「薄转发」（同名箭头函数转发到 window.GEO），
  // 所以主文件不再随每刀线性变小，但每刀都在减少「同一份实现有两个位置」的风险。
  // 2026-09-21：第十块搬出去 —— 结果页三页签 + 资产视图（含结构化报告渲染器）
  // → public/geo-entry-reports.js。7636 → 6220 行。
  // 报告分区常量（SECTION_LABELS / RESULT_ZONES / REPORT_*）留主文件：它们是对象、
  // 没法用函数薄转发替，而且结果页自己也要用。
  // 2026-09-21：第十一块搬出去 —— 项目簇（列表/选择器/详情/阶段轨/新建删除/设置）
  // → public/geo-entry-projects.js。6220 → 5343 行。
  // 计划 §7 点名的六个域到这一刀全部拆完，主文件只剩脚手架与通用辅助。
  // 2026-09-21：GEO 导出表去重（十一刀累积出 15 行重复键）→ 5343 → 5302 行。
  // 2026-09-21：5302 → 5305 —— 登录成功时把"本次登录撤销过注销申请"这一事实写进会话
  // （persistSession 里 2 行 + 1 行注释），落地提示在 public/geo-entry-account.js。
  // 这是功能必需的"收口一处"：两条登录路径都经过 persistSession，写在这里才不用改两处。
  // 2026-09-22：5305 → 5312 —— CSP 强制模式的可观测判据：<html data-entry-config="ready">。
  // 为什么值得占这几行：CSP 转强制后，漏挂 nonce 的内联配置脚本会被**静默拦下**，
  // 而当前配置取值与代码兜底一致 —— 页面照样能跑，人眼与"有没有报错"都看不出来（实测过）。
  // 有了这个标记，真浏览器判据逐视图断言即可，生产上 DevTools 也能一眼看到。
  // 2026-09-22：5318 → 5296 —— 凭据红线下调水位：API 令牌的元素登记/视图 meta/别名与
  // 渠道密钥·Agent 包·信标插件的元素登记全部撤出（租户侧不得有 key 与令牌的创建/删除）。
  // 2026-09-23：GEO 20:1 对客显示恢复，主文件只加共享格式器接线与少量旧展示替换。
  // 2026-09-25：+2 行用于自动任务扣点待核验状态，不扩散到已拆出的视图。
  // 2026-09-25：新增一行“模型费用待核验”状态文案，避免未知账单显示成暂无用量。
  // 2026-09-27：5304 → 5326。模型连接测试增加原请求状态读取、待核验时保留请求键、
  // 确认未发送后才释放请求键。这是现有单按钮处理器的故障分支，保留在原位置；
  // 为一个小处理器另建跨文件桥接会增加页面加载与作用域风险。
  // 2026-09-27：5326 → 5329。付费请求键保存失败时拒绝发送，连接测试错误出口恢复按钮。
  // 2026-09-27：5329 → 5350。连接测试处理器增加服务端原键发现、送达确认及失败恢复；
  // 逻辑仍使用本文件的会话状态、API 与按钮上下文，避免另建跨文件可变状态。
  // 2026-09-27：5350 → 5347。连接测试改用服务端原子认领，主文件净减少 3 行。
  // 2026-09-27：5347 → 5352。首次打开只回看上次已确认结果，页内再次点击才申请新任务。
  // 2026-09-27：5352 → 5362。共享付费请求键分账号存放、响应绑定原账号，旧版无归属键拒绝自动换键。
  // 2026-10-01：报告详情复用原路由/分区和 chunk 解析，编辑界面拆到 geo-report-editor.js；
  // 主文件仅留报告状态接线、只读分区与无损分块解析。基线原本已有 7 行偏差。
  // 2026-10-02：合并报告/知识接线与现有看板状态口径；沿用同一共享视图上下文。
  // 2026-10-15：账号掩码碰撞时展示稳定工作区标识；同步账号菜单与页头，增加 12 行。
  // 2026-10-15：独立功能页回工作台时恢复项目与新建入口，增加 URL 项目同步和启动接线。
  // 这两处依赖主壳的会话/项目状态；独立页的菜单与样式已经拆到 geo-page-navigation.*。
  // 2026-10-23：5541 → 5542。会话就绪后通知外壳读取顶栏积分；读取逻辑在 geo-page-navigation.js，
  // 主文件只加这一行触发（线上首屏顶栏积分在打开「账户与用量」前一直是「—」）。
  // 2026-10-24：EXEC-10A 项目设置子视图，5542 → 5546；拆开压在同一行的语句与声明。
  // 这是现有视图切换函数中的子视图路由接线，不另拆模块以免引入跨文件视图状态。
  // EXEC-10B B5：5546 → 5541，删除旧阶段容器、整体进度和内联时间线的元素映射。
  // EXEC-11 B / §6 裁定2、3：删除重复档案/下一步卡与状态播报，5541 → 5504。
  // EXEC-11 评审：删除已无按钮可触发的「重新分析」分支，5504 → 5502。
  // EXEC-14 A：运营统计合并、删除重复AI采样、摘要失败提示收口，5502 → 5494。
  // EXEC-14 B：页签选中态同时写 aria-selected 与 is-on，绑定作用域清晰分行，5494 → 5496（较基线仍净减6行）。
  // EXEC-14 C：动态表格与两个既有去处按需绑定，5496 → 5499；全批相对5502净减3行。
  // EXEC-14 评审：两个去处改在初始化时绑一次（摘要失败时也能用），「待审核文章」同步文章页页签高亮，5499 → 5501。
  // EXEC-15 A：删除订阅运营前端入口及附加加载，5501 → 5496。
  // EXEC-15 B：模型状态与重试失败仅新增原始错误warn，冻结连通测试流程，5496 → 5498。
  // EXEC-15 C：额度常量/口径函数/页签/usage请求从项目总览搬出，5498 → 5444。
  'public/geo-entry.js': 5444,
  // 拆出来的第一块：自成一个文件，之后继续拆的块各自登记水位，主文件只许降。
  // ⚠️ 这个 571 是交付物清单，不是"可以继续加"的额度 —— 往里加代码要说明为什么不另开文件。
  // 2026-09-22：586 → 437 —— API 令牌那一块（列表/签发/吊销/明文回显）整段撤出租户工作台，
  // 面板模板一并删除；后端 /api/v1/api-tokens 与其权限判据原样保留。
  // EXEC-15 A：删除订阅运营前端入口及附加加载，437 → 350。
  // EXEC-15 评审：账户中心概览读取异常时同时清空旧流水，不让上一次的数据留在“暂不可用”旁边，350 → 351。
  'public/geo-entry-management.js': 351,
  // 拆出来的第二块：财务面（含支付等待/轮询、套餐结算跳转、用量图表与明细表）。
  // 2026-09-21：772 → 779 —— 收编归档分支 cecf370 的"部分退款"前端那一半（7 行）：
  // 状态列显示"部分原路退款 ¥X"、已部分退款的订单不再按原额开票。
  // 按棘轮规则显式抬水位（该提交当年改的是 1.4 万行的 geo-entry.js，这里落在拆分后的财务面文件里）。
  // EXEC-15 B：客户错误文案与原始对象warn，不改余额计算和其他四视图，779 → 782。
  // EXEC-15 C：从geo-entry.js原样搬入额度常量/口径函数，新增额度状态渲染与并行请求，782 → 850。
  // EXEC-15 补修：「购买补充包」等 data-open-billing 改为 document 委托（异步渲染的按钮也能点），拆成具名函数，850 → 855。
  // 拆出来的第三块：企业知识库（含两个 2500ms 轮询与它们的停止条件）。
  // 2026-10-02：+38 行，事实卡目标库选择、来源链接和发布竞争保护属于本模块。
  'public/geo-entry-enterprise-knowledge.js': 606,
  // 拆出来的第四块：知识库文档（含文档轮询与 15 分钟上限、10MB 上传闸、切片/分屏/检索）。
  // 1076 里含 24 行区块横幅（详情开关 / 文档轮询 / 上传摄入 / 切片分屏 / 检索测试 / 删除）——
  // 这个脚本靠横幅切块，没有横幅就只能把 1000 行报成一块，水位表也就失去"哪块多大"的信息。
  // 2026-10-02：检索按钮补全与 API 共用的幂等键及未知结果重试保护（+8 行）。
  // 逻辑留在检索弹窗事件里，避免为一次请求再拆一块共享状态。
  // 2026-10-02：+32 行，文档质量提示、历史预览及重抓/归档入口属于知识库详情。
  // 分页与预览竞态修复：将正文/PDF 预览独立成模块，列表入口只保留转发。
  'public/geo-entry-knowledge-base.js': 777,
  'public/geo-entry-knowledge-preview.js': 537,
  // 拆出来的第五块：内容库（CL0-CL5 列表/审核/抽屉/下载）+ 素材库（四类素材 + 技能选料 chips）。
  // 1459 里含 18 行区块横幅（内容库 / 数据拉取 / 列表与行内动作 / 抽屉 / 素材库 / 注册绑定）——
  // 同第四块：这个脚本靠横幅切块，没有横幅就只剩一个 900 行的"未命名区块"。
  // 2026-09-22：1459 → 1486 —— 分发去向的单渠道路由下拉框换成多选勾选列表，
  // 改走此前从无调用方的 POST /articles/{id}/publish-batch（含 207 部分失败的如实反馈）。
  // Batch title generation now keeps one retry identity until its paid result is confirmed.
  // 2026-09-27：1490 → 1491。标题生成在请求键保存失败时停在发送前。
  // 2026-09-27：1491 → 1503。付费标题仅在返回行和数量一致时释放原请求键。
  // 2026-09-27：1503 → 1562。标题认领、原键恢复及送达确认。
  // 2026-10-02：+108 行，文章审核即时状态、服务端预览和渠道状态留在内容库模块。
  // 2026-10-02：集成渠道就绪字段、目标接受状态与所有审核后文章的发布记录。
  // 2026-10-14：+3 行，为已通过文章提供修订入口和重新审核提示；沿用现有编辑器。
  // 2026-10-17：新增平台账号目标、版本化发布单交接和人工发布记录，仍归文章发布域。
  // EXEC-10B B1/B3: expose existing unfiltered readers and article detail navigation.
  // EXEC-13 A / §6 裁定1：分类加载、双路筛选、清空和项目复位；其他改动保持原行为。
  // 1732 → 1787，新增逻辑属于现有文章模块，不引入跨模块状态。
  // EXEC-13 评审：清除筛选在类型来自地址时改走路由（否则下次进页类型被地址带回），拆成具名函数，1787 → 1791。
  'public/geo-entry-content-library.js': 1791,
  // 拆出来的第六块：发布与渠道（渠道列表/详情/健康检查/日志/重写规则/密钥/Agent 包 +
  // 站点文件 robots.txt·sitemap + 项目↔渠道绑定）。
  // 2026-09-22：585 → 443 —— 凭据红线收敛：密钥管理 / Agent 包 / WP 信标插件三处入口
  // 连同生成逻辑一起撤出租户侧（都依赖渠道密钥，属运营动作，端点保留）；只留
  // 不需要凭据的 WP 分类/标签预同步。
  // 2026-10-02：站点文件实际生效检查及渠道状态留在现有发布模块。
  // 2026-10-17：项目绑定加入第三方账号，并防止切项目后的迟到响应覆盖绑定。
  'public/geo-entry-publishing.js': 487,
  // 拆出来的第七块：线索表单管理台（线索列表/详情抽屉/导出、表单管理、转化统计）。
  'public/geo-entry-leads.js': 872,
  // 拆出来的第八块：平台运营台（仅 platform_admin；五个端点 Promise.allSettled）。
  'public/geo-entry-platform.js': 209,
  // 拆出来的第九块：技能工作区（含 brief_fields 动态表单、执行轮询、结果卡与「下一步」）。
  // Paid suggestion forms now retain one attempt key across transport retry and
  // suppress concurrent submits; the extra 14 lines are the explicit safety path.
  // 2026-09-25：技能执行汇总区分已确认扣点与原网关请求的未知费用。
  // 2026-09-25：恢复检查点分开呈现用量、扣点与交付状态，不在页面透出正文。
  // 2026-09-27：1625 → 1628。技能与辅助建议在请求键保存失败后恢复按钮与错误提示。
  // 2026-09-27：1628 → 1642。技能入队响应归属核对，缺失任务 ID 时保留原付费键。
  // 2026-09-27：1642 → 1649。错误回执不再落入入队成功分支，账号切换保留旧任务键。
  // 2026-09-27：1649 → 1703。技能付费任务认领、原键恢复和送达确认。
  // 2026-10-02：报告选题、结构化参考材料、草稿保留及已知冲突进度都依附技能执行表单。
  // 校准已提交版本现有的 1809 行；本批未更改技能界面业务代码。
  // EXEC-11 C / §6 裁定4：删除复述状态与重复阶段药丸，1809 → 1803。
  // EXEC-12 A：合并三类结果提示、添加状态徽标与产物行，展示分流显式逐句书写，1803 → 1815。
  // EXEC-12 B：去除重复下一步/文章按钮，按护栏实际行数登记，1815 → 1807（较基线净增4行展示分类）。
  'public/geo-entry-skills.js': 1807,
  // 拆出来的第十块：结果页三页签 + 资产视图（历史报告/文章列表与详情）。
  // 2026-09-23：标题优化报告新增紧凑候选、推荐与使用建议的专用渲染，1606 → 1632 行。
  // 1632 里含 16 行区块横幅（渲染器 / 滑轨 / 资产列表 / 资产详情 / 回挂）——水位表靠横幅逐块报数。
  // 报告页阅读模式增加按字段宽度的表格与可展开问题/采样行；其他调用方保留原渲染。
  // 2026-10-02：旧报告展示校正、问题写作入口及跨项目报告列表加载属于结果页。
  'public/geo-entry-reports.js': 1763,
  // 拆出来的第十一块：项目簇（含阶段轨/档案/右键菜单/时间线/分析入口）。
  // 1033 里含 16 行区块横幅（选项目 / 项目详情 / 右键菜单 / 新建删除 / 回挂）。
  // 2026-09-21：1033 → 1179 → 1220 —— 计划 §9.5 项目回收站（桶内列表 / 恢复 / 保留期天数回读，
  // 用户口径「删项目保留 7 天，7 天之后无法恢复」）。新增功能导致的显式抬水位；
  // 回收站与项目域同一个生命周期，内聚在这里，不另开文件。
  // 1220 那 41 行是外站撤稿待办（回收站里显示"还有 N 篇没撤下来" + 重试按钮），
  // 同一批拍板的口径：撤不下来的必须亮在客户看得到的地方。
  // 原基线比工作树少 40 行；本轮只移除两个未定义导出以恢复工作台启动。
  // 2026-10-02：路线百分比口径与单技能完成提示在项目阶段卡展示。
  // 2026-10-15：校验独立页带回的 project_id 后再恢复所选项目。
  // 2026-10-24：EXEC-10A 项目设置子视图，1270 → 1273；拆开压在同一行的语句与声明。
  // 项目切换后聚焦和标题同步沿用现有项目生命周期，属于子视图路由接线，不另拆模块。
  // EXEC-10B B1: one runtime hook; B5 removes the legacy stage renderers.
  // EXEC-10B B2/B5：1274 → 1066，删除旧阶段卡、阶段轨和内联时间线渲染；原推进/回退与项目生命周期留在此域。
  // EXEC-11 B：移除诊断重复项目信息卡的写入与死调用，1066 → 1051。
  'public/geo-entry-projects.js': 1051,
  // EXEC-10B B6: dedicated project home, including explicit parser section boundaries and navigation guards.
  'public/geo-entry-project-home.js': 592,
  // 2026-09-21：新文件，随账户注销功能一起出生 —— 从第一天就进棘轮，防止它长成下一个 1.4 万行。
  // 203 里含 9 行区块横幅（状态 / 提交 / 恢复提示 / 注册与绑定）—— 水位表靠横幅逐块报数，按 §7 的既有约定。
  'public/geo-entry-account.js': 203,
  // 2026-09-21：全部 25 个面板（共约 1700 行）迁到
  // resources/views/workbench/panels/*.blade.php，2176 → 385 行（约 −82%）——
  // 父模板现在只剩外壳（head / 顶栏 / 侧栏 / 三个启动态）与 @include 清单。
  // 迁移判据不是行数而是"渲染后 DOM 完全不变"（scripts/blade-dom-fingerprint.php）。
  // 2026-09-21：385 → 391 —— 多了三行 <script src="/geo-entry-*.js"> 与三行顺序说明
  // （拆出去的块必须在 geo-entry.js 之后加载，否则读不到 window.GEO）。
  // 这是拆分带来的显式抬水位，不是新增 UI 面板。
  // 2026-09-21：391 → … → 408 —— 第四到第十一块各加一行 <script>，第九/十/十一刀还补了
  // 12 行加载顺序说明（projects → reports → skills → 其余：加载期解构的依赖必须排前面）。
  // 这是拆分带来的显式抬水位，不是新增 UI 面板。
  // 2026-09-21：408 → 410 —— 计划 §9.5 账户注销脚本（geo-entry-account.js）+ 一行说明。
  // 这次是**新增功能**（不是拆分搬运），按棘轮规则显式抬水位并在提交信息里说明。
  // 2026-09-23：正式工作台须在入口脚本前加载 GEO 积分显示格式器。
  // 原基线已有 5 行偏差，本轮再登记独立报告编辑脚本。
  // 2026-10-15：为工作区标识补页头和账号菜单两个可访问节点，增加 2 行。
  // 2026-10-15：工作台新增两个受路由开关控制的独立监测页入口。
  'resources/views/welcome.blade.php': 425,
  // 2026-09-21：六刀 —— 提示词构造 → SkillPromptBuilder；质量报告 → SkillQualityReporter；
  // 分节续写摘要 → SkillContinuityDigest；分节谱与初始状态 → SkillChunkPlan；
  // 分节数据装配 → SkillChunkAssembly；交付内容组装 → SkillDeliveryAssembler。
  // 3483 → 2080 行（少 1403 行，约 −40%）。
  // 六刀都用特征化测试冻结输出 sha256（拆分前后逐字一致）。
  // 2026-09-27：658 → 660。异步执行行与原请求键同次插入，队列简报保留该键供崩溃恢复。
  'app/Services/SkillAgent/SkillAgentRunnerService.php': 660,
  // 2026-09-21：五批共 32 条命令实现迁入 app/Console/Commands，5066 → 176 行（约 −97%）。
  // 这个文件现在只剩调度声明 + inspire（Laravel 自带示例，不是本仓的业务命令）。
  // 棘轮按承诺逐批下调。
  // 2026-09-21：176 → 188 → 193 —— 计划 §9.5 三条隐私生命周期调度（geo:projects-purge 03:40 /
  // geo:account-purge 03:45 / geo:privacy-purge 03:50）与它们的口径说明。
  // **调度声明本来就该住在这个文件**（§7 的分工：
  // 路由文件只留声明、命令实现进命令目录），所以这 12 行不是该拆的东西，是显式抬水位。
  // The already committed GEO debit reconciliation command added four lines;
  // correct the stale ratchet without changing that command in this return.
  // 2026-09-25：+3 行为原扣点键的五分钟续办调度；命令实现留在独立类。
  'routes/console.php': 200,
});

function posix(value) {
  return String(value).split(sep).join('/');
}

function lines(text) {
  return text.split(/\r?\n/);
}

/** JS：以 `// ═══…` / `// ──…` 横幅为界切块（geo-entry.js 的既有约定）。 */
function jsPieces(text) {
  const all = lines(text);
  const marks = [];
  all.forEach((line, index) => {
    if (/^\s*\/\/\s*[═─]{3,}/.test(line)) marks.push(index);
  });
  return sliceByMarks(all, marks, (index) => {
    // 横幅下一行通常是人读的标题。
    const next = (all[index + 1] || '').replace(/^\s*\/\/\s?/, '').trim();
    return next.length > 1 && !/^[═─]{3,}/.test(next) ? next : '(未命名区块)';
  });
}

/** Blade：按 `data-workspace-view="x"` 的面板切块。 */
function bladePieces(text) {
  const all = lines(text);
  const marks = [];
  const names = new Map();
  all.forEach((line, index) => {
    const match = line.match(/data-workspace-view="([a-z0-9-]+)"/);
    if (match) {
      marks.push(index);
      names.set(index, `面板 ${match[1]}`);
    }
  });
  return sliceByMarks(all, marks, (index) => names.get(index) || '面板');
}

/** PHP：按 `function name(` 与 `Artisan::command('name'` 切块。 */
function phpPieces(text) {
  const all = lines(text);
  const marks = [];
  const names = new Map();
  all.forEach((line, index) => {
    // 命令签名常常跨行书写（`Artisan::command('geo:health-check` 换行接参数声明），
    // 所以只取名字的第一段，不要求同一行出现收尾引号。
    const command = line.match(/Artisan::command\(\s*'([^']+)/);
    if (command) {
      marks.push(index);
      names.set(index, `命令 ${command[1]}`);
      return;
    }
    const method = line.match(/^\s{4}(?:public|private|protected)\s+(?:static\s+)?function\s+([a-zA-Z0-9_]+)\s*\(/);
    if (method) {
      marks.push(index);
      names.set(index, `方法 ${method[1]}()`);
    }
  });
  return sliceByMarks(all, marks, (index) => names.get(index) || '区块');
}

function sliceByMarks(all, marks, label) {
  if (marks.length === 0) return [];
  // 文件末尾通常有一个空元素（结尾换行），它不是一行内容。
  const lastIndex = all[all.length - 1] === '' ? all.length - 2 : all.length - 1;
  const pieces = [];
  for (let i = 0; i < marks.length; i++) {
    const start = marks[i];
    const endIndex = i + 1 < marks.length ? marks[i + 1] - 1 : lastIndex;
    pieces.push({
      label: label(start),
      start: start + 1,
      end: endIndex + 1,
      size: endIndex - start + 1,
    });
  }
  return pieces;
}

export function inventory() {
  const plan = [
    ['public/geo-entry.js', jsPieces],
    ['public/geo-entry-management.js', jsPieces],
    ['public/geo-entry-enterprise-knowledge.js', jsPieces],
    ['public/geo-entry-knowledge-base.js', jsPieces],
    ['public/geo-entry-knowledge-preview.js', jsPieces],
    ['public/geo-entry-content-library.js', jsPieces],
    ['public/geo-entry-publishing.js', jsPieces],
    ['public/geo-entry-leads.js', jsPieces],
    ['public/geo-entry-platform.js', jsPieces],
    ['public/geo-entry-skills.js', jsPieces],
    ['public/geo-entry-reports.js', jsPieces],
    ['public/geo-entry-projects.js', jsPieces],
    ['public/geo-entry-project-home.js', jsPieces],
    ['public/geo-entry-account.js', jsPieces],
    ['resources/views/welcome.blade.php', bladePieces],
    ['app/Services/SkillAgent/SkillAgentRunnerService.php', phpPieces],
    ['routes/console.php', phpPieces],
  ];
  return plan.map(([file, parser]) => {
    const text = readFileSync(resolve(ROOT, file), 'utf8');
    const all = lines(text);
    const total = all[all.length - 1] === '' ? all.length - 1 : all.length;
    const pieces = parser(text).sort((a, b) => b.size - a.size);
    return { file, total, ceiling: CEILINGS[file], pieces };
  });
}

function main() {
  const args = process.argv.slice(2);
  const report = inventory();

  if (args.includes('--json')) {
    console.log(JSON.stringify(report, null, 2));
    return report.every((item) => !args.includes('--check') || item.total <= item.ceiling) ? 0 : 1;
  }

  let over = 0;
  for (const item of report) {
    const delta = item.total - item.ceiling;
    const flag = delta > 0 ? `  ⚠ 超水位 ${delta} 行` : '';
    console.log(`\n${posix(item.file)}  ${item.total} 行（水位 ${item.ceiling}）${flag}`);
    if (delta > 0) over++;
    for (const piece of item.pieces.slice(0, 8)) {
      console.log(`   ${String(piece.size).padStart(6)} 行  L${piece.start}–${piece.end}  ${piece.label}`);
    }
    if (item.pieces.length > 8) {
      console.log(`   … 其余 ${item.pieces.length - 8} 块见 --json`);
    }
  }

  if (args.includes('--check') && over > 0) {
    console.error(
      `\n✗ 有 ${over} 个文件超过拆分水位：要么真的拆掉一块，要么在`
      + 'scripts/frontend-piece-sizes.mjs 的 CEILINGS 里显式抬高并说明原因。',
    );
    return 1;
  }
  return 0;
}

if (process.argv[1] && posix(fileURLToPath(import.meta.url)) === posix(process.argv[1])) {
  process.exitCode = main();
}
