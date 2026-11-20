<?php

// Legacy thresholds must be explicitly migrated with the database cutover.
foreach (['GEO_VISIBILITY_MIN_BALANCE_FEN','GEO_AI_BALANCE_GATE_MIN_FEN','GEO_AI_BALANCE_GATE_MIN_FEN_TEXT'] as $legacyPointSetting) {
    if (env($legacyPointSetting) !== null) throw new RuntimeException('积分配置需升级：'.$legacyPointSetting.' 请改用 POINTS 名称并按旧值乘 5。');
}
unset($legacyPointSetting);

$skillRegistryPath = env('GEO_SKILL_REGISTRY_PATH');

// 分段完整性闸的逃生阀取值（说明见下方 chunk_strict）。
//
// ⚠️ 这段绕了个弯，两个坑都踩过，别改回一行：
//   1. env('X') 拿到的不是原始字符串——Laravel 会把 "false" 先转成布尔 false。
//      于是 (string) env(...) 得到的是空串 ''，任何「比对 false/0/off/no 字面量」
//      的写法都永远匹配不上，开关是死的（2026-09-05 发版前进镜像实测抓到）。
//   2. 光用 filter_var 也不行：它把空串判成 false，那样 geo.env 里写一行没填值的
//      GEO_CHUNK_STRICT= 就会把闸门悄悄关掉。默认为真的安全开关不能这么脆。
// 现在的口径：空值 / 空白 / 认不出的乱码一律保持严格，只有显式的
// false/0/off/no（含大小写混写）才松闸。
$geoChunkStrictRaw = env('GEO_CHUNK_STRICT', true);
$geoChunkStrict = is_string($geoChunkStrictRaw) && trim($geoChunkStrictRaw) === ''
    ? true
    : (filter_var($geoChunkStrictRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);

return [
    // Test-only opt-in. A missing Key never enables simulated model output.
    'test_mock_ai' => false,
    'model_http_timeout' => (int) env('GEO_MODEL_HTTP_TIMEOUT', 280),
    /*
     * 工作台安全响应头（计划 §9.1，2026-09-21）。
     *
     * 此前应用层一个安全响应头都没有：只有 theme-preview 单条路由和几个下载接口自己打，
     * 工作台、公开阅读页、线索表单这些 HTML 页面裸奔。生产拓扑是
     * 公网 → 边缘 Caddy（终止 TLS）→ 明文 HTTP → 本容器，所以 HSTS 只能由本层在
     * 请求确实是 https 时发出（HTTP 下的 HSTS 会被浏览器忽略，发了也没意义）。
     *
     * CSP 默认走 **report-only**：工作台有内联脚本（window.GEO_* 配置）与内联样式，
     * 在没做过浏览器逐页复核之前直接 enforce 会把页面打断。report-only 不上报就只是
     * 一个可见性信号，配上 GEO_CSP_REPORT_URI 才会真的收集违规。
     * 逐页复核通过后再把 GEO_CSP_MODE 改成 enforce。
     */
    'security_headers' => [
        'enabled' => filter_var(env('GEO_SECURITY_HEADERS', true), FILTER_VALIDATE_BOOL),
        // SAMEORIGIN：theme-package 预览是同源 iframe，跨站嵌入一律拒绝。
        'frame_options' => env('GEO_FRAME_OPTIONS', 'SAMEORIGIN'),
        'hsts_max_age' => (int) env('GEO_HSTS_MAX_AGE', 15552000), // 180 天
        'hsts_include_subdomains' => filter_var(env('GEO_HSTS_INCLUDE_SUBDOMAINS', true), FILTER_VALIDATE_BOOL),
        'csp_mode' => env('GEO_CSP_MODE', 'report-only'), // off | report-only | enforce
        'csp_report_uri' => env('GEO_CSP_REPORT_URI'),
        // 留空用中间件里的默认策略；要收紧/放宽都在这里写整条策略，便于按环境回滚。
        'csp_policy' => env('GEO_CSP_POLICY'),
    ],
    /*
     * 高成本接口限流口径（计划 §9.4）。
     *
     * 「高成本」= 一次调用会花钱或占用外部资源：模型直连（技能执行 / 分析 / 关键词与竞品建议）、
     * 文档摄入（解析 + 向量化）、URL 抓取导入。
     *
     * ⚠️ **默认全空 = 不限流**（现状行为，一个字节都不变）。这里的每个键都是"填上才生效"：
     *      GEO_RL_SKILL_EXECUTE=30,60   → 每个租户+用户 60 分钟内最多 30 次技能执行
     * 口径要产品定（见 docs/待决事项-决策清单-2026-09-21.md §9.4），
     * 定了之后只改 .env，不动路由与中间件（中间件已挂好，见 routes/api.php）。
     */
    /*
     * 数据库备份与恢复演练（计划 §9.2）。
     *
     * 2026-09-21 用户口径：**备份存本机，本机足够大** —— 所以 dir 默认落在
     * storage/app/backups（容器里就是挂出来的那块盘）。将来要接异机/对象存储，
     * 只需要在备份命令之后加一步上传（校验和文件就是给那一步准备的），不用改这里的逻辑。
     *
     * *_binary 三个键是给"不在 PATH 里 / 要指定版本"的部署用的；测试里也用它注入假执行器。
     */
    'backup' => [
        'dir' => env('GEO_BACKUP_DIR'),
        'pg_dump_binary' => env('GEO_BACKUP_PG_DUMP', 'pg_dump'),
        'pg_restore_binary' => env('GEO_BACKUP_PG_RESTORE', 'pg_restore'),
        'psql_binary' => env('GEO_BACKUP_PSQL', 'psql'),
        'createdb_binary' => env('GEO_BACKUP_CREATEDB', 'createdb'),
        'dropdb_binary' => env('GEO_BACKUP_DROPDB', 'dropdb'),
    ],
    'rate_limits' => [
        'skill_execute' => env('GEO_RL_SKILL_EXECUTE'),           // POST /skills/execute、/skills/execute-async
        'skill_suggest' => env('GEO_RL_SKILL_SUGGEST'),           // POST /skills/suggest-keywords、/suggest-competitors
        'analysis_create' => env('GEO_RL_ANALYSIS_CREATE'),       // POST /projects/{project}/analyses
        'knowledge_ingest' => env('GEO_RL_KNOWLEDGE_INGEST'),     // POST /knowledge-bases/{kb}/documents、/url
        'url_import' => env('GEO_RL_URL_IMPORT'),                 // POST /url-imports
    ],
    /*
     * 隐私生命周期（计划 §9.5；口径由用户 2026-09-21 拍板）。
     *
     * 项目删除 —— 原话：「客户删项目之后可以自动保留 7 天，7 天之后无法恢复，这里是不是应该设计垃圾桶」。
     *   所以删除 = **进垃圾桶**（项目软删、子内容原地保留、可恢复），期满由
     *   `geo:projects-purge` 执行原来的连坐硬删。调用流水（与钱有关的）照旧不删。
     *
     * 账户注销 —— 原话：「账户可以注销，但是要提交申请注销，15 天以内再次登陆就等于恢复账户」。
     *   所以申请即退出登录并进入冷静期；期内任一次登录自动撤销申请（恢复账户）；
     *   期满由 `geo:account-purge` 清除身份信息（手机号等）并清空其名下内容。
     *
     * 两个数字都可按环境改（env），所以"改口径"是一行配置，不是一次发版。
     */
    'lifecycle' => [
        'project_trash_retention_days' => (int) env('GEO_PROJECT_TRASH_RETENTION_DAYS', 7),
        'account_deletion_grace_days' => (int) env('GEO_ACCOUNT_DELETION_GRACE_DAYS', 15),
        /*
         * 其余表与磁盘产物的保留期限（用户 2026-09-21 拍板：「可以，做吧」）。
         *
         * 口径（推荐值即默认值）：
         *   - 内容类：项目被真删（垃圾桶期满）之后，剩下的内容行与磁盘产物按 90 天清 ——
         *     3 个月足够覆盖"客户删完又想起来要找回"的现实窗口，也不至于把磁盘长期占着。
         *   - 钱相关：model_call_logs / point_debit_reconciliations **至少留 24 个月**；
         *     即使超过，默认也只统计不动手，要清必须显式 `--include-money`（对账与审计要用）。
         */
        'content_purge_days' => (int) env('GEO_CONTENT_PURGE_DAYS', 90),
        'money_retention_months' => (int) env('GEO_MONEY_RETENTION_MONTHS', 24),
    ],
    // Release-owned catalog. An upstream registry may supply metadata only for these approved IDs.
    'skill_registry_path' => $skillRegistryPath ?: base_path('resources/skills/registry.json'),
    'skill_allowed_ids' => require __DIR__.'/skill-allowlist.php',
    'skill_asset_lock_path' => base_path('resources/skills/upstream-lock.json'),
    // 技能资产的本仓覆盖层。SkillAssetLoader 先查这里，查不到再回落 SKILLS_REPO_PATH。
    //
    // 为什么需要它：yao-geo-skills 是姚的仓库，以只读方式挂进容器
    // （docker-compose.yml: ./yao-geo-skills:/app/yao-geo-skills:ro）。我们对渲染脚本
    // 的修复推不进上游、也写不进挂载点，此前就只能以「未跟踪文件」的形态散落在开发机和
    // 生产机上 —— 没有版本控制、改坏了无法回滚，而且镜像 tag 完全不能说明脚本是哪一版。
    //
    // 放在本仓则随 `COPY . .` 进镜像，路径 /app/skill-overlays 不被那个只读挂载遮蔽，
    // 于是「镜像 git-xxx 跑的就是 xxx 那版脚本」重新成立。
    //
    // 目前只有 yao-geo-panorama-audit 一个技能在这里：它的 scripts/ 在上游历史中从未
    // 存在过（另外 18 个技能的渲染脚本都在上游、正常跟踪）。上游哪天补齐了，删掉这里
    // 对应目录即可自动回落，不需要改代码。
    'skill_overlay_path' => env('GEO_SKILL_OVERLAY_PATH') ?: base_path('skill-overlays'),
    'analysis_pipeline_mode' => env('GEO_ANALYSIS_PIPELINE_MODE', 'legacy'),
    'analysis_lock_seconds' => (int) env('GEO_ANALYSIS_LOCK_SECONDS', 600),
    // 异步分析：开启后 POST /analyses 立即入队返回 202(status=pending)，前端轮询 show 端点直到终态。
    // 默认关（保持同步 201 契约，测试与旧行为不变）；生产由 geo.env 显式置 true。correctness 已由网关超时修复兜底，
    // 本开关只影响 UX(免长时间挂浏览器)。见 2026-07-28 GEO 分析 502 复盘。
    'analysis_async' => filter_var(env('GEO_ANALYSIS_ASYNC', false), FILTER_VALIDATE_BOOL),
    // 已登记的长报告技能分段生成（全景、路线图、解释型文章、意图挖掘）。
    // 默认关；生产由 geo.env 显式置 true。
    'analysis_chunked' => filter_var(env('GEO_ANALYSIS_CHUNKED', false), FILTER_VALIDATE_BOOL),

    // 页面审计执行前真的去抓客户填的那个页面（SkillAgentRunnerService::PAGE_FETCH_SKILLS）。
    //
    // ⚠️ 默认 true，而且这个默认值是有意的：关掉它不是「少一个功能」，而是**退回到
    //    2026-09-06 之前那个会编报告的行为** —— 那时页面审计从不发请求，模型凭对域名的
    //    印象写「观察到的」结论。生产 execution 52 因此告诉客户他那个能正常打开的官网
    //    不存在。只有在抓取本身出系统性故障、且宁可要一份不可信报告也不能全停的时候才关。
    'page_fetch_enabled' => filter_var(env('GEO_PAGE_FETCH_ENABLED', true), FILTER_VALIDATE_BOOL),
    // 这是请求预算，真实模型/时间上限仍由网关钳制，不在 runner 写死旧 8000 上限。
    'chunk_max_tokens' => max(1, (int) env('GEO_CHUNK_MAX_TOKENS', 16384)),
    // 意图挖掘只补生成被截断或漏交的字段；每个字段最多补一次，0 可单独关闭补跑。
    // 2026-09-06 老板拍板 3 → 6：生产 execution 48 只有一段出问题（证据与路线图，3 个字段
    // 全没交），就把 3 次预算正好用满。再多坏一段就补不动，整单判失败、客户为我们的缺陷
    // 付第二次钱。6 次能扛住两段同时坏，且只有真出问题时才产生这笔调用。
    'chunk_recovery_max_calls' => max(0, (int) env('GEO_CHUNK_RECOVERY_MAX_CALLS', 6)),
    // 补跑必须给单次 HTTP 最坏耗时及收尾留余量，不延长队列任务的 480 秒寿命。
    'chunk_recovery_deadline_seconds' => max(1, (int) env('GEO_CHUNK_RECOVERY_DEADLINE_SECONDS', 400)),
    // 分段完整性闸：任一段没成（调用失败／解析失败／解析出来一个自有字段都没有）就整单
    // 判失败，不出缺内容的报告。默认严格。
    // 置 false 是逃生阀，退回旧行为——只要还有一段有内容就照常出报告。它存在的唯一理由：
    // 上线后失败率万一爆掉，改一行 env 就能止血，不用重建镜像重推一遍。
    // ⚠️ 两种模式都会记 'Chunked execution incomplete' 日志（带 failed_chunks），
    // 关掉它不会让频率数据断，事后照样能统计到底有多少报告是缺段的。
    // ⚠️ 取值口径见文件顶部 $geoChunkStrict 那段注释（踩过两个坑，别改回一行 env()）。
    'chunk_strict' => $geoChunkStrict,
    // JSON Schema output is opt-in and only sent to the OpenAI preset.
    'native_json_schema' => filter_var(env('GEO_NATIVE_JSON_SCHEMA', false), FILTER_VALIDATE_BOOL),
    'api_token_default_ttl_days' => (int) env('GEO_API_TOKEN_DEFAULT_TTL_DAYS', 30),
    // 带 X-Idempotency-Key 的写请求在租户+用户+路由+key 维度串行化。
    // 600 秒是硬下限：同步技能执行的 PHP 上限为 420 秒，必须覆盖完整业务和响应落库；
    // 等待 5 秒可让绝大多数并发重试直接拿到首请求结果，长任务则返回明确的可重试 409。
    'api_idempotency' => [
        'lock_seconds' => max(600, (int) env('GEO_API_IDEMPOTENCY_LOCK_SECONDS', 600)),
        'wait_seconds' => max(0, (int) env('GEO_API_IDEMPOTENCY_WAIT_SECONDS', 5)),
    ],
    // 放量前收尾（T1）：v2 工作台开关。默认关；置 true 即恢复 /workbench。
    // 用 filter_var 防止把字符串 "false" 误判为真。
    'workbench_v2_enabled' => filter_var(env('GEO_WORKBENCH_V2_ENABLED', false), FILTER_VALIDATE_BOOL),
    // Phase 1：AI 爬虫可见性面板开关。默认关；真实接线复核通过前不对客户露出（守 workbench 翻车前车之鉴）。
    // 用 filter_var 防止把字符串 "false" 误判为真。
    'analytics_panel_enabled' => filter_var(env('GEO_ANALYTICS_PANEL_ENABLED', true), FILTER_VALIDATE_BOOL),
    // 单 9：队列心跳看板
    // 面板开关（红线 8，第一道回滚）：默认 false，置 true 才在前端运营台露出「队列健康」页 + 端点放行。
    'queue_health_panel_enabled' => filter_var(env('GEO_QUEUE_HEALTH_PANEL_ENABLED', true), FILTER_VALIDATE_BOOL),
    // worker 心跳写入的独立开关（与面板开关分开）：万一心跳写入本身出问题，能只关它、不关整个运营台。默认 true。
    // 它是 worker 内部行为、不是新页面，故默认开（「新页面默认 false」那条红线指的是上面的队列健康面板开关）。
    'worker_heartbeat' => [
        'enabled' => filter_var(env('GEO_WORKER_HEARTBEAT_ENABLED', true), FILTER_VALIDATE_BOOL),
        'interval_seconds' => (int) env('GEO_WORKER_HEARTBEAT_INTERVAL', 15), // 写入节流，红线 3
        'stale_seconds' => (int) env('GEO_WORKER_STALE_SECONDS', 120),        // 判死阈值，下限 30（防配成 5 秒天天误报）
    ],
    'distribution' => [
        'channel_failure_threshold' => (int) env('GEO_CHANNEL_FAILURE_THRESHOLD', 5),
    ],
    'rag' => [
        'require_pgvector_in_production' => filter_var(env('GEO_RAG_REQUIRE_PGVECTOR_IN_PRODUCTION', true), FILTER_VALIDATE_BOOL),
        'sqlite_fallback_enabled' => filter_var(env('GEO_RAG_SQLITE_FALLBACK_ENABLED', env('APP_ENV') !== 'production'), FILTER_VALIDATE_BOOL),
    ],
    // 知识库多模态（视觉誊抄）开关，批次二（执行书 B5）。
    // 默认 false：B7 扣费归属收口没做完、没验收之前，生产不许开（执行书 B7 阻断项）。
    // 开启后 PDF 入库对 needs_vision 的页走 geo-plus 逐页誊抄（VisionPageExtractor），
    // 每页一次真实模型调用 = 真金白银，这就是它必须默认关的原因。
    'knowledge' => [
        'vision_enabled' => filter_var(env('GEO_KNOWLEDGE_VISION', true), FILTER_VALIDATE_BOOL),
    ],
    // 文档入库是否走队列（异步）。关掉时三个控制器退回同步 ingest*，
    // 运维改一行 secrets/geo.env（GEO_ASYNC_INGEST=false）重启即退回，不用重新打镜像。
    // 异步入库失败时是「静默不产出」（文档停在排队中），比「慢」难查得多，所以这个开关是兜底。
    //
    // ⚠️ 环境变量名必须与配置路径对得上：读的是 config('geo.async_ingest')，所以叫
    // GEO_ASYNC_INGEST，不叫 GEO_RAG_ASYNC_INGEST（这个键不在 rag 块里）。回退是急事，
    // 名字对不上会让运维现场猜。
    'async_ingest' => filter_var(env('GEO_ASYNC_INGEST', true), FILTER_VALIDATE_BOOL),

    // ── 出站安全链（批次一 · 单 1）────────────────────────────────────────────
    // SSRF / DNS 重绑定 / 响应体超限防护。默认强制开启。
    //
    // ⚠️ outbound_enforce=false 是「观察模式」，也是本模块唯一的正确回滚方式：
    //    置 false 后「地址类」判定只记日志不拦截（loopback / 内网 / 云元数据 /
    //    DNS 重绑定 / localhost / 歧义 IP），用于线上发现误杀正常站点时立刻止血，
    //    不必回滚整个镜像（回滚镜像会连带回滚其他模块）。
    //
    // ⚠️ 观察模式**不放开**这两类，因为它们不是「误杀」而是真实风险：
    //    1) URL 结构非法（控制字符 / 非 http(s) / 带 userinfo / 端口越界等）
    //       —— 正常站点的 URL 不会长这样，放行等于把垃圾喂给 curl；
    //    2) 响应体超限 —— 站点确实返回了超大响应，放行会真的打爆 worker 内存。
    //    这一条与派单判据 O5 字面「上述全部放行」有意偏离，理由见交付报告。
    'outbound_enforce' => filter_var(env('GEO_OUTBOUND_ENFORCE', true), FILTER_VALIDATE_BOOL),

    // 允许直连的内网目标白名单，形如 "localhost:8792,localhost:80"（host:port，需含端口）。
    // 仅用于确有需要的内网目标（如内网 Caddy 端口）。不支持通配符与 CIDR：
    // 精确匹配是有意的，模糊白名单等于把 SSRF 防护开一个后门。
    'outbound_private_targets' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GEO_OUTBOUND_PRIVATE_TARGETS', ''))
    ), static fn (string $item): bool => $item !== '')),

    // Map of deployer-managed Magpie targets. Tenant settings can only select these aliases.
    'magpie_targets' => json_decode((string) env('GEO_MAGPIE_TARGETS', '{}'), true),

    // 单次出站响应体上限（字节）。AI 类端点单独收紧，其余走全局上限。
    'outbound_ai_max_bytes' => (int) env('GEO_OUTBOUND_AI_MAX_BYTES', 8 * 1024 * 1024),
    'outbound_max_bytes' => (int) env('GEO_OUTBOUND_MAX_BYTES', 50 * 1024 * 1024),
    // URL 导入 / 知识库抓网页的响应体上限（单 2 接入点使用）。
    'outbound_import_max_bytes' => (int) env('GEO_OUTBOUND_IMPORT_MAX_BYTES', 5 * 1024 * 1024),

    // ── AI 可见性监测（批次二 · 单 3–单 6）────────────────────────────────────
    // 总开关：默认关。同时门控前端 /visibility 页面（routes/web.php）与
    // POST /api/v1/visibility/runs（花钱入口）。置 false 是本模块第一道回滚
    // （§13.1），改 secrets/geo.env 的 GEO_VISIBILITY_PANEL_ENABLED 重启即生效。
    //
    // ⚠️ env 名与计划书 §11.2 老板生产操作命令逐字一致（GEO_VISIBILITY_PANEL_ENABLED），
    //    改名会让生产 env 写下去不生效。
    'visibility_panel_enabled' => filter_var(env('GEO_VISIBILITY_PANEL_ENABLED',  true), FILTER_VALIDATE_BOOL),
    // Paid visibility POSTs require a durable account/body claim before dispatch.
    'visibility_run_claim_required' => true,
    // Paid title POSTs require a durable owner/body claim before model dispatch.
    'title_generation_claim_required' => true,
    // Async paid skills require an owner/body claim before queue dispatch.
    'skill_execution_claim_required' => true,

    // ── 手动发布模块（单 7）────────────────────────────────────────────────
    // 总开关：默认关。门控前端 /manual-publish 页面（routes/web.php）与全部
    // /api/v1/manual-publications/* 端点（routes/api.php）——两者都以「路由不注册」
    // 实现，关掉时请求一律 404，不暴露模块存在。
    // 置 false 是本模块第一道回滚，改 secrets/geo.env 的 GEO_MANUAL_PUBLISH_ENABLED
    // 重启即生效。命名与执行书 §三-4 逐字一致。
    'manual_publish_enabled' => filter_var(env('GEO_MANUAL_PUBLISH_ENABLED', true), FILTER_VALIDATE_BOOL),

    // 知乎草稿同步依赖客户本机扩展；提供受鉴权的安装包下载，false 可紧急关闭。
    'zhihu_draft_sync_enabled' => filter_var(env('GEO_ZHIHU_DRAFT_SYNC_ENABLED', true), FILTER_VALIDATE_BOOL),

    // ── 线索表单模块（单 8）────────────────────────────────────────────────
    // 公开表单页总开关：默认关。门控 routes/web.php 的 /forms/{tenantSlug}/{slug}
    // 两条路由（GET 公开页 + POST 提交）。置 false 时路由不注册 → 公开页 404，
    // 是本模块第一道回滚（执行书 §七-8）。改 secrets/geo.env 的 GEO_LEAD_FORM_ENABLED
    // 重启即生效。命名与执行书 §五-3 / L10 逐字一致。
    'lead_form_enabled' => filter_var(env('GEO_LEAD_FORM_ENABLED', true), FILTER_VALIDATE_BOOL),

    // ── 主题包生成模块（单 10）────────────────────────────────────────────
    // 总开关：默认关。门控前端 /theme-package 页面与全部 /api/v1/theme-packages/*
    // 端点（路由不注册 → 404）。置 false 是本模块第一道回滚。改 secrets/geo.env
    // 的 GEO_THEME_PACKAGE_ENABLED 重启即生效。命名与执行书逐字一致。
    'theme_package_enabled' => filter_var(env('GEO_THEME_PACKAGE_ENABLED', true), FILTER_VALIDATE_BOOL),

    // 单租户磁盘配额（字节）。超过直接拒绝新建，不删旧的。默认 500MB。
    // 三道闸之一（另两道：单 replication 版本数上限、保留天数清理）。
    'theme_package' => [
        // 单租户在 storage 下 theme-packages/{tenant_id} 累计字节上限。
        'max_bytes_per_tenant' => (int) env('GEO_THEME_PACKAGE_MAX_BYTES', 500 * 1024 * 1024),
        // 单 replication 版本数上限：超过删最老草稿版本。默认 10。
        'max_versions' => (int) env('GEO_THEME_PACKAGE_MAX_VERSIONS', 10),
        // 保留天数：N 天前的生成物与 zip 由 theme-packages:prune 清理。默认 14。
        'retention_days' => (int) env('GEO_THEME_PACKAGE_RETENTION_DAYS', 14),

        // 打包（zip）限制。上游放在 config/geoflow.php 的四个键，本仓没有那个配置文件，
        // 归到这里。⚠️ 都必须 > 0：ThemeReplicationPackagePathGuard::positiveInteger()
        // 收到 null / 0 会直接 reject（表现为「主题包创建失败」，且查不出原因）。
        'package_max_files' => max(1, (int) env('GEO_THEME_PACKAGE_MAX_FILES', 500)),
        'package_max_file_bytes' => max(1, (int) env('GEO_THEME_PACKAGE_MAX_FILE_BYTES', 5 * 1024 * 1024)),
        'package_max_total_bytes' => max(1, (int) env('GEO_THEME_PACKAGE_MAX_TOTAL_BYTES', 25 * 1024 * 1024)),
        'package_lock_timeout_ms' => max(1, (int) env('GEO_THEME_PACKAGE_LOCK_TIMEOUT_MS', 5000)),
    ],

    'visibility' => [
        // 方舟 key 只进生产 secrets/geo.env（本地 .env 到不了生产，.dockerignore 已排除）。

        // 端点固定北京区 Responses API；改这里必须同时过 AiProviderEndpointPolicy 白名单。


        // 梯度约束：网关240 < HTTP 250..260 < Job 280 < worker 300 < retry_after 600。
        'http_timeout' => (int) env('GEO_VISIBILITY_HTTP_TIMEOUT', 250),
        // 单次提交的问题数上限（「一个问题一个 Job」红线；10 问题约 8–9 分钟）。
        'max_questions' => (int) env('GEO_VISIBILITY_MAX_QUESTIONS', 10),

        // 端点白名单（host 精确匹配，支持 '*.suffix' 子域条目）。防配置事故/密钥外发。
        'allowed_hosts' => ['ark.cn-beijing.volces.com'],
    ],

];
