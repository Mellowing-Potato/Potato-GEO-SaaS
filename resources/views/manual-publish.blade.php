@php
    /* 手动发布模块 · 租户侧净新增看板（单 7）
       同构 visibility：blade 空壳 + public/geo-manual-publish.js（vanilla fetch）+ config 门控。
       红线（design-specs/prototypes/前端接后端-防走偏与纠偏规程）：
         - 仅消费真实端点 /api/v1/manual-publications/*；接口失败显式报错，绝不回退假数据。
         - 空态要真：无数据即显示「暂无」，不塞假数。 */
    $mpConfig = [
        'accountLoginUrl' => url('/login'),
        'accountUrl' => url('/#personal-settings'),
        'zhihuDraftSyncEnabled' => (bool) config('geo.zhihu_draft_sync_enabled', false),
        'publishingHelperVersion' => app(\App\Services\Publishing\BrowserExtensionPackage::class)->version(),
        'publishingHelperOrigin' => app(\App\Services\Publishing\BrowserExtensionPackage::class)->instance()['origin'],
    ];
    $cssVersion = file_exists(public_path('geo-manual-publish.css')) ? \App\Support\PublicAssetVersion::for('geo-manual-publish.css') : time();
    $jsVersion  = file_exists(public_path('geo-manual-publish.js'))  ? \App\Support\PublicAssetVersion::for('geo-manual-publish.js')  : time();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex,nofollow">
    <title>手动发布 · 言及</title>
    <link rel="icon" href="/brand/yanji/v1/favicon.svg" type="image/svg+xml">
    <script nonce="{{ $cspNonce }}">
        window.GEO_MANUAL_PUBLISH_CONFIG = @json($mpConfig);
    </script>
    {{-- 设计令牌：必须排在页面自身样式表【前面】，页面里的覆盖才压得住。 --}}
    <link rel="stylesheet" href="/geo-tokens.css?v={{ file_exists(public_path('geo-tokens.css')) ? \App\Support\PublicAssetVersion::for('geo-tokens.css') : time() }}">
    <link rel="stylesheet" href="/geo-shell.css?v={{ \App\Support\PublicAssetVersion::for('geo-shell.css') }}">
    <link rel="stylesheet" href="/geo-components.css?v={{ \App\Support\PublicAssetVersion::for('geo-components.css') }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ \App\Support\PublicAssetVersion::for('geo-fonts.css') }}">
    <link rel="stylesheet" href="/geo-brand-pages.css?v={{ \App\Support\PublicAssetVersion::for('geo-brand-pages.css') }}">
    <link rel="stylesheet" href="/geo-manual-publish.css?v={{ $cssVersion }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ \App\Support\PublicAssetVersion::for('geo-typography.css') }}">
    <link rel="stylesheet" href="/geo-page-navigation.css?v={{ \App\Support\PublicAssetVersion::for('geo-page-navigation.css') }}">
</head>
<body class="mp geo-app geo-standalone">

<div class="ops-state" id="guardState" hidden>
    <div class="ops-state-box">
        <div class="ob-spinner" id="guardIcon" aria-hidden="true"></div>
        <div class="ob-icon" id="guardGlyph" hidden>!</div>
        <h2 id="guardTitle">连接中…</h2>
        <p id="guardText">正在校验手动发布面板访问权限</p>
        <a class="btn" id="guardLink" href="#" hidden>登录言及</a>
    </div>
</div>

<div class="geo-page-shell">
    @include('partials.workspace-topbar', ['mode' => 'page'])
    @include('partials.tenant-workspace-sidebar', ['currentPage' => 'manual-publish'])
    <div class="geo-page-content">

<nav class="section-nav" aria-label="发布管理视图">
    <a href="#sec-list" class="on">发布单</a>
    <a href="#sec-new">新建发布单</a>
    <a href="#sec-detail">详情 / 流转</a>
    <a href="#sec-settings">人设与账号</a>
</nav>

<main class="wrap">
    <div class="view-title">
        <h1>手动发布</h1>
        <span class="vt-sub" id="mpSub">管理人去外部平台手动发帖的流程与证据</span>
        <a class="btn ghost mini" id="backToProjectBindings" href="/#publishing" hidden>返回项目渠道绑定</a>
        <details class="mp-create">
            <summary>浏览器发布助手与使用说明</summary>
            <p class="hint">平台账号属于你自己的工作区。知乎和微博文章支持通过助手保存草稿，正式发布由你在目标平台确认，完成后回填公开链接。</p>
            <button class="btn mini" type="button" id="downloadPublishingHelper">下载 Chrome 草稿助手</button>
            <span class="hint">当前最新版 {{ $mpConfig['publishingHelperVersion'] }}；旧版请更新并刷新此页。</span>
            <span class="hint">此安装包只用于实例 {{ $mpConfig['publishingHelperOrigin'] }}。不同实例请使用不同安装目录。</span>
            <span class="form-msg" id="publishingHelperMessage" role="status"></span>
            <ol class="hint"><li>下载并解压安装包，在 Chrome 扩展管理页开启开发者模式，选择“加载已解压的扩展程序”；更新时选择新版解压目录并重新加载。</li><li>同一 Chrome 用户资料登录言及和目标平台，在账号设置中填写准确的个人主页链接；微博请使用 https://weibo.com/u/数字账号ID。</li><li>将文章发布单推进到“进行中”，点击对应平台的草稿同步按钮。微博会新建草稿并重新打开核对；已有编号只读核对，不重复新建。</li></ol>
            <p class="hint">当前安装包由言及分发，尚未在 Chrome 应用商店上架。助手读取言及发布单、知乎文章编辑器及微博头条文章编辑器，不读取 Cookie 或将登录凭据上传。知乎为纯文本；微博支持正文排版、链接及最多 20 张 HTTPS 图片转存，封面请在微博补充。连接中断时先核对已有草稿，系统不会自动重复发送。</p>
        </details>
    </div>

    <section id="sec-list">
        <div class="panel">
            <div class="panel-head"><h2 tabindex="-1">发布单</h2><div class="spacer"></div>
                <span class="stat" id="statTotal">—</span>
                <button class="btn ghost" id="refreshBtn">刷新</button>
            </div>
            <div class="panel-body">
                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead><tr>
                            <th>ID</th><th>类型</th><th>平台</th><th>状态</th>
                            <th>执行人</th><th>风险</th><th>重复</th><th>时间</th><th>操作</th>
                        </tr></thead>
                        <tbody id="pubListBody">
                            <tr><td colspan="9" class="state-td"><div class="skeleton" role="status" aria-label="加载中"><div class="sk w80"></div><div class="sk w60"></div><div class="sk w40"></div></div></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section id="sec-new">
        <div class="panel">
            <div class="panel-head"><h2 tabindex="-1">新建发布单</h2><span class="hint">文章 ≤ 20000 字，评论 ≤ 2000 字；文章须选关联文章</span></div>
            <div class="panel-body">
                <form id="newPubForm" class="run-form">
                    <label class="fld" for="personaSel">
                        <span class="fld-label">人设</span>
                        <select name="persona_id" id="personaSel" required><option value="">选择人设…</option></select>
                    </label>
                    <label class="fld" for="accountSel">
                        <span class="fld-label">账号</span>
                        <select name="account_id" id="accountSel"><option value="">选择账号…</option></select>
                    </label>
                    <label class="fld" for="platformSel">
                        <span class="fld-label">平台</span>
                        <select name="platform" id="platformSel" required></select>
                    </label>
                    <label class="fld" id="customPlatformFld" hidden for="customPlatform">
                        <span class="fld-label">自定义平台名（选「自定义」时必填）</span>
                        <input type="text" name="custom_platform" id="customPlatform" maxlength="64" placeholder="平台名称，≤64 字">
                    </label>
                    <label class="fld" for="typeSel">
                        <span class="fld-label">类型</span>
                        <select name="type" id="typeSel" required>
                            <option value="post">文章</option>
                            <option value="comment">评论</option>
                        </select>
                    </label>
                    <label class="fld fld-wide" id="articleFld" hidden for="articleSel">
                        <span class="fld-label">关联文章（选「文章」类型时必选）</span>
                        <select name="article_id" id="articleSel"><option value="">选择已审核文章…</option></select>
                    </label>
                    <div class="fld fld-wide" id="articleSourceHost" hidden></div>
                    <label class="fld" for="assignedUserId">
                        <span class="fld-label">指派给</span>
                        {{-- 缺陷 C（派单二 2026-08-31）：原为手填数字用户 ID 的 input。
                             客户得先知道同事的数字 ID 才能指派，而这个数字界面上任何地方都不显示。
                             改成下拉，选项由 public/geo-manual-publish.js 的 fillAssignee() 填充：
                             admin 拿本租户成员（GET /api/v1/team/），editor 只有「我自己」一项。
                             留空时下拉是禁用的（成员列表没拉到就不让选，不许静默降级回数字框）。 --}}
                        <select name="assigned_user_id" id="assignedUserId" disabled></select>
                    </label>
                    <label class="fld fld-wide" for="content">
                        <span class="fld-label">内容</span>
                        <textarea name="content" id="content" aria-describedby="contentCounter" rows="5" placeholder="要发布的内容" required></textarea>
                        <span class="fld-counter" id="contentCounter">0 / 20000</span>
                    </label>
                    <div id="commentFields">
                    <label class="fld fld-wide" for="targetUrl">
                        <span class="fld-label">目标链接（评论必填）</span>
                        <input type="url" name="target_url" id="targetUrl" placeholder="https://…">
                    </label>
                    <label class="fld fld-wide" for="targetContext">
                        <span class="fld-label">目标上下文（评论必填）</span>
                        <input type="text" name="target_context" id="targetContext" placeholder="被评论内容的上下文">
                    </label>
                    </div>
                    <label class="fld fld-wide" for="scheduledAt">
                        <span class="fld-label">计划时间（可选）</span>
                        <input type="datetime-local" name="scheduled_at" id="scheduledAt">
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn primary" id="submitBtn">创建</button>
                        <span class="form-msg" id="formMsg"></span>
                    </div>
                </form>
                <div class="risk-box" id="riskBox" hidden></div>
            </div>
        </div>
    </section>

    <section id="sec-detail">
        <div class="panel">
            <div class="panel-head"><h2 tabindex="-1">详情 / 流转</h2></div>
            <div class="panel-body" id="detailHost">
                <div class="state-tpl"><div class="st-icon">○</div><p>从「发布单」点选一行查看详情与流转记录。</p></div>
            </div>
        </div>
    </section>

    <section id="sec-settings">
        <div class="panel">
            <div class="panel-head"><h2 tabindex="-1">人设与账号</h2><span class="hint">建单前先建人设；账号可选，未配置时也可保存草稿</span></div>
            <div class="panel-body">
                <h3>人设</h3>
                <span class="form-msg" id="personaListMsg"></span>
                <div class="tbl-wrap"><table class="tbl">
                    <thead><tr><th>名称</th><th>语气</th><th>领域</th><th>披露声明</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody id="personaListBody"><tr><td colspan="6" class="state-td">加载中…</td></tr></tbody>
                </table></div>
                <details class="mp-create"><summary>新建人设</summary>
<form id="personaForm" class="run-form">
                    <label class="fld" for="personaName">
                        <span class="fld-label">新人设名称（必填）</span>
                        <input type="text" id="personaName" required maxlength="120" placeholder="例如：测评师小清">
                    </label>
                    <label class="fld" for="personaTone">
                        <span class="fld-label">语气（可选）</span>
                        <input type="text" id="personaTone" maxlength="120" placeholder="例如：专业克制">
                    </label>
                    <label class="fld" for="personaDomain">
                        <span class="fld-label">领域（可选）</span>
                        <input type="text" id="personaDomain" maxlength="120" placeholder="例如：消费测评">
                    </label>
                    <label class="fld fld-wide" for="personaDisclosure">
                        <span class="fld-label">披露声明（可选）</span>
                        <input type="text" id="personaDisclosure" placeholder="例如：本文含商业合作">
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn primary mini">新建人设</button>
                        <span class="form-msg" id="personaMsg"></span>
                    </div>
                </form>
</details>
                <h3>账号</h3>
                <span class="form-msg" id="accountListMsg"></span>
                <div class="tbl-wrap"><table class="tbl">
                    <thead><tr><th>账号</th><th>平台</th><th>人设</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody id="accountListBody"><tr><td colspan="5" class="state-td">加载中…</td></tr></tbody>
                </table></div>
                <div id="wechatConnectionHost"></div>
                <details class="mp-create"><summary>新建账号</summary>
<form id="accountForm" class="run-form">
                    <label class="fld" for="accountPersonaSel">
                        <span class="fld-label">所属人设（必填）</span>
                        <select id="accountPersonaSel" required><option value="">选择人设…</option></select>
                    </label>
                    <label class="fld" for="accountPlatformSel">
                        <span class="fld-label">平台（必填）</span>
                        <select id="accountPlatformSel" required></select>
                    </label>
                    <label class="fld" id="accountCustomPlatformFld" hidden for="accountCustomPlatform">
                        <span class="fld-label">自定义平台名（选「自定义」时必填）</span>
                        <input type="text" id="accountCustomPlatform" maxlength="64">
                    </label>
                    <label class="fld" for="accountName">
                        <span class="fld-label">账号名（必填）</span>
                        <input type="text" id="accountName" required maxlength="120" placeholder="外部平台上的账号名">
                    </label>
                    <label class="fld fld-wide" for="accountProfileUrl">
                        <span class="fld-label">主页链接（可选）</span>
                        <input type="url" id="accountProfileUrl" placeholder="https://…">
                    </label>
                    <label class="fld fld-wide" for="accountNotes">
                        <span class="fld-label">备注（可选）</span>
                        <input type="text" id="accountNotes">
                    </label>
                    <div class="form-actions">
                        <button type="submit" class="btn primary mini">新建账号</button>
                        <button type="button" class="btn ghost mini" id="accountEditCancel" hidden>取消编辑</button>
                        <span class="form-msg" id="accountMsg"></span>
                    </div>
                </form>
</details>
            </div>
        </div>
    </section>
</main>
    </div>
</div>

<script src="/geo-page-navigation.js?v={{ \App\Support\PublicAssetVersion::for('geo-page-navigation.js') }}" defer></script>
<script src="/geo-weibo-drafts.js?v={{ \App\Support\PublicAssetVersion::for('geo-weibo-drafts.js') }}" defer></script>
<script src="/geo-publication-drafts.js?v={{ \App\Support\PublicAssetVersion::for('geo-publication-drafts.js') }}" defer></script>
<script src="/geo-wechat-drafts.js?v={{ \App\Support\PublicAssetVersion::for('geo-wechat-drafts.js') }}" defer></script>
<script src="/geo-publication-article-source.js?v={{ \App\Support\PublicAssetVersion::for('geo-publication-article-source.js') }}" defer></script>
<script src="/geo-manual-publish.js?v={{ $jsVersion }}" defer></script>
</body>
</html>
