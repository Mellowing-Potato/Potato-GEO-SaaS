@php
    /* 主题包生成模块 · 租户侧看板（单 10；2026-09-03 按 B 稿重做为主从两栏）
       交接三件套：design-specs/designs/geo-theme-package/{主题包生成 重做.html, SPEC.md}
                 ＋ docs/主题包页重做-工兵执行书-2026-09-03.md

       样式挂载三件套（顺序不能变，见 geo-base.css 头部）：
         geo-tokens.css（只有自定义属性）→ geo-base.css（共用零件 .md-*）→ 本页样式（.tp-*）
       ＋ <body class="md-app geo-app">：geo-base.css 的**全部**规则都在 .md-app 之下，
         只挂 <link> 不加这个 class 的话，零件层一条都不生效。

       红线（design-specs/prototypes/前端接后端-防走偏与纠偏规程）：
         - 仅消费真实端点 /api/v1/theme-packages/* 与 /theme-preview/*；接口失败显式报错，绝不回退假数据。
         - 空态要真：无数据即显示「暂无」，不塞假数。
       进度红线（执行书 §〇-1）：阶段名 / 状态名一律用服务端 progressSnapshot() 返回的字段，
         前端不许出现阶段中文字面量，也不许 Math.max(pct, 8) 这类补丁。
       文案红线（执行书 §〇-3）：本功能不复制/克隆站点版式，只读取参考站点的配色与字体
         生成一套可下载的主题包。界面上出现「复制/克隆站点」= 虚假承诺，验收打回。 */
    $tpConfig = [
        'accountLoginUrl' => url('/login'),
        'accountUrl' => url('/#personal-settings'),

        /* 11 个状态的中文一律取自服务端 lang 文件，前端不另起一张表。
           列表接口 GET /theme-packages 不返回 progress（只有 show / status 有），
           所以左栏那些不轮询的终态行没有 progress.status_label 可用 ——
           在前端硬编码一张中文表就是「第二份权威」，以后加英文时界面一半不跟着变。
           这里把服务端那张表整个交给前端，来源仍是 resources/lang/en/theme_package.php。 */
        'statusLabels' => (array) __('theme_package.status'),

        /* 安全预览说明（缺陷 11）：lang 里一直有这句，界面上从没显示过。
           不显示的后果是用户以为预览就是最终成品，期望和产出对不上。 */
        'safePreviewNotice' => __('theme_package.safe_preview.notice'),

        /* 预览的三种页型。这里的 label 是**页型名**，不是阶段名也不是状态名，
           所以不走 lang（lang 的 preview.home 是「首页预览」，是预览页自己的标题，
           放进分段控件里会变成「首页预览 / 列表页预览 / 详情页预览」的重复）。
           key 必须与 web 路由 /theme-preview/{id}/{page} 的三个取值逐字一致。 */
        'previewPages' => [
            ['key' => 'home',     'label' => '首页'],
            ['key' => 'category', 'label' => '分类页'],
            ['key' => 'article',  'label' => '文章页'],
        ],

        /* ⚠️ value 必须与后端白名单逐字一致（控制器 store 的 in: 规则 +
             SiteThemeReplicationService::normalizeStylePreference）。
             对不上的后果是**静默的**：Service 认不出就默默回落成 content_site，
             用户选了「品牌站」拿到的是内容站，界面上什么都不说。 */
        'styleOptions' => [
            ['value' => 'content_site', 'label' => '内容站', 'hint' => '正文优先、信息密度高，适合博客与知识库'],
            ['value' => 'brand_site',   'label' => '品牌站', 'hint' => '留白大、视觉重，适合产品与官网首页'],
            ['value' => 'news_site',    'label' => '资讯站', 'hint' => '列表密集、时间线明显，适合新闻与快讯'],
        ],
    ];
    $cssVersion  = file_exists(public_path('geo-theme-package.css')) ? \App\Support\PublicAssetVersion::for('geo-theme-package.css') : time();
    $jsVersion   = file_exists(public_path('geo-theme-package.js'))  ? \App\Support\PublicAssetVersion::for('geo-theme-package.js')  : time();
    $tokVersion  = file_exists(public_path('geo-tokens.css'))        ? \App\Support\PublicAssetVersion::for('geo-tokens.css')        : time();
    $baseVersion = file_exists(public_path('geo-base.css'))          ? \App\Support\PublicAssetVersion::for('geo-base.css')          : time();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex,nofollow">
    <title>主题包生成 · 言及</title>
    <link rel="icon" href="/brand/yanji/v1/favicon.svg" type="image/svg+xml">
    <script nonce="{{ $cspNonce }}">
        window.GEO_THEME_PACKAGE_CONFIG = @json($tpConfig);
    </script>
    {{-- 令牌 → 零件层 → 本页样式，顺序不能变：
         排在令牌前取不到 var()，排在本页样式后压不住本页覆盖。 --}}
    <link rel="stylesheet" href="/geo-tokens.css?v={{ $tokVersion }}">
    <link rel="stylesheet" href="/geo-shell.css?v={{ \App\Support\PublicAssetVersion::for('geo-shell.css') }}">
    <link rel="stylesheet" href="/geo-components.css?v={{ \App\Support\PublicAssetVersion::for('geo-components.css') }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ \App\Support\PublicAssetVersion::for('geo-fonts.css') }}">
    <link rel="stylesheet" href="/geo-brand-pages.css?v={{ \App\Support\PublicAssetVersion::for('geo-brand-pages.css') }}">
    <link rel="stylesheet" href="/geo-base.css?v={{ $baseVersion }}">
    <link rel="stylesheet" href="/geo-theme-package.css?v={{ $cssVersion }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ \App\Support\PublicAssetVersion::for('geo-typography.css') }}">
    <link rel="stylesheet" href="/geo-page-navigation.css?v={{ \App\Support\PublicAssetVersion::for('geo-page-navigation.css') }}">
</head>
<body class="tp md-app geo-app geo-standalone">

{{-- ⚠️ 权限守卫这一块的 DOM 与 id 一个都不要改名：geo-theme-package.js 的
     showGuard() 是按 id 找它们的（guardState / guardIcon / guardGlyph /
     guardTitle / guardText / guardLink）。样式可以改，id 不能动。 --}}
<div class="ops-state" id="guardState" hidden>
    <div class="ops-state-box">
        <div class="ob-spinner" id="guardIcon" aria-hidden="true"></div>
        <div class="ob-icon" id="guardGlyph" hidden>!</div>
        <h2 id="guardTitle">连接中…</h2>
        <p id="guardText">正在校验主题包生成面板访问权限</p>
        <a class="md-btn" id="guardLink" href="#" hidden>登录言及</a>
    </div>
</div>

<div class="geo-page-shell">
    @include('partials.workspace-topbar', ['mode' => 'page'])
    @include('partials.tenant-workspace-sidebar', ['currentPage' => 'theme-package'])
    <div class="geo-page-content">

<div class="geo-page-heading view-title">
    <h1>主题包生成</h1>
    <p class="tp-tagline">读取参考站点的配色与字体，生成一套可下载的主题包</p>
</div>

<div class="tp-split">
    {{-- ── 左栏：这条链现在在哪（进行中置顶、带活的进度条）───────────── --}}
    <aside class="tp-rail" aria-label="主题包列表">
        <div class="tp-rail-head">
            <button type="button" class="md-btn md-btn--primary md-btn--full" id="tpNewBtn">生成新主题包</button>
            <input type="search" class="md-input md-input--search" id="tpSearch"
                   placeholder="搜名称或主题标识" aria-label="搜索主题包" autocomplete="off">
        </div>
        <div class="tp-rail-list" id="tpRailList">
            <div class="md-skeleton" role="status" aria-label="加载中">
                <div class="md-sk md-sk--w80"></div>
                <div class="md-sk md-sk--w60"></div>
                <div class="md-sk md-sk--w40"></div>
            </div>
        </div>
    </aside>

    {{-- ── 右栏：正在做什么（新建 / 选中那一个的全过程）───────────────── --}}
    <section class="tp-main" id="tpMain"></section>
</div>
    </div>
</div>

{{-- 抽屉与对话框挂这里；toast 挂下面那个。都由 JS 填充。 --}}
<div id="tpOverlay"></div>
<div id="tpToastHost" aria-live="polite"></div>

<script src="/geo-page-navigation.js?v={{ \App\Support\PublicAssetVersion::for('geo-page-navigation.js') }}" defer></script>
<script src="/geo-theme-package.js?v={{ $jsVersion }}" defer></script>
</body>
</html>
