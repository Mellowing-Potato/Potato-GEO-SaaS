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
                <p class="brand-positioning">项目、材料与模型配置保存在你的本机实例中。</p>
            </div>
        </section>
        <main>
            <h1>打开工作台</h1>
            <p class="intro">从桌面或应用菜单的“言及工作台”本机入口打开，无需填写邮箱和密码。</p>
            <p class="status" id="localStartMessage" role="status" aria-live="polite">正在打开你的本机工作台…</p>
            <div id="localStartHelp" hidden>
                <p>请从桌面或应用菜单的“言及工作台”本机入口打开，在系统浏览器中使用。没有快捷方式时，可按安装完成时显示的位置找到本机入口并双击。</p>
                <p class="field-note">不同浏览器或应用窗口的访问状态可能分开保存。如果当前浏览器已打开过工作台，可以重新检查。</p>
                <button class="btn-primary" id="localStartCheck" type="button" disabled>重新检查当前浏览器</button>
                <details class="footer">
                    <summary>高级排障（可选）</summary>
                    <p class="field-note">熟悉命令行的用户也可使用安装时显示的管理命令运行 <code>yanji open</code>，重新从本机入口打开。</p>
                </details>
            </div>
            <noscript><p class="status">请启用系统浏览器的 JavaScript，再从桌面或应用菜单的“言及工作台”本机入口打开。没有快捷方式时，可按安装完成时显示的位置找到本机入口并双击。</p></noscript>
        </main>
        <div class="eco-note">{{ config('brand.name') }} · 本机工作台</div>
    </div>
    <script src="/geo-local-start.js?v={{ \App\Support\PublicAssetVersion::for('geo-local-start.js') }}"></script>
</body>
</html>
