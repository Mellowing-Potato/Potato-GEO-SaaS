@php
    $geoEntryConfig = [
        'desktopMode' => \App\Support\PortableDesktopAccess::enabled(),
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
    $geoEntryCssVersion = file_exists(public_path('geo-entry.css')) ? \App\Support\PublicAssetVersion::for('geo-entry.css') : time();
    $geoBaseCssVersion = file_exists(public_path('geo-base.css')) ? \App\Support\PublicAssetVersion::for('geo-base.css') : time();
    $geoEntryAssetVersion = file_exists(public_path('geo-entry.js')) ? \App\Support\PublicAssetVersion::for('geo-entry.js') : time();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-brand-name="{{ config('brand.name') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>工作台 · {{ config('brand.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="/brand/yanji/v1/favicon.svg">
    <link rel="alternate icon" href="/brand/yanji/v1/favicon.ico">
    <link rel="apple-touch-icon" href="/brand/yanji/v1/apple-touch-icon.png">
    {{-- 设计令牌：必须排在页面自身样式表【前面】，页面里的覆盖才压得住。 --}}
    <link rel="stylesheet" href="/geo-tokens.css?v={{ file_exists(public_path('geo-tokens.css')) ? \App\Support\PublicAssetVersion::for('geo-tokens.css') : time() }}">
    <link rel="stylesheet" href="/geo-shell.css?v={{ \App\Support\PublicAssetVersion::for('geo-shell.css') }}">
    <link rel="stylesheet" href="/geo-components.css?v={{ \App\Support\PublicAssetVersion::for('geo-components.css') }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ \App\Support\PublicAssetVersion::for('geo-fonts.css') }}">
    {{-- 共用零件层。2026-09-03 内容库迁零件层时才第一次挂上来 —— 在此之前
         geo-base.css 全站只有 theme-package.blade.php 一个消费者，本页够不着。
         挂上是安全的：geo-base.css 里 136 条规则**全部**写在 .md-app 之下
         （已核：无一条裸选择器，两个 @media 里也一样），所以不带 md-app 的
         视图一条都吃不到。顺序必须夹在令牌与本页样式之间。 --}}
    <link rel="stylesheet" href="/geo-base.css?v={{ $geoBaseCssVersion }}">
    <link rel="stylesheet" href="/geo-entry.css?v={{ $geoEntryCssVersion }}">
    <link rel="stylesheet" href="/geo-workspace-ui.css?v={{ \App\Support\PublicAssetVersion::for('geo-workspace-ui.css') }}">
    <link rel="stylesheet" href="/geo-report-reader.css?v={{ \App\Support\PublicAssetVersion::for('geo-report-reader.css') }}">
    <link rel="stylesheet" href="/geo-project-home.css?v={{ \App\Support\PublicAssetVersion::for('geo-project-home.css') }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ \App\Support\PublicAssetVersion::for('geo-typography.css') }}">
</head>
<body class="geo-app">
    <main class="geo-app" data-state="connecting">
        <section class="state-panel" id="connectingState" aria-live="polite" hidden>
            <x-yanji-logo variant="compact" :label="config('brand.name')" class="state-logo" />
            <div class="loader" aria-hidden="true"></div>
            <h1>正在打开言及工作台</h1>
            <p>正在恢复当前项目，通常只需片刻。</p>
        </section>

        <section class="state-panel" id="signedOutState" hidden>
            <x-yanji-logo variant="compact" :label="config('brand.name')" class="state-logo" />
            <h1>登录言及工作台</h1>
            <p class="muted">登录后可管理项目、执行诊断并查看报告。</p>
            <div class="actions">
                <a class="button primary" id="loginLink" href="#">登录</a>
                <button class="button secondary" id="retryButton" type="button">重试</button>
            </div>
        </section>

        <section class="state-panel" id="errorState" hidden>
            <x-yanji-logo variant="compact" :label="config('brand.name')" class="state-logo" />
            <h1>工作台暂不可用</h1>
            <p class="muted" id="errorMessage">言及工作台暂时无法连接。</p>
            <div class="actions">
                <button class="button primary" id="errorRetryButton" type="button">重试</button>
                <a class="button secondary" id="errorLoginLink" href="#">重新登录</a>
            </div>
        </section>

        <section class="workspace" id="workspaceState" data-session-ready="false">
            @include('partials.workspace-topbar', ['mode' => 'spa'])

            <div class="workspace-shell">
                <aside class="workspace-sidebar" id="workspaceSidebar" data-nav-mode="work" inert>
                    <div class="workspace-sidebar-scroll">
                        <div class="project-nav-section" id="pcContext">
                            <button class="project-card" id="projectNavToggle" type="button" aria-label="展开项目列表" aria-expanded="false" aria-controls="projectNavChildren">
                                <span class="project-card-label">当前项目</span>
                                <strong id="projectCardName">请选择项目</strong>
                                <span id="projectCardStage">选择项目后查看阶段</span>
                                <span class="project-nav-chevron" aria-hidden="true">⌄</span>
                            </button>
                            <div class="project-nav-children" id="projectNavChildren" hidden>
                                <label class="project-nav-search-label" for="projectNavSearch">搜索项目</label>
                                <input id="projectNavSearch" type="search" placeholder="搜索项目名称" autocomplete="off">
                                <div id="projectNavList" aria-label="所有项目"></div>
                                <p id="projectNavMessage" class="project-nav-message" role="status"></p>
                                <button class="project-nav-action" id="projectCardAllProjects" type="button" data-shell-view="projects/list">全部项目</button>
                                <button class="project-nav-action" id="newProjectButton" type="button">＋ 新建项目</button>
                                <button class="project-nav-action" id="projectCardSettings" type="button">项目设置</button>
                            </div>
                        </div>
                    @include('partials.workspace-nav', ['mode' => 'spa'])
                    </div>
                    <div class="workspace-sidebar-bottom">
                        <button class="workspace-menu-link workspace-trash-link" id="globalTrashNav" type="button" hidden>
                            回收站 <span class="workspace-trash-count" id="globalTrashCount"></span>
                        </button>
                        <button class="workspace-menu-link" id="openSettingsButton" type="button">设置</button>
                    </div>
                </aside>

                <div class="workspace-main">
                <div class="workspace-load-notice" id="workspaceLoadNotice" role="status" aria-live="polite">
                    <span id="workspaceLoadMessage">正在验证登录状态…</span>
                    <button class="button secondary small" id="workspaceLoadRetry" type="button" hidden>重试</button>
                    <a class="button secondary small" id="workspaceLoadLogin" href="#" hidden>重新登录</a>
                </div>
                <div class="workspace-load-notice" id="workspaceViewLoadNotice" role="status" aria-live="polite" hidden>
                    <span id="workspaceViewLoadMessage"></span>
                    <button class="button secondary small" id="workspaceViewLoadRetry" type="button" hidden>重试</button>
                </div>
                {{-- id 供 geo-entry.js 在财务四视图下隐藏本栏（四视图自带页头，否则标题重复渲染两遍） --}}
                <div class="workspace-project-context" id="activeProjectContext" hidden><span>当前项目</span><strong id="pcName"></strong><small id="pcMeta"></small></div>
                <div class="workspace-view-titlebar" id="workspaceViewTitlebar" aria-live="polite">
                    <p class="eyebrow" id="workspaceViewEyebrow">项目工作</p>
                    <h2 id="workspaceViewTitle">项目管理</h2>
                    <p id="workspaceViewDescription">项目档案与阶段推进；切换项目用左上角的项目卡。</p>
                    <span class="spacer"></span>
                </div>

            {{-- 项目管理 · 2026-09-04 迁共用零件层（.md-*，geo-base.css）。
                 ⚠️ md-app 只加在本 section，**绝不能加到 <body>**：geo-base.css 的
                    .md-app 会一次性把 background / color / font-family / font-size /
                    line-height / :focus-visible 压到全部 21 个视图上。
                 ⚠️ 本 section 是**透明容器**（卡片是里面那两个 .md-panel），而 .md-app
                    自带页面底色 --md-bg-page。geo-entry.css 里 .project-workspace.md-app
                    双类显式收口成 transparent，别依赖加载顺序。
                 ⚠️ 旧规则一条没删（改前实测：.frow 还剩 25 处 / 3 个视图，.form-message
                    12 处 / 6 个视图，.panel 25 处）。本批只让项目管理页脱离，
                    其余视图各自那轮再迁。 --}}
            @include('workbench.panels.personal-settings')
            @unless(\App\Support\PortableDesktopAccess::enabled())
                @include('workbench.panels.account-security')
            @endunless
            @include('workbench.panels.project-workspace')

                {{-- 2026-09-04 走查：这里原来有一个面板头，写的是「分析诊断 / 诊断与路线」——
                     和上方 titlebar 由 geo-entry.js 按 meta 渲染出来的那两行**逐字相同**，
                     同一句话上下挨着说两遍（老板 09-04 原话：「项目旁边写着大 N 多号的项目管理」，
                     同一个毛病）。而且那个 h2 是 18px，比 titlebar 里真正的页标题（16px）还大。
                     ⇒ 删掉重复的那份，页标题只由 titlebar 出（它还多带一句说明文案）。
                     原来挂在这个头上的「刷新技能」按钮下移到了技能列表那一区 ——
                     它刷的就是那个列表，改前两者相距 1375px，要滚 1.5 屏才看得到效果。
                     ⚠️ 别把 "skill" 加进 geo-entry.js 的 selfTitledViews：那个名单是给
                        「自带页头」的视图用的，本页删头之后恰恰没有自己的页头了。 --}}
                @include('workbench.panels.skill')

                {{-- 2026-09-05 批 B 第 5 步（走查：同一句话说三遍）。删了两块：
                     ① 面板头「分析诊断 / 历史报告」—— 和上方 titlebar 按 meta 渲染出来的
                        那两行逐字相同，上下挨着说两遍。这是 2026-09-04 在诊断与路线页
                        处理过的同一个毛病，同样的做法：页标题只由 titlebar 出
                        （它还多带一句说明）。⚠️ 同样**别把 "asset" 加进 selfTitledViews**：
                        那个名单是给自带页头的视图用的，本页删头之后恰恰没有自己的页头了。
                     ② .asset-guide 那条说明 —— 和 titlebar 的 description 是同一句话的
                        两种写法（「归档综合诊断、执行路线、监测报告等产物…可发布文章在
                        「内容 → 文章」」）。
                     「刷新内容」跟着下移到工具条：它刷的就是下面那个列表。 --}}
                @include('workbench.panels.asset')

                {{-- 独立结果页 · 2026-09-05（执行入口 / 独立结果页重构 · 批 B）
                     路由是 #result/<报告编号>，编号取历史报告那套（/api/v1/projects/{id}/articles）——
                     综合诊断和技能报告归档后都是一条 article，两类结果共用这一个结果页。

                     ⚠️ 侧栏没有这一项、也不该有：它是「从某份报告点进来」的详情页，不是并列的一级菜单。
                        所以停在这一页时侧栏没有任何一项高亮，这是有意的，不是漏了高亮。
                     ⚠️ 这一页在 selfTitledViews 名单里（共享 titlebar 会隐藏），页头就是下面这个
                        .panel-heading。改动这里的结构前先看那份名单。
                     ⚠️ 正文容器用的是 .result-view，和历史报告页 #assetResult 同一个类 ——
                        故意的：报告正文的排版规则只有一套，两处渲染出来必须长得一样。
                        执行入口那边原本给报告正文加过一层「收到一屏 + 展开全文」的封顶，
                        正文搬到这一页之后那套已整体删掉：这里就是读全文的地方，不该再收。 --}}
                @include('workbench.panels.result')

                {{-- 内容库 · 2026-09-03 迁共用零件层（.md-*，geo-base.css）。
                     ⚠️ md-app 只加在本 section，**绝不能加到 <body>**：geo-base.css 的
                        .md-app 会一次性把 background / color / font-family / font-size /
                        line-height / :focus-visible 描边压到全部 21 个视图上。
                     ⚠️ .panel 与 .md-app 都声明了背景，两者同为单类选择器，靠加载顺序
                        决胜（geo-entry.css 在后 → .panel 赢）。本页 CSS 里的
                        .content-library-panel.md-app 用双类显式收口，别依赖顺序。
                     ⚠️ 旧 .cl-* 规则一条没删：cl-tbl 等被素材/自动化/知识库/线索共 5 个
                        视图 7 张表借用，本批只让内容库脱离，其余各自那轮再迁。 --}}
                @include('workbench.panels.content-library')

                @include('workbench.panels.materials')

                @include('workbench.panels.automation')

                {{-- 文章详情抽屉 · 迁零件层 md-scrim + md-sheet（主题包页之后的第二个消费者）。
                     ⚠️ 结构从「遮罩与抽屉两个平级兄弟」改成「遮罩包住抽屉」——零件层的
                        .md-scrim 是 position:fixed 的 flex 容器，抽屉必须是它的子元素。
                     ⚠️ 因此**开关挂在 #clDrawerMask（遮罩）上**，不再挂 #clDrawer。
                        两个 id 都保留，但含义变了：Mask=遮罩层，Drawer=面板本身。
                     ⚠️ 本 section 之外还要一层 md-app：遮罩是 <body> 直系的定位层，不能塞进
                        #contentLibraryPanel（那个 section 切视图时会被 hidden 掉），
                        而 geo-base.css 全部规则都在 .md-app 之下。所以全页有两处 md-app。
                     🔴 md-app 必须是**外面包一层**，不能跟 md-scrim 写在同一个元素上。
                        geo-base.css 里是 `.md-app .md-scrim`（后代选择器），同元素不匹配 ——
                        实测那样写出来的遮罩是 position:static、没有底色、抽屉直接摊在页面下方。
                     ⚠️ 遮罩关闭只认 e.target 就是遮罩本身；别给它挂 data-* 动作属性，
                        closest() 会从面板内部一路找上来，点面板空白就把抽屉关了。 --}}
                <div class="md-app">
                <div class="md-scrim" id="clDrawerMask" hidden>
                    <aside class="md-sheet" role="dialog" aria-modal="true" aria-labelledby="clDrawerTitle" id="clDrawer">
                        <div class="md-sheet-head">
                            <div>
                                <h2 class="md-sheet-title" id="clDrawerTitle">文章标题</h2>
                                <div class="md-sheet-sub" id="clDrawerSub"></div>
                            </div>
                            <button class="md-iconbtn" id="clDrawerClose" aria-label="关闭" type="button">✕</button>
                        </div>
                        <div class="md-sheet-body" id="clDrawerBody"></div>
                        <div class="md-sheet-foot" id="clDrawerFoot"></div>
                    </aside>
                </div>
                </div>

                {{-- EXEC-10B 阶段抽屉：md-app 单独包裹，开关挂遮罩，关闭只认遮罩本身。 --}}
                <div class="md-app">
                <div class="md-scrim" id="projectStageDrawerMask" hidden>
                    <aside class="md-sheet" role="dialog" aria-modal="true" aria-labelledby="projectStageDrawerTitle" id="projectStageDrawer">
                        <div class="md-sheet-head">
                            <div>
                                <h2 class="md-sheet-title" id="projectStageDrawerTitle">项目阶段</h2>
                                <div class="md-sheet-sub" id="projectStageDrawerSub"></div>
                            </div>
                            <button class="md-iconbtn" id="projectStageDrawerClose" aria-label="关闭" type="button">✕</button>
                        </div>
                        <div class="md-sheet-body" id="projectStageDrawerBody"></div>
                        <div class="md-sheet-foot" id="projectStageDrawerFoot">
                            <p class="muted" id="stageActionMessage" role="status"></p>
                            <div id="projectStageDrawerActions"></div>
                            <select id="rollbackTargetSelect" hidden aria-hidden="true" tabindex="-1"><option value="0">0</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option></select>
                        </div>
                    </aside>
                </div>
                </div>

                @include('workbench.panels.team')

                @include('workbench.panels.knowledge')

                @include('workbench.panels.sensitive-words')

                @include('workbench.panels.skill-prompts')

                {{-- URL 文章导入（2026-09-21 工作台功能补齐，计划 §5）。
                     后端 /api/v1/url-imports 早已存在（异步抓取 → 转 Markdown → 生成草稿），
                     此前只在已关闭的 v2 原型里。这里补正式入口：
                       提交（url + project_id）→ 列表（状态/标题/产物文章/失败原因）→ 详情即列表行内展开。
                     表单字段名与后端校验一致（url / project_id），项目从当前项目取。 --}}
                @include('workbench.panels.url-imports')

                {{-- 文章分类管理（2026-09-21 工作台功能补齐，计划 §5）。
                     后端 /api/v1/categories 早已存在，此前没有正式管理界面。
                     字段与后端校验一致：name（必填 ≤120）、slug（可选，字母数字开头的标识）、
                     description（可选 ≤2000）、sort_order（可选整数）。文章数量用 with_counts 取。 --}}
                @include('workbench.panels.categories')

                @include('workbench.panels.analytics')

                @include('workbench.panels.publishing')


                @if (config('geo.lead_form_enabled', false))
                {{-- 单 8：线索表单管理台。执行书第 5 步「并进现有工作台，不新开顶级页面」。
                     三个页签共用一个视图：线索（提交记录）/ 表单（CRUD）/ 转化统计。
                     权限门在 API 层（can:lead:manage = admin + editor），前端只按角色隐藏入口，
                     member 直接调接口照样 403 —— 前端隐藏不是权限。 --}}
                @include('workbench.panels.leads')
                @endif


                {{-- 订阅套餐：全程站内完成，不跳账户中心。购买流程复用充值页那套二维码 + 轮询。 --}}




                @include('workbench.panels.usage')
                @include('workbench.panels.ai-keys')
                @include('workbench.panels.platform')
                </div>
            </div>
        </section>
    </main>

    <div id="projectNameMenu" class="project-name-menu md-app" popover="manual" role="menu" aria-label="项目操作" hidden><button type="button" role="menuitem" id="projectNameMenuRename">重命名</button></div>
    <dialog id="projectRenameDialog" class="project-rename-dialog md-app" aria-labelledby="projectRenameTitle">
        <form id="projectRenameForm">
            <h2 id="projectRenameTitle">重命名项目</h2>
            <div class="md-field"><label class="md-field-label" for="projectRenameInput">项目名称</label><input class="md-input" id="projectRenameInput" required maxlength="255" autocomplete="off"></div>
            <p id="projectRenameMessage" class="project-rename-message" role="status" aria-live="polite"></p>
            <div class="project-rename-actions"><button type="button" class="md-btn md-btn--secondary" id="projectRenameCancel">取消</button><button type="submit" class="md-btn md-btn--primary" id="projectRenameSave">保存</button></div>
        </form>
    </dialog>
    <script nonce="{{ $cspNonce }}">
        window.GEO_ENTRY_CONFIG = @json($geoEntryConfig);
    </script>
    <script src="/geo-report-reader.js?v={{ \App\Support\PublicAssetVersion::for('geo-report-reader.js') }}" defer></script>
    <script src="/geo-page-navigation.js?v={{ \App\Support\PublicAssetVersion::for('geo-page-navigation.js') }}" defer></script>
    <script src="/geo-entry.js?v={{ $geoEntryAssetVersion }}" defer></script>
    <script src="/geo-report-editor.js?v={{ \App\Support\PublicAssetVersion::for('geo-report-editor.js') }}" defer></script>
    {{-- 计划 §7：geo-entry.js 拆出来的各块。
         ⚠️ 顺序两件事（2026-09-21 第九刀补）：
         1. 全部必须排在 geo-entry.js 之后 —— 它们读 window.GEO。都是 defer，按顺序执行完，
            bootstrap() 取加载器时一定已注册。
         2. **前三块有固定顺序：projects → reports → skills**：
            项目块提供 openWorkspaceView / openResultPage（主文件与所有拆出块都要用），排最前；
            报告块提供 renderExecutionGlance / skillExecutionText / copySkillExecution 等，
            技能块在**自己加载时**就解构它们；技能块又在末尾把 articleDisplayTitle /
            openContentTools / openExistingArticles 挂回 GEO，而 geo-entry-content-library.js
            在**自己加载时**解构这三个名字（解构发生在加载期，不是调用期）。
            以后再有"A 块加载时解构 B 块提供的名字"的依赖，就把提供方排到前面来。 --}}
    <script src="/geo-entry-projects.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-projects.js') }}" defer></script>
    <script src="/geo-entry-project-home.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-project-home.js') }}" defer></script>
    <script src="/geo-entry-reports.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-reports.js') }}" defer></script>
    <script src="/geo-entry-skills.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-skills.js') }}" defer></script>
    <script src="/geo-entry-management.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-management.js') }}" defer></script>
    <script src="/geo-entry-magpie.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-magpie.js') }}" defer></script>
    <script src="/geo-entry-onboarding.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-onboarding.js') }}" defer></script>
    <script src="/geo-entry-usage.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-usage.js') }}" defer></script>
    <script src="/geo-entry-enterprise-knowledge.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-enterprise-knowledge.js') }}" defer></script>
    <script src="/geo-entry-knowledge-base.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-knowledge-base.js') }}" defer></script>
    <script src="/geo-entry-content-library.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-content-library.js') }}" defer></script>
    <script src="/geo-entry-publishing.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-publishing.js') }}" defer></script>
    <script src="/geo-entry-leads.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-leads.js') }}" defer></script>
    <script src="/geo-entry-lead-export.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-lead-export.js') }}" defer></script>
<script src="/geo-entry-platform.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-platform.js') }}" defer></script>
{{-- 计划 §9.5：账户注销申请（申请 + 15 天冷静期 + 期内登录即恢复）。 --}}
<script src="/geo-entry-account.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-account.js') }}" defer></script>
{{-- 2026-09-22 前后端对齐：工作区改名 + 我的权限（PATCH /api/v1/workspace、GET /api/v1/me/permissions）。 --}}
<script src="/geo-entry-workspace-settings.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-workspace-settings.js') }}" defer></script><script src="/geo-entry-mobile-menu.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-mobile-menu.js') }}" defer></script>
{{-- 2026-09-22 前后端对齐：URL 导入单条详情（GET /api/v1/url-imports/{id}）。 --}}
<script src="/geo-entry-url-import-detail.js?v={{ \App\Support\PublicAssetVersion::for('geo-entry-url-import-detail.js') }}" defer></script>
</body>
</html>
