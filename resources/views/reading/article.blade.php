<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }} · {{ $seo['site_name'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    @if (! empty($seo['canonical_url']))
    <link rel="canonical" href="{{ $seo['canonical_url'] }}">
    @endif
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="{{ $seo['site_name'] }}">
    <meta property="og:title" content="{{ $seo['og_title'] }}">
    <meta property="og:description" content="{{ $seo['og_description'] }}">
    @if (! empty($seo['og_image']))
    <meta property="og:image" content="{{ $seo['og_image'] }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    <link rel="stylesheet" href="/geo-tokens.css?v={{ file_exists(public_path('geo-tokens.css')) ? filemtime(public_path('geo-tokens.css')) : time() }}">
    <link rel="stylesheet" href="/geo-fonts.css?v={{ filemtime(public_path('geo-fonts.css')) }}">
    <link rel="stylesheet" href="/geo-reading.css?v={{ file_exists(public_path('geo-reading.css')) ? filemtime(public_path('geo-reading.css')) : time() }}">
    <link rel="stylesheet" href="/geo-typography.css?v={{ filemtime(public_path('geo-typography.css')) }}">
</head>
<body class="geo-reading geo-app">
    <div class="wrap">
        <div class="site">{{ $seo['site_name'] }}</div>
        <h1>{{ $article->title }}</h1>
        <article class="article">
            {!! $html !!}
        </article>
    </div>
</body>
</html>
