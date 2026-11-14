<?php

namespace App\Services\Content;

final class WebExtractionQuality
{
    /** @return array{state:string,chars:int,preview:string,reasons:list<string>} */
    public function assess(string $html, string $text): array
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? $text);
        $chars = mb_strlen($plain, 'UTF-8');
        $reasons = [];
        if ($chars === 0) {
            $reasons[] = '没有提取到正文，请重抓或人工补全。';
        } elseif ($chars < 120) {
            $reasons[] = '提取内容较短，请与原网页核对；短页面也可能是完整内容。';
        }

        $navigation = '';
        preg_match_all('~<nav\b[^>]*>(.*?)</nav>~is', $html, $matches);
        foreach ($matches[1] ?? [] as $fragment) {
            $navigation .= ' '.strip_tags($fragment);
        }
        if ($chars > 0 && mb_strlen(trim($navigation), 'UTF-8') / $chars > 0.4) {
            $reasons[] = '页面导航文字占比较高，请核对提取预览。';
        }

        return [
            'state' => $reasons === [] ? 'unverified' : 'needs_review',
            'chars' => $chars,
            'preview' => mb_substr($plain, 0, 360, 'UTF-8'),
            'reasons' => $reasons,
        ];
    }
}
