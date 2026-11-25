<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>用户登录 · {{ config('brand.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="/brand/yanji/v1/favicon.svg">
    <link rel="alternate icon" href="/brand/yanji/v1/favicon.ico">
    <link rel="apple-touch-icon" href="/brand/yanji/v1/apple-touch-icon.png">
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
                <p class="brand-kicker">GEO 内容优化平台</p>
                <p class="brand-tagline">{{ config('brand.tagline') }}</p>
                <p class="brand-positioning">{{ config('brand.positioning') }}</p>
            </div>
        </section>
        <main id="authCard">
            <h1 id="loginTitle">用户登录</h1>
            <p class="intro" id="loginIntro">登录言及，管理项目、诊断报告与内容。</p>
            <form id="geoLoginForm" novalidate>
                <label for="email">邮箱</label>
                <input id="email" type="email" autocomplete="username" placeholder="登录邮箱" required maxlength="254">
                <div id="nameFields" hidden><label for="name">昵称</label><input id="name" autocomplete="name" maxlength="80"></div>
                <div id="passwordFields"><label for="password">密码</label><input id="password" type="password" autocomplete="current-password" required minlength="8" maxlength="128"></div>
                <div id="confirmFields" hidden><label for="confirmPassword">确认密码</label><input id="confirmPassword" type="password" autocomplete="new-password" minlength="8" maxlength="128"></div>
                @if(\App\Support\InstanceConfiguration::agreementsRequired())
                    <label class="agreement"><input type="checkbox" id="terms" required><span>我已阅读并同意
                        @if(config('instance.terms_url'))<a href="{{ config('instance.terms_url') }}" target="_blank" rel="noopener">《用户协议》</a>@endif
                        @if(config('instance.privacy_url'))<a href="{{ config('instance.privacy_url') }}" target="_blank" rel="noopener">《隐私政策》</a>@endif
                    </span></label>
                @endif
                <button type="submit" class="btn-primary" id="submitLogin">登录</button>
            </form>
            <div class="status" id="loginMessage" role="status" aria-live="polite"></div>
            <div class="footer">
                @if($registrationEnabled ?? false)<button type="button" data-mode="register" id="registerAccount">注册账号</button>@endif
                <button type="button" data-mode="forgot" id="forgotPassword">忘记密码？</button>
                <button type="button" data-mode="login" id="backToLogin" hidden>返回登录</button>
                @if(config('instance.website_url'))<a href="{{ config('instance.website_url') }}" rel="noopener">品牌官网</a>@endif
            </div>
        </main>
        <div class="eco-note">{{ config('brand.name') }} · GEO 内容优化平台</div>
    </div>
    <script src="/geo-login.js?v={{ \App\Support\PublicAssetVersion::for('geo-login.js') }}"></script>
</body>
</html>
