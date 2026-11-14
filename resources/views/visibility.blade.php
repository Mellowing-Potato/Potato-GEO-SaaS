@php
    /* GEO AI 可见性监测面板 · 租户侧净新增看板（Phase 1）
       同构 workbench / platform-ops / analytics：blade 空壳 + public/geo-visibility.js（vanilla fetch）+ config 门控。
       红线：
         - 真实权限门在 API 层（/v1/visibility/* 已挂 auth:sanctum）；
         - 页面首屏先显「连接中」，前端 session-login 后切台面或 403 守卫态；
         - 仅消费真实端点，前端无 fixture 静默兜底（守 geo-frontend-backend-integration-discipline）。 */
    $geoVisibilityConfig = [
        'accountLoginUrl' => url('/login'),
        'accountUrl' => url('/#personal-settings'),
        'maxQuestions'    => max(1, (int) config('geo.visibility.max_questions', 10)),
    ];
    $cssVersion = file_exists(public_path('geo-visibility.css')) ? filemtime(public_path('geo-visibility.css')) : time();
    $jsVersion  = file_exists(public_path('geo-visibility.js'))  ? filemtime(public_path('geo-visibility.js'))  : time();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex,nofollow">
    <title>智能回答可见性 · 言及</title>
    <link rel="icon" href="/brand/yanji/v1/favicon.svg" type="image/svg+xml">
    <script nonce="{{ $cspNonce }}">
        window.GEO_VISIBILITY_CONFIG = @json($geoVisibilityConfig);
    </script>
    {{-- 设计令牌：必须排在页面自身样式表【前面】，页面里的覆盖才压得住。 --}}
    <link rel="stylesheet" href="/geo-tokens.css?v={{ file_exists(public_path('geo-tokens.css')) ? filemtime(public_path('geo-tokens.css')) : time() }}">
    <link rel="stylesheet" href="/geo-shell.css?v={{ filemtime(public_path('geo-shell.css')) }}">
    <link rel="stylesheet" href="/geo-components.css?v={{ filemtime(public_path('geo-components.css')) }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ filemtime(public_path('geo-fonts.css')) }}">
    <link rel="stylesheet" href="/geo-brand-pages.css?v={{ filemtime(public_path('geo-brand-pages.css')) }}">
    <link rel="stylesheet" href="/geo-visibility.css?v={{ $cssVersion }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ filemtime(public_path('geo-typography.css')) }}">
    <link rel="stylesheet" href="/geo-page-navigation.css?v={{ filemtime(public_path('geo-page-navigation.css')) }}">
</head>
<body class="geo-visibility geo-app geo-standalone">

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
    @include('partials.tenant-workspace-sidebar', ['currentPage' => 'visibility'])
    <div class="geo-page-content">

<nav class="section-nav" aria-label="监测视图">
    <a href="#sec-list" class="on">监测历史</a>
    <a href="#sec-new">发起监测</a>
    <a href="#sec-detail">运行详情</a>
</nav>

<main class="wrap">
    <div class="view-title">
        <h1>智能回答可见性</h1>
        <span class="vt-sub" id="gvSub">当前接入豆包，监测品牌在 AI 回答里的曝光与信源</span>
    </div>
    <p class="monitoring-scope">这里观察品牌是否出现在智能回答及其信源中；结果与站点的爬虫访问量分别统计。</p>

    <p class="form-msg" id="monitoringConfigNotice">未配置豆包监测，请先在<a href="{{ url('/workbench?view=aikeys') }}">设置中填写模型配置</a>。</p>
    <p class="form-msg" id="formMsg" role="status" aria-live="polite"></p>

    <!-- ============ 一、监测历史 ============ -->
    <section id="sec-list">
        <div class="panel">
            <div class="panel-head"><h2 tabindex="-1">监测历史</h2><div class="spacer"></div>
                <button class="btn ghost" id="refreshBtn">刷新</button>
            </div>
            <div class="panel-body">
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr>
                            <th>ID</th><th>问题</th><th>状态</th>
                            <th>搜索次数</th><th>Tokens</th><th>耗时</th><th>时间</th>
                        </tr></thead>
                        <tbody id="runListBody">
                            <tr><td colspan="7" class="state-td"><div class="skeleton" role="status" aria-label="加载中"><div class="sk w80"></div><div class="sk w60"></div><div class="sk w40"></div></div></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="pager" id="runListPager"></div>
            </div>
        </div>
    </section>

    <!-- ============ 二、发起监测 ============ -->
    <section id="sec-new">
        <div class="panel">
            <div class="panel-head"><h2 tabindex="-1">发起一轮监测</h2><span class="hint">单问题通常 1–4 分钟；最多 {{ max(1, (int) config('geo.visibility.max_questions', 10)) }} 个问题，每个问题独立执行</span></div>
            <div class="panel-body">
                <form id="newRunForm" class="run-form">
                    <label class="fld" for="projectId">
                        <span class="fld-label">项目（必填）</span>
                        <select name="project_id" id="projectId" required disabled aria-describedby="projectHelp">
                            <option value="">正在加载项目…</option>
                        </select>
                        <span class="fld-help" id="projectHelp">从工作台进入时会自动使用当前项目。</span>
                    </label>
                    <label class="fld" for="questions">
                        <span class="fld-label">问题（必填，每行一个；每个 ≤500 字，最多 {{ max(1, (int) config('geo.visibility.max_questions', 10)) }} 个）</span>
                        <textarea name="questions" id="questions" rows="4" placeholder="可点击下方项目问题加入，也可以自己填写；每行一个问题。" required aria-describedby="questionCount"></textarea>
                        <span class="fld-help cost-help" id="questionCount">已填写 0 个问题。提交后每个问题独立执行。</span>
                    </label>
                    <div class="question-suggestions" id="questionSuggestions" aria-live="polite">
                        <div class="suggestions-head">
                            <strong>可选监测问题</strong>
                            <span id="suggestionHint">正在读取当前项目已有的问题库，不调用模型。</span>
                        </div>
                        <div class="suggestion-list" id="suggestionList"></div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn primary" id="submitBtn" disabled>发起监测</button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- ============ 三、运行详情 ============ -->
    <section id="sec-detail">
        <div class="panel">
            <div class="panel-head"><h2 tabindex="-1">运行详情</h2><div class="spacer"></div>
                <button class="btn ghost" id="downloadMd" hidden>下载 Markdown</button>
                <button class="btn ghost" id="downloadHtml" hidden>下载 HTML</button>
            </div>
            <div class="panel-body" id="runDetailHost">
                <div class="state-tpl"><div class="st-icon">○</div><p>从「监测历史」选择一条运行查看详情。</p><a class="btn ghost" href="#sec-list">查看监测历史</a></div>
            </div>
        </div>
    </section>
</main>
    </div>
</div>

<script src="/geo-page-navigation.js?v={{ filemtime(public_path('geo-page-navigation.js')) }}" defer></script>
<script src="/geo-visibility.js?v={{ $jsVersion }}" defer></script>
</body>
</html>
