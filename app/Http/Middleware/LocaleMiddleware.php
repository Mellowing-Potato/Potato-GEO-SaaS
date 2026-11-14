<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后端多语言中间件（阶段 3.3，对标 GEOFlow 多语 zh/en/ja/es/ru/pt，本计划落中/英）。
 *
 * 优先级：X-Locale 头 > Accept-Language 头 > 应用默认 locale。
 * 仅接受受支持的语言；其余回退到默认 locale（新增语种仅需补 resources/lang 目录）。
 */
final class LocaleMiddleware
{
    public const SUPPORTED = ['en', 'zh-CN'];

    public const DEFAULT = 'zh-CN';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);

        if (in_array($locale, self::SUPPORTED, true)) {
            App::setLocale($locale);
        } else {
            App::setLocale(self::DEFAULT);
        }

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $explicit = $request->header('X-Locale');
        if (is_string($explicit) && $explicit !== '') {
            return strtolower(trim($explicit));
        }

        $accept = $request->header('Accept-Language');
        if (is_string($accept) && $accept !== '') {
            return $this->fromAcceptLanguage($accept);
        }

        return self::DEFAULT;
    }

    private function fromAcceptLanguage(string $header): string
    {
        // 取质量最高的受支持语言：zh-CN,zh -> zh-CN；en-US,en -> en。
        $parts = preg_split('/,\s*/', $header) ?: [];
        $candidates = [];
        foreach ($parts as $part) {
            if (! preg_match('/^([a-zA-Z-]+)(?:;q=([0-9.]+))?$/', $part, $m)) {
                continue;
            }
            $lang = strtolower($m[1]);
            $quality = isset($m[2]) ? (float) $m[2] : 1.0;
            $candidates[$lang] = $quality;
        }

        // 精确匹配
        foreach ($candidates as $lang => $q) {
            if (in_array($lang, self::SUPPORTED, true)) {
                return $lang;
            }
        }

        // 前缀匹配（zh -> zh-CN, en -> en）
        foreach ($candidates as $lang => $q) {
            if (str_starts_with($lang, 'zh')) {
                return 'zh-CN';
            }
            if (str_starts_with($lang, 'en')) {
                return 'en';
            }
        }

        return self::DEFAULT;
    }
}
