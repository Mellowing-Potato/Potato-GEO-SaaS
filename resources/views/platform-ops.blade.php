@php
    /* GEO 平台运营台 · 当前工作区运营与系统管理
       使用静态资源版本号 + IIFE 约定；与租户侧工作台完全隔离。
       红线：环境标签必须真实反映 config('app.env')；不渲染任何非 platform:operations 可见数据。 */
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex,nofollow">
    <title>平台运营台 · 言及</title>
    <link rel="icon" href="/brand/yanji/v1/favicon.svg" type="image/svg+xml">
    <script nonce="{{ $cspNonce }}">
        /* 登录地址取自服务端配置，不写死域名；测试/生产环境自动正确 */
        window.GEO_OPS_CONFIG = {
            accountLoginUrl: @json($geoOpsConfig['accountLoginUrl']),
            accountUrl:      @json($geoOpsConfig['accountUrl'])
        };
    </script>
    {{-- 设计令牌：必须排在页面自身样式表【前面】，页面里的覆盖才压得住。 --}}
    <link rel="stylesheet" href="/geo-tokens.css?v={{ file_exists(public_path('geo-tokens.css')) ? \App\Support\PublicAssetVersion::for('geo-tokens.css') : time() }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ \App\Support\PublicAssetVersion::for('geo-fonts.css') }}">
    <link rel="stylesheet" href="/geo-brand-pages.css?v={{ \App\Support\PublicAssetVersion::for('geo-brand-pages.css') }}">
    <link rel="stylesheet" href="/geo-platform-ops.css?v={{ $cssVersion }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ \App\Support\PublicAssetVersion::for('geo-typography.css') }}">
</head>
<body class="geo-platform-ops geo-app">

<!-- 连接中 / 守卫态遮罩：页面首屏先显「连接中」，前端 session-login 后切换为台面或 403 守卫态 -->
<div class="ops-state" id="opsState" hidden>
    <div class="ops-state-box">
        <div class="ob-spinner" id="opsStateIcon" aria-hidden="true"></div>
        <div class="ob-icon" id="opsStateGlyph" hidden>!</div>
        <h2 id="opsStateTitle">连接中…</h2>
        <p id="opsStateText">正在校验运营台访问权限</p>
        <a class="btn" id="opsStateLink" href="#" hidden>登录言及</a>
    </div>
</div>

<header class="topbar">
    <div class="brand geo-brand-lockup"><x-yanji-logo variant="compact" /> <span class="geo-brand-page">平台运营台</span></div>
    <span class="env-tag @if($isProduction) env-prod @endif">{{ $envLabel }}</span>
    <div class="spacer"></div>
    <span class="who" id="opsWho">—</span>
</header>

<nav class="section-nav" aria-label="分区跳转">
    <a href="#sec-subs">当前工作区</a>
    <a href="#sec-health">系统健康</a>
    <a href="#sec-risk">工作区风险</a>
    <a href="#sec-words">敏感词基线</a>
    {{-- 2026-09-21 工作台功能补齐（计划 §5）：平台审计与安全设置此前只有后端路由
         （/api/v1/platform/audit、/api/v1/platform/security-settings），运营台没接线。
         两页都只在 platform_admin 下可见（路由组已挂 can:platform:operations）。 --}}
    <a href="#sec-audit">审计日志</a>
    <a href="#sec-security">安全设置</a>
@if(config('geo.queue_health_panel_enabled'))    <a href="#sec-queue">队列健康</a>
@endif</nav>

<main class="wrap">
    <div class="view-title">
        <h1>平台运营台</h1>
        <span class="vt-sub">当前工作区 · 近 30 天 · 用户、执行与发布状态</span>
    </div>

    <!-- 持久操作横幅挂载位 -->
    <div class="op-banner ok" id="opBanner" role="status">
        <span class="ob-icon">✓</span>
        <span class="ob-body" id="opBannerBody"></span>
        <span class="audit" id="opBannerAudit" title="点击复制"></span>
        <button class="ob-close" aria-label="关闭横幅" id="opBannerClose">×</button>
    </div>

    <!-- ============ 一、钱流总览（签名：钱流链路带） ============ -->


    <!-- ============ 二、订单查询 ============ -->


    <!-- ============ 三、付款异常 ============ -->


    <!-- ============ 四、租户管理 ============ -->
    <section id="sec-subs">
        <div class="panel">
            <div class="panel-head"><h2>当前工作区</h2><div class="spacer"></div><span class="pill ok" id="subActive" hidden>active 0</span><span class="pill neutral" id="subPaused" hidden>已暂停 0</span></div>
            <div class="panel-body" style="padding-top:0;">
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>租户</th><th>状态</th><th>用户数</th><th>近 30 天执行</th><th class="cell-actions">操作</th></tr></thead>
                        <tbody id="subsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 五、发票队列 ============ -->


    <!-- ============ 六、系统健康 ============ -->
    <section id="sec-health">
        <div class="panel">
            <div class="panel-head"><h2>系统健康</h2><span class="hint">近 24 小时</span></div>
            <div class="panel-body">
                <div class="health-row" id="healthRow"></div>
            </div>
        </div>
    </section>

    <!-- ============ 七、风险租户 ============ -->
    <section id="sec-risk">
        <div class="panel">
            <div class="panel-head"><h2>工作区风险</h2><span class="hint">当前工作区需要人工跟进的状态</span></div>
            <div class="panel-body" style="padding-top:0;">
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>租户</th><th>状态</th></tr></thead>
                        <tbody id="riskBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    {{-- ============ 八、平台敏感词基线库（执行书 T8）============
         三块内容缺一不可，理由都不是「功能完整性」而是「误伤谁来发现」：
           1. 词表 + 批量加词：老板第 6 条「平台随时能更新」的落点，改完立刻生效。
           2. 加词前试算：误伤的**事前**闸门。租户不能自助停用平台词，
              代价全由平台承担，所以按下保存之前必须先看到会拦掉多少篇现有稿子。
           3. 按词聚合：误伤的**事后**发现手段。租户侧不会来报障（不点名、不开申诉），
              加错的常用词只能靠命中篇数倒序自己浮上来。 --}}
    <section id="sec-words">
        <div class="panel">
            <div class="panel-head">
                <h2>平台敏感词基线库</h2>
                <span class="hint">全平台共享 · 租户不可见、不可自行停用</span>
                <div class="spacer"></div>
                <span class="pill neutral" id="swVersion" title="平台词缓存版本号：写操作后应立即进位">版本 —</span>
                <span class="pill ok" id="swEnabledCount" hidden>生效 0</span>
            </div>

            <div class="panel-body">
                {{-- 加词区：试算是硬闸门，没跑过试算「保存」按钮不可点（见 JS syncWordSaveGate） --}}
                <details class="sw-compose">
                    <summary>新增敏感词与影响试算</summary>
                    <div class="field">
                        <label for="swBulk">批量加词（每行一个，或用逗号 / 顿号分隔）</label>
                        <textarea id="swBulk" rows="4" placeholder="每行一个词"></textarea>
                    </div>
                    <div class="sw-compose-row">
                        <label class="sw-inline">等级
                            <select id="swSeverity">
                                <option value="blocked" selected>blocked（命中即拦）</option>
                                <option value="warning">warning（仅提示）</option>
                            </select>
                        </label>
                        <label class="sw-inline">分类
                            <select id="swCategory">
                                <option value="politics" selected>politics</option>
                                <option value="illegal">illegal</option>
                            </select>
                        </label>
                        <div class="spacer"></div>
                        <button class="btn" id="swDryRunBtn">试算影响面</button>
                        <button class="btn primary-fill" id="swSaveBtn" disabled title="必须先跑一次试算，看到影响面之后才能保存">保存到基线库</button>
                    </div>
                    <p class="sw-gate-note" id="swGateNote">保存前先对当前工作区试算；结果不代表其他工作区。保存的基线词仍对整个系统生效。</p>
                </details>

                {{-- 试算结果 --}}
                <div class="sw-dry" id="swDryResult" hidden>
                    <div class="sw-dry-head">
                        <span class="sw-dry-title" id="swDryTitle">试算中…</span>
                        <div class="spacer"></div>
                        <span class="hint" id="swDryMeta"></span>
                    </div>
                    <div class="sw-dry-body" id="swDryBody"></div>
                </div>
            </div>

            <div class="panel-body" style="padding-top:0;">
                <div class="toolbar">
                    <select id="swFilterCategory" aria-label="分类筛选">
                        <option value="">全部分类</option>
                        <option value="politics">politics</option>
                        <option value="illegal">illegal</option>
                    </select>
                    <select id="swFilterEnabled" aria-label="状态筛选">
                        <option value="">全部状态</option>
                        <option value="1">生效中</option>
                        <option value="0">已停用</option>
                    </select>
                    <input type="search" id="swSearch" placeholder="搜索词" aria-label="搜索平台敏感词">
                    <button class="btn ghost" id="swReloadBtn">刷新</button>
                </div>
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>词</th><th>等级</th><th>分类</th><th>状态</th><th>备注</th><th>更新时间</th><th class="cell-actions">操作</th></tr></thead>
                        <tbody id="swBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- 按词聚合：默认命中篇数倒序，加错的词自己浮到最上面 --}}
        <div class="panel">
            <div class="panel-head">
                <h2>按词聚合</h2>
                <span class="hint">当前工作区 · 按命中篇数倒序</span>
                <div class="spacer"></div>
                <select id="swSummaryDays" aria-label="时间窗">
                    <option value="">全部时间</option>
                    <option value="7">近 7 天</option>
                    <option value="30" selected>近 30 天</option>
                    <option value="90">近 90 天</option>
                </select>
            </div>
            <div class="panel-body" style="padding-top:0;">
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>词</th><th>来源</th><th>命中篇数</th><th>命中工作区数</th><th>命中次数</th><th>最近命中</th><th class="cell-actions">操作</th></tr></thead>
                        <tbody id="swSummaryBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- 命中日志：给定文章 ID 必须能答出「被哪条词拦的」--}}
        <div class="panel">
            <div class="panel-head">
                <h2>命中日志</h2>
                <span class="hint">仅展示当前工作区的命中记录</span>
                <div class="spacer"></div>
                <div class="toolbar">
                    <input type="search" id="swHitArticle" placeholder="文章 ID" aria-label="按文章 ID 筛选" inputmode="numeric">
                    <input type="search" id="swHitWord" placeholder="词" aria-label="按词筛选">
                    <select id="swHitOrigin" aria-label="来源筛选">
                        <option value="">全部来源</option>
                        <option value="platform">平台基线词</option>
                        <option value="tenant">租户自加词</option>
                    </select>
                    <button class="btn ghost" id="swHitReloadBtn">查询</button>
                </div>
            </div>
            <div class="panel-body" style="padding-top:0;">
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>时间</th><th>租户</th><th>文章</th><th>词</th><th>来源</th><th>字段</th><th>触发点</th><th>上下文</th></tr></thead>
                        <tbody id="swHitBody"></tbody>
                    </table>
                </div>
                <p class="sw-gate-note" id="swHitRetention"></p>
            </div>
        </div>
    </section>

@if(config('geo.queue_health_panel_enabled'))
    <!-- ============ 队列健康（单 9） ============ -->
    <section id="sec-queue">
        <div class="panel">
            <div class="panel-head">
                <h2>队列健康</h2><span class="hint">worker 心跳 · 无消费者检测 · 积压</span>
                <div class="spacer"></div>
                <button class="btn ghost" id="queueRefreshBtn">刷新</button>
            </div>

            <div id="queueAlerts"></div>

            <h3 class="sub">Worker 心跳</h3>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>Worker</th><th>状态</th><th>最后心跳</th><th>判死</th><th>消费队列</th></tr></thead>
                    <tbody id="queueWorkers"></tbody>
                </table>
            </div>

            <h3 class="sub">队列积压</h3>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>队列</th><th>待消费</th><th>最老等待</th><th>处理中卡住</th><th>近 24h 失败</th></tr></thead>
                    <tbody id="queueDepth"></tbody>
                </table>
            </div>
        </div>
    </section>
@endif

    <!-- ============ 八之二、对账待办与人工复核（计划 §9.6，2026-09-21） ============
         落点选平台运营台（决策清单 B3 的推荐项）：待办是平台侧资金问题，租户看到只会
         引发无法自助解决的恐慌；同页已有钱流总览/付款异常/发票队列，复核上下文都在手边。
         红线：这里只落"谁/何时/依据什么/下了什么结论"，**不做任何资金写操作** ——
         真正的冲正必须带着这条结论去网关的资金复核流程执行。 -->


    <!-- ============ 九、审计日志（2026-09-21 补接线） ============ -->
    <section id="sec-audit">
        <div class="panel">
            <div class="panel-head">
                <h2>审计日志</h2>
                <span class="hint">当前工作区的关键操作记录 · 只读</span>
                <div class="spacer"></div>
                <button class="btn ghost" id="auditRefreshBtn">刷新</button>
            </div>
            <div class="panel-body">
                <div class="toolbar">
                    <input type="search" id="auditAction" placeholder="动作关键字（如 order）" aria-label="按动作筛选">
                    <input type="search" id="auditTargetType" placeholder="对象类型（如 article）" aria-label="按对象类型筛选">
                    <input type="date" id="auditFrom" aria-label="起始日期">
                    <input type="date" id="auditTo" aria-label="结束日期">
                    <select id="auditPerPage" aria-label="每页条数">
                        <option value="25" selected>25 条 / 页</option>
                        <option value="50">50 条 / 页</option>
                        <option value="100">100 条 / 页</option>
                    </select>
                    <button class="btn" id="auditApplyBtn">查询</button>
                </div>
                <div class="toolbar" style="margin-top:8px;">
                    <span class="hint" id="auditMeta">—</span>
                    <div class="spacer"></div>
                    <button class="btn ghost" id="auditPrevBtn" disabled>上一页</button>
                    <button class="btn ghost" id="auditNextBtn" disabled>下一页</button>
                </div>
                <div class="tbl-wrap">
                    <table class="tbl">
                        {{-- 租户列（2026-09-22）：§9.7 起租户侧的敏感动作（渠道密钥、API 令牌、
                             AI 凭据、删数据）也写进这张表，行里带 tenant_id；此前界面不给这一列，
                             平台侧看到 channel_secret.created 根本判断不出是谁家的。 --}}
                        <thead><tr><th>时间</th><th>动作</th><th>操作人</th><th>租户</th><th>对象</th><th>详情</th></tr></thead>
                        <tbody id="auditBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 十、安全设置（2026-09-21 补接线） ============ -->
    <section id="sec-security">
        <div class="panel">
            <div class="panel-head">
                <h2>安全设置</h2>
                <span class="hint">平台级 · 写入即落审计</span>
                <div class="spacer"></div>
                <button class="btn ghost" id="secReloadBtn">重新读取</button>
            </div>
            <div class="panel-body">
                <div class="field">
                    <label for="secPasswordMin">密码最小长度（6–64）</label>
                    <input id="secPasswordMin" type="number" min="6" max="64" step="1">
                </div>
                <div class="field">
                    <label for="secRequireSpecial">密码必须包含特殊字符</label>
                    <select id="secRequireSpecial">
                        <option value="0">否</option>
                        <option value="1">是</option>
                    </select>
                </div>
                <div class="field">
                    <label for="secRateLimit">接口每分钟限流（1–10000）</label>
                    <input id="secRateLimit" type="number" min="1" max="10000" step="1">
                </div>
                {{-- 敏感词留空 = 不改动现状：直接提交空数组会把平台词清空，代价由租户承担。 --}}
                <div class="field">
                    <label for="secSensitiveWords">附加平台敏感词（每行一个；留空表示不改动）</label>
                    <textarea id="secSensitiveWords" rows="3" placeholder="每行一个词"></textarea>
                </div>
                <p class="sw-gate-note" id="secNote">平台级设置对所有租户生效，保存会自动记录审计日志。修改密码策略前先确认不会把现有用户锁在门外。</p>
                <div class="toolbar">
                    <button class="btn primary-fill" id="secSaveBtn">保存设置</button>
                    <span class="hint" id="secSavedAt"></span>
                </div>
            </div>
        </div>
    </section>
</main>

<!-- ============ 危险确认弹窗（暂停账号 = 一级：原因必填） ============ -->
<div class="dm-mask" id="dmMask">
    <section class="dmodal" role="alertdialog" aria-modal="true" aria-labelledby="dmTitle">
        <h3 id="dmTitle">暂停账号 · <span id="dmTenant"></span></h3>
        <div class="dm-sub">暂停后该租户所有技能执行与分发立即停止，工作台显示「订阅已暂停」。可随时恢复，不退款。</div>
        <div class="dm-impact">
            <div class="row"><span class="k">生效至</span><span class="v" id="dmUntil">—</span></div>
            <div class="row"><span class="k">暂停后</span><span class="v minus">执行 / 分发全部停止</span></div>
        </div>
        <div class="field" id="dmReasonField">
            <label for="dmReason">操作原因（必填，写入审计日志）</label>
            <select id="dmReason">
                <option value="">请选择</option>
                <option>欠费催缴未响应</option>
                <option>违规内容处置</option>
                <option>租户主动申请</option>
                <option>其他</option>
            </select>
        </div>
        <div class="field" id="dmNoteField" hidden>
            <label for="dmNote" id="dmNoteLabel">备注（必填，写入审计日志）</label>
            <textarea id="dmNote" rows="3"></textarea>
        </div>
        <div class="audit-note">执行将写入平台审计日志（操作人、时间、租户、原因）。</div>
        <div class="dm-actions">
            <button class="btn" id="dmCancel">取消</button>
            <button class="btn danger-fill" id="dmConfirm" disabled>暂停账号</button>
        </div>
    </section>
</div>

<script src="/geo-platform-ops.js?v={{ $jsVersion }}" defer></script>
</body>
</html>
