<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $form->name }} - {{ $siteName }}</title>
    @if ($description !== '')
        <meta name="description" content="{{ $description }}">
    @endif
    {{-- 二轮评审修复：这里不发绝对 URL。 --}}
    {{-- 当时的病灶是「边缘 Caddy 终止 TLS + 没配 TrustProxies」→ Laravel 认定协议是 http， --}}
    {{-- asset() 在 https 页面上吐出 http:// 的样式和脚本，被浏览器当混合内容拦掉。 --}}
    {{-- 那个根因已在 bootstrap/app.php 配 trustProxies 修掉了，但这里仍用根相对路径： --}}
    {{-- ① 本仓其它视图（welcome / analytics / visibility / platform-ops）一直是这个写法； --}}
    {{-- ② 页面正确性不该再取决于代理配置对不对 —— 少一层依赖就少一种翻车方式。 --}}
    {{-- 顺带补上此前缺的破缓存版本号。 --}}
    {{-- 设计令牌：必须排在页面自身样式表【前面】，页面里的覆盖才压得住。 --}}
    <link rel="stylesheet" href="/geo-tokens.css?v={{ file_exists(public_path('geo-tokens.css')) ? filemtime(public_path('geo-tokens.css')) : time() }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ filemtime(public_path('geo-fonts.css')) }}">
    <link rel="stylesheet" href="/geo-lead-form.css?v={{ $cssVersion }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ filemtime(public_path('geo-typography.css')) }}">
</head>
<body class="geo-public-form geo-app">
    <main class="lf-wrap">
        <h1 class="lf-title">{{ $form->name }}</h1>
        @if ($description !== '')
            <p class="lf-desc">{{ $description }}</p>
        @endif

        {{-- 成功提示区：JS 用 textContent 写入，绝不 innerHTML，防 XSS（L8） --}}
        <div id="lf-alert" class="lf-alert" role="alert" hidden></div>

        {{-- 评审修复（中低项 M3）：无 JS 降级。原生 form POST 一直可用，且控制器对非 --}}
        {{-- JSON 请求回 302 + lead_form_message flash / $errors，但页面此前不渲染它们 --}}
        {{-- ——JS 关掉时访客提交后什么都看不到。这里服务端直出，复用同一套 lf-alert 样式。 --}}
        @if (session('lead_form_message'))
            <div class="lf-alert lf-alert--ok" role="alert">{{ session('lead_form_message') }}</div>
        @endif
        @if ($errors->any())
            <div class="lf-alert lf-alert--err" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="lf-form" class="lf-form" method="POST" action="{{ $formAction }}" novalidate>
            @csrf
            {{-- 蜜罐：真访客看不到也不会填；bot 自动填 → 命中即按成功返回但不落库 --}}
            <input type="text" name="website" tabindex="-1" autocomplete="off"
                   aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;">

            @foreach ($fields as $field)
                <div class="lf-field lf-field--{{ $field['type'] }}">
                    @if ($field['type'] !== 'checkbox')
                    <label class="lf-label" for="lf-{{ $field['name'] }}">
                        {{ $field['label'] }}@if ($field['required'])<span class="lf-req">*</span>@endif
                    </label>
                    @endif

                    @switch($field['type'])
                        @case('textarea')
                            <textarea class="lf-input" id="lf-{{ $field['name'] }}" name="{{ $field['name'] }}"
                                      rows="5" @if ($field['required']) required @endif></textarea>
                            @break

                        @case('select')
                            <select class="lf-input" id="lf-{{ $field['name'] }}" name="{{ $field['name'] }}"
                                    @if ($field['required']) required @endif>
                                <option value="">请选择</option>
                                @foreach ($field['options'] as $option)
                                    <option value="{{ $option }}">{{ $option }}</option>
                                @endforeach
                            </select>
                            @break

                        @case('checkbox')
                            <label class="lf-checkbox">
                                <input type="checkbox" id="lf-{{ $field['name'] }}" name="{{ $field['name'] }}" value="1">
                                <span>{{ $field['label'] }}@if ($field['required'])<span class="lf-req">*</span>@endif</span>
                            </label>
                            @break

                        @case('email')
                            <input type="email" class="lf-input" id="lf-{{ $field['name'] }}" name="{{ $field['name'] }}"
                                   @if ($field['required']) required @endif>
                            @break

                        @case('phone')
                            <input type="tel" class="lf-input" id="lf-{{ $field['name'] }}" name="{{ $field['name'] }}"
                                   @if ($field['required']) required @endif>
                            @break

                        @default
                            <input type="text" class="lf-input" id="lf-{{ $field['name'] }}" name="{{ $field['name'] }}"
                                   @if ($field['required']) required @endif>
                    @endswitch
                </div>
            @endforeach

            <button type="submit" class="lf-submit">{{ $submitLabel }}</button>
        </form>
    </main>

    <script nonce="{{ $cspNonce }}">
        window.LF_CONFIG = {
            submitUrl: @json($formAction),
            csrfToken: @json($csrfToken),
        };
    </script>
    <script src="/geo-lead-form.js?v={{ $jsVersion }}" defer></script>
</body>
</html>
