{{--
    主题包预览（单 10）· 安全示例页

    这一页展示的是「这一版主题包生成出来的配色与字体」，套在一套固定占位内容上。
    为什么不是渲染草稿里的 .blade.php：
      那些模板是给客户自己的 Laravel 站用的（@extends('theme.{id}.layout')、
      route('site.home')、$articles / $article…），本仓没有那些路由和变量，渲染不了。
      而本功能的真实产出本来就只有配色和字体（执行书 §〇-3：版式是同一套骨架），
      所以「固定版式 + 真实配色字体」正好就是它能承诺的全部。

    安全：
      - $themeCss 是生成的 theme.css 原文，已在 Service::previewPage() 里去掉尖括号，
        这里用 {!! !!} 直接内联（用 {{ }} 会把引号转义成 &quot;，字体名直接坏掉）；
      - 页面里没有任何来自生成物的 HTML / JS，占位文案全部走 __()；
      - 响应头由 ThemePreviewController 带 CSP（script-src 'none'）+ X-Frame-Options。
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageLabel }}</title>
    <style nonce="{{ $cspNonce }}">{!! $themeCss !!}</style>
    <style nonce="{{ $cspNonce }}">
        /* 预览壳自身的样式，与主题 CSS 分开，避免主题没定义时整页塌掉。
           ⚠️ 这里只留 body 的 margin 归零：任何加在这一层的可见元素都会被
              客户当成「我的主题里有这个东西」，见下面 <body> 里那段说明。 */
        body { margin: 0; }
    </style>
</head>
<body class="rep-body">
    {{-- 安全预览说明（theme_package.safe_preview.notice）**故意不在这里渲染**，
         2026-09-03 从这一页移到了面板层（geo-theme-package.js 的 .tp-safenotice，
         钉在预览框下面）。三个原因，别再往回加：
           ① 保真度：这一页要回答的是「你的主题长这样」。在客户主题的 header
              上面压一条我们自己的横幅，是这页里唯一不属于客户的东西 ——
              这句话本来要防的就是「期望和产出对不上」，加在这儿反倒自己犯一次。
           ② 它会滚走：面板里的预览框高 420px，这一页比它长得多，
              一往下滚这条免责声明就没了 —— 恰恰在用户认真看预览的时候消失。
           ③ 这一页在 sandbox="" 的 iframe 里，面板的 CSS 够不着，
              样式只能写死（原来是一条 Tailwind 蓝的横幅），永远在设计体系外。
         ⚠️ 那个 lang key 没有废弃，仍在用：resources/views/theme-package.blade.php
            把它下发给前端（$tpConfig['safePreviewNotice']）。
            「grep 不到它显示在哪 → 以为没显示过」正是 SPEC 缺陷 11 判断错的地方。 --}}

    <header class="rep-header">
        <div class="rep-shell rep-header__bar">
            <span class="rep-brand">{{ $themeName }}</span>
            <nav class="rep-nav">
                <span>{{ __('theme_package.preview.home') }}</span>
                <span>{{ __('theme_package.preview.category') }}</span>
                <span>{{ __('theme_package.preview.article') }}</span>
            </nav>
        </div>
    </header>

    <main>
        @if ($page === 'article')
            <article class="rep-shell rep-detail">
                <span class="rep-chip">{{ __('theme_package.preview.sample_category') }}</span>
                <h1>{{ $title }}</h1>
                <div class="rep-meta">{{ $description }}</div>
                <p class="rep-summary">{{ __('theme_package.preview.sample_excerpt') }}</p>
                <div class="rep-content">
                    @foreach ($cards as $card)
                        <h2>{{ $card['title'] }}</h2>
                        <p>{{ $card['description'] }}</p>
                    @endforeach
                </div>
            </article>
        @else
            <section class="rep-shell rep-hero">
                <h1>{{ $title }}</h1>
                <p>{{ $description }}</p>
            </section>

            <section class="rep-shell rep-grid">
                @foreach ($cards as $card)
                    <article class="rep-card">
                        <h2>{{ $card['title'] }}</h2>
                        <p class="rep-summary">{{ $card['description'] }}</p>
                    </article>
                @endforeach
            </section>
        @endif
    </main>

    <footer class="rep-footer">
        <div class="rep-shell">{{ $themeName }} · v{{ $version }}</div>
    </footer>
</body>
</html>
