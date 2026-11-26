@php
    /* GEO AI 爬虫可见性面板 · 租户侧净新增看板（Phase 1）
       同构 workbench / platform-ops：blade 空壳 + public/geo-analytics.js（vanilla fetch）+ config 门控。
       红线：
         - 真实权限门在 API 层（/v1/analytics/* 已挂 auth:sanctum + can:analytics:view）；
         - 页面首屏先显「连接中」，前端 session-login 后切台面或 403 守卫态；
         - 仅消费真实端点，前端无 fixture 静默兜底（守 geo-frontend-backend-integration-discipline）。 */
    $geoAnalyticsConfig = [
        'accountLoginUrl' => url('/login'),
        'accountUrl' => url('/#personal-settings'),
    ];
    $cssVersion = file_exists(public_path('geo-analytics.css')) ? \App\Support\PublicAssetVersion::for('geo-analytics.css') : time();
    $jsVersion  = file_exists(public_path('geo-analytics.js'))  ? \App\Support\PublicAssetVersion::for('geo-analytics.js')  : time();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex,nofollow">
    <title>AI 爬虫访问 · 言及</title>
    <link rel="icon" href="/brand/yanji/v1/favicon.svg" type="image/svg+xml">
    <script nonce="{{ $cspNonce }}">
        window.GEO_ANALYTICS_CONFIG = @json($geoAnalyticsConfig);
    </script>
    {{-- 设计令牌：必须排在页面自身样式表【前面】，页面里的覆盖才压得住。 --}}
    <link rel="stylesheet" href="/geo-tokens.css?v={{ file_exists(public_path('geo-tokens.css')) ? \App\Support\PublicAssetVersion::for('geo-tokens.css') : time() }}">
    <link rel="stylesheet" href="/geo-shell.css?v={{ \App\Support\PublicAssetVersion::for('geo-shell.css') }}">
    <link rel="stylesheet" href="/geo-components.css?v={{ \App\Support\PublicAssetVersion::for('geo-components.css') }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ \App\Support\PublicAssetVersion::for('geo-fonts.css') }}">
    <link rel="stylesheet" href="/geo-brand-pages.css?v={{ \App\Support\PublicAssetVersion::for('geo-brand-pages.css') }}">
    <link rel="stylesheet" href="/geo-analytics.css?v={{ $cssVersion }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ \App\Support\PublicAssetVersion::for('geo-typography.css') }}">
    <link rel="stylesheet" href="/geo-page-navigation.css?v={{ \App\Support\PublicAssetVersion::for('geo-page-navigation.css') }}">
</head>
<body class="geo-analytics geo-app geo-standalone">

<!-- 连接中 / 守卫态遮罩 -->
<div class="ops-state" id="guardState" hidden>
    <div class="ops-state-box">
        <div class="ob-spinner" id="guardIcon" aria-hidden="true"></div>
        <div class="ob-icon" id="guardGlyph" hidden>!</div>
        <h2 id="guardTitle">连接中…</h2>
        <p id="guardText">正在校验可见性面板访问权限</p>
        <a class="btn" id="guardLink" href="#" hidden>登录言及</a>
    </div>
</div>

<div class="geo-page-shell">
    @include('partials.workspace-topbar', ['mode' => 'page'])
    @include('partials.tenant-workspace-sidebar', ['currentPage' => 'analytics'])
    <div class="geo-page-content">

<nav class="section-nav" aria-label="分区跳转">
    <div class="section-nav-inner">
        <a href="#sec-overview" class="on">总览</a>
        <a href="#sec-trend">AI-PV 趋势</a>
        <a href="#sec-topbots">Top AI 爬虫</a>
        <a href="#sec-articles">Top 文章</a>
        <a href="#sec-beacon">信标健康度</a>
        <a href="#sec-logs">原始日志</a>
    </div>
</nav>

<main class="wrap">
    <header class="ga-header">
        <div class="ga-header-copy">
            <div class="view-title">
                <h1>AI 爬虫访问</h1>
                <span class="vt-sub" id="gaPeriod">—</span>
            </div>
            <p class="monitoring-scope">这里记录站点被 AI 爬虫访问的情况；访问量不代表品牌已出现在智能回答中。</p>
        </div>
        <label class="days-pick" for="daysSel">统计周期
            <select id="daysSel" aria-label="统计周期">
                <option value="7">近 7 天</option>
                <option value="30" selected>近 30 天</option>
                <option value="90">近 90 天</option>
            </select>
        </label>
    </header>

    <!-- ============ 一、总览：Tremor 顶层 KPI 卡片带 ============ -->
    <section id="sec-overview" aria-label="流量总览指标">
        <div class="kpi-row" id="kpiRow">
            <div class="kpi">
                <div class="k-head"><span class="k-label">总 PV</span><span class="k-badge neutral" data-kpi-meta="pv">—</span></div>
                <div class="k-num" data-kpi="pv">—</div>
            </div>
            <div class="kpi kpi-featured">
                <div class="k-head"><span class="k-label"><span class="k-dot ai" aria-hidden="true"></span>AI 爬虫 PV</span><span class="k-badge ai" data-kpi-meta="ai_bot_pv">—</span></div>
                <div class="k-num" data-kpi="ai_bot_pv">—</div>
            </div>
            <div class="kpi">
                <div class="k-head"><span class="k-label"><span class="k-dot human" aria-hidden="true"></span>真人 PV</span><span class="k-badge neutral" data-kpi-meta="human_pv">—</span></div>
                <div class="k-num" data-kpi="human_pv">—</div>
            </div>
            <div class="kpi">
                <div class="k-head"><span class="k-label"><span class="k-dot search" aria-hidden="true"></span>搜索爬虫 PV</span><span class="k-badge neutral" data-kpi-meta="search_bot_pv">—</span></div>
                <div class="k-num" data-kpi="search_bot_pv">—</div>
            </div>
            <div class="kpi">
                <div class="k-head"><span class="k-label">独立 IP</span><span class="k-badge neutral" data-kpi-meta="unique_ip">—</span></div>
                <div class="k-num" data-kpi="unique_ip">—</div>
            </div>
        </div>
    </section>

    <!-- ============ 二、主分析网格：AreaChart 趋势 + 流量构成 Donut/CategoryBar ============ -->
    <div class="ga-grid-main">
        <section id="sec-trend">
            <div class="panel ga-card-fill">
                <div class="panel-head">
                    <div class="ga-head-title">
                        <h3>每日 PV 与 AI 爬虫 PV</h3>
                        <span class="hint">按日对比总访问量与 AI 爬虫访问量</span>
                    </div>
                    <div class="spacer"></div>
                    <div class="trend-legend" aria-label="图例">
                        <span><span class="sw total"></span>总 PV</span>
                        <span><span class="sw ai"></span>AI 爬虫 PV</span>
                    </div>
                </div>
                <div class="panel-body" id="trendHost"></div>
            </div>
        </section>

        <section id="sec-breakdown" aria-label="流量构成">
            <div class="panel ga-card-fill">
                <div class="panel-head">
                    <div class="ga-head-title">
                        <h3>流量构成</h3>
                        <span class="hint" id="ovHint"></span>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="donut-wrap" id="donutWrap"><!-- 流量构成环 + CategoryBar（纯 SVG） --></div>
                </div>
            </div>
        </section>
    </div>

    <!-- ============ 三、明细网格：Top AI 爬虫 BarList + Top 文章 Table ============ -->
    <div class="ga-grid-split">
        <section id="sec-topbots">
            <div class="panel ga-card-fill">
                <div class="panel-head">
                    <div class="ga-head-title">
                        <h3>Top AI 爬虫</h3>
                        <span class="hint">按命中（hits）排序 · 近周期</span>
                    </div>
                </div>
                <div class="panel-body" id="topBotsHost"></div>
            </div>
        </section>

        <section id="sec-articles">
            <div class="panel ga-card-fill">
                <div class="panel-head">
                    <div class="ga-head-title">
                        <h3>Top 文章（按访问量）</h3>
                        <span class="hint">近周期 · 最多 10 篇</span>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="tbl-wrap">
                        <table class="tbl">
                            <thead><tr><th>文章</th><th class="num">访问量</th><th class="num">独立 IP</th></tr></thead>
                            <tbody id="articlesBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- ============ 五、信标健康度 ============ -->
    <section id="sec-beacon">
        <div class="panel">
            <div class="panel-head">
                <div class="ga-head-title">
                    <h3>外站信标健康度</h3>
                    <span class="hint" id="beaconHint"></span>
                </div>
            </div>
            <div class="panel-body">
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>分发渠道</th><th>最后回传</th><th>状态</th><th class="num">近 7 日命中</th></tr></thead>
                        <tbody id="beaconBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <!-- ============ 六、原始日志 ============ -->
    <section id="sec-logs">
        <div class="panel">
            <div class="panel-head">
                <div class="ga-head-title">
                    <h3>原始访问日志</h3>
                </div>
                <div class="spacer"></div>
                <div class="toolbar">
                    <select id="logTraffic" aria-label="流量类型筛选">
                        <option value="">全部流量</option>
                        <option value="human">真人</option>
                        <option value="ai_bot">AI 爬虫</option>
                        <option value="search_bot">搜索爬虫</option>
                        <option value="other_bot">其他爬虫</option>
                        <option value="unknown">未知</option>
                    </select>
                    <input type="search" id="logPath" placeholder="路径关键字" aria-label="路径关键字">
                    <button class="btn ghost" id="logSearchBtn">查询</button>
                    <button class="btn ghost" id="logResetBtn">重置</button>
                </div>
            </div>
            <div class="panel-body">
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr><th>时间</th><th>类型</th><th>爬虫</th><th>路径</th><th>IP</th><th class="num">状态</th></tr></thead>
                        <tbody id="logsBody"></tbody>
                    </table>
                </div>
                <div class="pager" id="logsPager"></div>
            </div>
        </div>
    </section>
</main>
    </div>
</div>

<script src="/geo-page-navigation.js?v={{ \App\Support\PublicAssetVersion::for('geo-page-navigation.js') }}" defer></script>
<script src="/geo-analytics.js?v={{ $jsVersion }}" defer></script>
</body>
</html>
