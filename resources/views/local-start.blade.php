<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>打开工作台 · {{ config('brand.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="/brand/yanji/v1/favicon.svg">
    <link rel="stylesheet" href="/geo-tokens.css?v={{ \App\Support\PublicAssetVersion::for('geo-tokens.css') }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ \App\Support\PublicAssetVersion::for('geo-fonts.css') }}">
    <link rel="stylesheet" href="/geo-login.css?v={{ \App\Support\PublicAssetVersion::for('geo-login.css') }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ \App\Support\PublicAssetVersion::for('geo-typography.css') }}">
</head>
<body class="geo-app geo-login">
    <div class="stage">
        <section class="brand-panel" aria-label="{{ config('brand.name') }}品牌介绍">
            <x-yanji-logo variant="horizontal" :label="config('brand.name')" class="login-logo" />
            <div class="brand-copy">
                <p class="brand-kicker">本机 GEO 内容工作台</p>
                <p class="brand-tagline">{{ config('brand.tagline') }}</p>
                <p class="brand-positioning">项目、材料与模型配置仅保存在你的本机实例中，费用由你自己的模型供应商账户承担。</p>
                <ol class="launch-steps" aria-label="首次使用流程">
                    <li><span class="launch-step-ix">01</span><span><strong>配置文本模型</strong><small>填写自己的 API Key 或连接本机 Magpie</small></span></li>
                    <li><span class="launch-step-ix">02</span><span><strong>建立首个项目</strong><small>填写项目名称、品牌与目标市场即可开始</small></span></li>
                    <li><span class="launch-step-ix">03</span><span><strong>运行首个技能</strong><small>生成可导出的 AI 搜索诊断或意图报告</small></span></li>
                </ol>
            </div>
        </section>
        <main class="launch-main">
            <h1>打开工作台</h1>
            <p class="intro">直接在本机浏览器打开工作台地址，或从桌面及应用菜单的“言及工作台”入口进入，无需填写邮箱和密码。</p>
            <div class="launch-status-card" id="localStartCard">
                <span class="launch-indicator" aria-hidden="true"></span>
                <p class="status" id="localStartMessage" role="status" aria-live="polite">正在打开你的本机工作台…</p>
            </div>
            <div class="launch-recovery" id="localStartHelp" hidden>
                <p class="field-note">请确认直接通过本机回环地址（<code>127.0.0.1</code>）在系统浏览器中访问。若电脑开启了网络代理或浏览器代理插件，请将 <code>127.0.0.1</code> 设为直连（绕过本地地址）后再试。</p>
                <p class="field-note">不同浏览器或隐私窗口的会话分开保存；点击下方按钮即可在当前浏览器直接进入工作台。</p>
                <button class="btn-primary" id="localStartCheck" type="button" disabled>重新进入工作台</button>
                <details class="footer">
                    <summary>高级排障（可选）</summary>
                    <p class="field-note">如服务尚未启动，可双击安装完成时生成的“言及工作台”本机入口，或使用管理命令运行 <code>yanji open</code> 自动拉起服务。</p>
                </details>
            </div>
            <noscript><p class="status is-error">请启用系统浏览器的 JavaScript，再重新打开本机工作台地址或双击桌面“言及工作台”入口。</p></noscript>
        </main>
        <div class="eco-note">{{ config('brand.name') }} · 本机工作台</div>
    </div>
    <script src="/geo-local-start.js?v={{ \App\Support\PublicAssetVersion::for('geo-local-start.js') }}"></script>
</body>
</html>
