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
            <p class="intro">无需填写邮箱和密码，正在验证本次本机一次性启动凭证。</p>
            <div class="launch-status-card" id="localStartCard">
                <span class="launch-indicator" aria-hidden="true"></span>
                <p class="status" id="localStartMessage" role="status" aria-live="polite">正在打开你的本机工作台…</p>
            </div>
            <section class="launch-recovery" id="localStartHelp" hidden aria-label="重新打开指引">
                <p class="launch-recovery-title">本次一次性入口未能自动进入</p>
                <p class="field-note">链接可能已过期、已使用，或本机服务尚未启动。请在终端用安装完成时显示的管理命令路径运行以下命令，从新打开的页面进入：</p>
                <div class="launch-cmd-row">
                    <code id="localStartCommand">yanji open</code>
                    <button class="btn-code launch-copy-btn" id="localStartCopy" type="button">复制命令</button>
                </div>
                <p class="field-note" id="localStartCopyStatus" role="status" aria-live="polite"></p>
            </section>
            <noscript><p class="status is-error">请启用浏览器 JavaScript，再运行本机管理命令 <code>yanji open</code>。</p></noscript>
        </main>
        <div class="eco-note">{{ config('brand.name') }} · 本机工作台</div>
    </div>
    <script src="/geo-local-start.js?v={{ \App\Support\PublicAssetVersion::for('geo-local-start.js') }}"></script>
</body>
</html>
