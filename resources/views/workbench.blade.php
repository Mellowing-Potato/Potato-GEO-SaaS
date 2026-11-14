@php
    /* 复用 welcome.blade.php 中完全相同的 GEO_ENTRY_CONFIG 定义（逐项不改，红线：不得变更 window.GEO_ENTRY_CONFIG / API / 表单 name） */
    $geoEntryConfig = [
        'sessionLoginEndpoint' => '/api/v1/auth/session-login',
        'tenantDomain' => config('app.tenant_domain'),
        'tenantSubdomainsEnabled' => (bool) config('app.tenant_subdomains_enabled', false),
        'accountLoginUrl' => url('/login'),
        'accountUrl' => url('/#personal-settings'),
        'projectsEndpoint' => '/api/v1/projects',
        'skillsEndpoint' => '/api/v1/skills',
        'skillExecuteEndpoint' => '/api/v1/skills/execute',
        'skillExecuteAsyncEndpoint' => '/api/v1/skills/execute-async',
    ];
    $geoWorkbenchCssVersion    = file_exists(public_path('geo-workbench.css'))        ? filemtime(public_path('geo-workbench.css'))        : time();
    $geoWorkbenchJsVersion     = file_exists(public_path('geo-workbench.js'))         ? filemtime(public_path('geo-workbench.js'))         : time();
    $geoWorkbenchViewsVersion  = file_exists(public_path('geo-workbench-views.js'))   ? filemtime(public_path('geo-workbench-views.js'))   : time();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>工作台 · 言及</title>
    <link rel="icon" href="/brand/yanji/v1/favicon.svg" type="image/svg+xml">
    {{-- 设计令牌：必须排在页面自身样式表【前面】，页面里的覆盖才压得住。 --}}
    <link rel="stylesheet" href="/geo-tokens.css?v={{ file_exists(public_path('geo-tokens.css')) ? filemtime(public_path('geo-tokens.css')) : time() }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ filemtime(public_path('geo-fonts.css')) }}">
    <link rel="stylesheet" href="/geo-brand-pages.css?v={{ filemtime(public_path('geo-brand-pages.css')) }}">
    <link rel="stylesheet" href="/geo-workbench.css?v={{ $geoWorkbenchCssVersion }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ filemtime(public_path('geo-typography.css')) }}">
</head>
<body class="geo-workbench geo-app">

<header class="topbar">
    <div class="brand geo-brand-lockup"><x-yanji-logo variant="compact" /> <span class="geo-brand-page">工作台</span></div>
    <div class="tenant">{{ $geoEntryConfig['tenantDomain'] }} · 专业版</div>
    <div class="topbar-spacer"></div>
    <span class="balance-chip" id="topBalanceChip">智达积分 <strong>—</strong></span>
    <a class="top-link" id="accountLink" href="{{ $geoEntryConfig['accountUrl'] }}">个人设置</a>
    <div class="avatar" id="userAvatar">陈</div>
</header>

<div class="shell">
    <!-- ======= 侧栏：项目情境卡 + 分组导航（v2 新结构） ======= -->
    <aside class="sidebar">
        <!-- 项目情境卡：点击展开项目切换下拉 -->
        <div class="proj-context-wrap">
            <button type="button" class="proj-context" id="projContextBtn" aria-haspopup="true" aria-expanded="false" title="切换项目">
                <span class="pc-label">当前项目</span>
                <span class="pc-name" id="pcName">—</span>
                <span class="pc-meta" id="pcMeta">加载中…</span>
            </button>
            <div class="proj-dropdown" id="projDropdown" role="listbox" aria-label="项目列表" hidden>
                <div class="proj-dropdown-list" id="projDropdownList" role="listbox" aria-label="项目"></div>
                <button type="button" class="side-new-btn" id="newProjectBtn">＋ 新建项目</button>
            </div>
        </div>

        <!-- 分组导航（顺序 = GEO 工作流） -->
        <nav aria-label="工作台导航">
            <div class="nav-group">
                <button type="button" class="nav-item" data-nav="work" aria-current="true">项目工作台</button>
            </div>

            <div class="nav-group" role="group" aria-label="内容">
                <div class="nav-group-label">内容</div>
                <button type="button" class="nav-item" data-nav="content">内容库 <span class="n-count" data-nav-badge="content" hidden></span></button>
                <button type="button" class="nav-item" data-nav="material">素材库</button>
                <button type="button" class="nav-item" data-nav="knowledge">知识库</button>
            </div>

            <div class="nav-group" role="group" aria-label="增长">
                <div class="nav-group-label">增长</div>
                <button type="button" class="nav-item" data-nav="channels">分发渠道 <span class="n-dot" data-nav-dot="channels" hidden title="有渠道连接异常"></span></button>
                <button type="button" class="nav-item" data-nav="tasks">自动化任务 <span class="n-count" data-nav-badge="tasks" hidden></span></button>
                <button type="button" class="nav-item" data-nav="insights">数据洞察</button>
            </div>

            <div class="nav-group" role="group" aria-label="经营与设置">
                <div class="nav-group-label">经营</div>
                <button type="button" class="nav-item" data-nav="biz">经营状态</button>
                <button type="button" class="nav-item" data-nav="settings">设置</button>
            </div>
        </nav>

        <div class="side-foot">
            <a href="#">帮助文档</a>
            <a href="{{ $geoEntryConfig['accountUrl'] }}">返回官网</a>
        </div>
    </aside>

    <!-- ======= 主区 ======= -->
    <main class="main">

        <!-- ————— 视图：项目工作台（v1 已实现，原样保留，仅加 data-view 挂载点） ————— -->
        <section id="view-work" data-view="work">
            <!-- 项目头 + 阶段轨 -->
            <div class="panel" id="projectDetail">
                <div class="project-head">
                    <div class="project-title">
                        <h1 id="pdName">—</h1>
                        <div class="sub" id="pdSub">加载中…</div>
                    </div>
                    <div class="spacer"></div>
                    <span class="badge neutral" id="pdStatus">—</span>
                    <button type="button" class="btn primary" id="openDrawerBtn" data-open-drawer>执行技能</button>
                </div>
                <div class="stage-rail" id="stageList" aria-label="项目阶段进度">
                    <ol>
                        <li><span class="seg"></span><span class="lab">1 品牌诊断</span></li>
                        <li><span class="seg"></span><span class="lab">2 关键词策略</span></li>
                        <li><span class="seg"></span><span class="lab">3 内容生产</span></li>
                        <li><span class="seg"></span><span class="lab">4 站内落地</span></li>
                        <li><span class="seg"></span><span class="lab">5 分发引用</span></li>
                        <li><span class="seg"></span><span class="lab">6 效果追踪</span></li>
                    </ol>
                </div>
            </div>

            <div class="grid-2">
                <!-- 当前阶段任务 -->
                <div class="panel" id="stagePanelHost">
                    <div class="panel-head">
                        <h2 id="stagePanelTitle">当前阶段</h2>
                        <div class="spacer"></div>
                        <span class="hint" id="stagePanelHint"></span>
                    </div>
                    <div class="panel-body" data-state-host data-endpoint="currentStage"></div>
                </div>

                <!-- 报告与文章 -->
                <div class="panel" id="assetPanel">
                    <div class="panel-head">
                        <h2>产出内容</h2>
                        <div class="spacer"></div>
                        <div class="asset-tabs" role="tablist" id="assetTabs">
                            <button type="button" role="tab" aria-selected="true" data-asset="articles">文章</button>
                            <button type="button" role="tab" aria-selected="false" data-asset="reports">报告</button>
                            <button type="button" role="tab" aria-selected="false" data-asset="analyses">分析历史</button>
                        </div>
                    </div>
                    <div class="panel-body" data-state-host data-endpoint="assets"></div>
                </div>
            </div>
        </section>

        <!-- ————— 视图：经营状态（v1 已实现，原样保留，仅加 data-view 挂载点） ————— -->
        <section id="view-biz" data-view="biz" hidden>
            <div class="biz-grid">
                <!-- 模型通道 -->
                <div class="panel">
                    <div class="panel-head"><h2>模型通道</h2><div class="spacer"></div><span class="badge neutral" id="svcBadge">检测中</span></div>
                    <div class="panel-body" data-state-host data-endpoint="service"></div>
                </div>

                <!-- 运营概览 -->
                <div class="panel">
                    <div class="panel-head"><h2>运营概览</h2><div class="spacer"></div><button type="button" class="btn ghost sm" data-refresh="ops">刷新</button></div>
                    <div class="panel-body" data-state-host data-endpoint="ops"></div>
                </div>

            </div>
        </section>

        <!-- ————— 以下七个新视图：blade 仅放空壳，内容由 geo-workbench-views.js 渲染 ————— -->
        <section data-view="content" hidden></section>
        <section data-view="material" hidden></section>
        <section data-view="knowledge" hidden></section>
        <section data-view="channels" hidden></section>
        <section data-view="tasks" hidden></section>
        <section data-view="insights" hidden></section>
        <section data-view="settings" hidden></section>
    </main>
</div>

<!-- ======= 技能执行抽屉（v1，保留） ======= -->
<div class="drawer-mask" id="drawerMask"></div>
<aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="drawerTitle" id="skillDrawer">
    <div class="drawer-head">
        <h2 id="drawerTitle">执行技能</h2>
        <div class="spacer" style="flex:1"></div>
        <button type="button" class="btn ghost sm" id="drawerClose" aria-label="关闭">✕ 关闭</button>
    </div>

    <div class="drawer-body">
        <!-- 态 1：表单 -->
        <div data-drawer-state="form">
            <div class="field">
                <label for="f-skill">技能</label>
                <select id="f-skill" name="skill"></select>
            </div>
            <div class="field">
                <label for="f-source">源内容</label>
                <select id="f-source" name="source">
                    <option value="kb">知识库 · 当前项目知识库</option>
                    <option value="paste">手动粘贴</option>
                </select>
                <div class="help">从项目知识库选择，或切换为手动输入。</div>
            </div>
            <div class="field">
                <label for="f-extra">补充要求（可选）</label>
                <textarea id="f-extra" name="extra" placeholder="例：突出无痛种植与价格透明，语气面向 30-45 岁本地患者。"></textarea>
            </div>
            <div class="field">
                <label>预计消耗</label>
                <div class="kv" style="border:1px solid var(--md-border-subtle); border-radius:6px; padding:8px 12px;">
                    <span class="k">本次执行</span><span class="v" id="estCost" style="color:var(--md-value)">— 积分</span>
                </div>
            </div>
        </div>

        <!-- 态 2：执行中 -->
        <div data-drawer-state="running" hidden>
            <p style="font-size:13.5px; font-weight:600;">正在执行 · 已用时 <span id="runSecs">0</span> 秒</p>
            <div class="run-bar"><i></i></div>
            <div class="run-log" id="runLog">→ 正在初始化任务…</div>
            <p style="font-size:12.5px; color:var(--md-text-faint); margin-top:12px;">执行超过 15 秒会转入后台继续，可随时回到本抽屉查看。</p>
        </div>

        <!-- 态 3：结果 -->
        <div data-drawer-state="result" hidden>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                <span class="badge ok">执行成功</span>
                <span style="font-size:12.5px; color:var(--md-text-faint)" id="resultMeta">耗时 — 秒 · 消耗 — 积分</span>
            </div>
            <div class="result-doc" id="resultDoc"></div>
        </div>

        <!-- 态 4：失败 -->
        <div data-drawer-state="error" hidden>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                <span class="badge fail">执行失败</span>
                <span style="font-size:12.5px; color:var(--md-text-faint)">错误编号 <button type="button" class="err-code" id="errCode" title="点击复制">SK-000000-0000</button></span>
            </div>
            <p style="font-size:13px; color:var(--md-text-secondary);" id="errReason">执行失败。</p>
        </div>
    </div>

    <div class="drawer-foot">
        <div data-drawer-state="form" style="display:flex; gap:10px;">
            <button type="button" class="btn" id="drawerCancel1">取消</button>
            <button type="button" class="btn primary" id="runBtn">开始执行</button>
        </div>
        <div data-drawer-state="running" hidden style="display:flex; gap:10px;">
            <button type="button" class="btn danger-ghost" id="cancelRunBtn">中止执行</button>
            <button type="button" class="btn" id="bgRunBtn">转后台执行</button>
        </div>
        <div data-drawer-state="result" hidden style="display:flex; gap:10px;">
            <button type="button" class="btn" id="againBtn">再次执行</button>
            <button type="button" class="btn ghost" id="downloadDocxBtn">下载 docx</button>
            <button type="button" class="btn primary" id="saveAssetBtn">保存到产出内容</button>
        </div>
        <div data-drawer-state="error" hidden style="display:flex; gap:10px;">
            <button type="button" class="btn" id="backFormBtn">返回修改</button>
        </div>
    </div>
</aside>

<!-- ======= 文章详情抽屉（v2 新增，与技能抽屉同规格 560px / ESC / 焦点管理） ======= -->
<aside class="drawer" role="dialog" aria-modal="true" aria-labelledby="adTitle" id="articleDrawer">
    <div class="drawer-head">
        <h2 id="adTitle">文章详情</h2>
        <div class="spacer" style="flex:1"></div>
        <button type="button" class="btn ghost sm" id="articleDrawerClose" aria-label="关闭">✕ 关闭</button>
    </div>
    <div class="drawer-body" id="articleDrawerBody">
        <!-- 由 geo-workbench-views.js 渲染 -->
    </div>
    <div class="drawer-foot" id="articleDrawerFoot">
        <!-- 由 geo-workbench-views.js 渲染 -->
    </div>
</aside>

<script nonce="{{ $cspNonce }}">
    /* 与 welcome.blade.php 完全相同的 GEO_ENTRY_CONFIG（逐项不改，红线） */
    window.GEO_ENTRY_CONFIG = @json($geoEntryConfig);
</script>
<script src="/geo-workbench.js?v={{ $geoWorkbenchJsVersion }}" defer></script>
<script src="/geo-workbench-views.js?v={{ $geoWorkbenchViewsVersion }}" defer></script>
</body>
</html>
