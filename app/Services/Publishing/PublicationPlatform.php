<?php

namespace App\Services\Publishing;

final class PublicationPlatform
{
    private const PLATFORMS = [
        'zhihu' => ['知乎', ['zhihu.com']],
        'xiaohongshu' => ['小红书', ['xiaohongshu.com', 'xhslink.com']],
        'weibo' => ['微博', ['weibo.com', 'weibo.cn']],
        'wechat' => ['微信', ['mp.weixin.qq.com']],
        'douyin' => ['抖音', ['douyin.com']],
        'bilibili' => ['B站', ['bilibili.com', 'b23.tv']],
        'reddit' => ['Reddit', ['reddit.com', 'redd.it']],
        'x' => ['X', ['x.com', 'twitter.com']],
        'linkedin' => ['LinkedIn', ['linkedin.com']],
        'custom' => ['自定义平台', []],
    ];

    public static function label(string $platform, ?string $custom = null): string
    {
        return $platform === 'custom' && trim((string) $custom) !== ''
            ? trim($custom) : (self::PLATFORMS[$platform][0] ?? $platform);
    }

    public static function acceptsUrl(string $platform, string $url): bool
    {
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && ! in_array($parts['port'], [80, 443], true))) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        if ($platform === 'custom') {
            return $host !== '';
        }
        foreach (self::PLATFORMS[$platform][1] ?? [] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    public static function acceptsPublishedUrl(string $platform, string $url): bool
    {
        if (! self::acceptsUrl($platform, $url)) {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (array_intersect(array_map('strtolower', array_keys($query)), ['token', 'access_token', 'auth_token', 'password', 'sessionid', 'session_id'])) {
            return false;
        }
        if ($platform === 'zhihu') {
            return (bool) preg_match('~^/(?:p/\d+|question/\d+/answer/\d+)/?$~', $path);
        }
        if ($platform === 'wechat') {
            if (array_intersect(array_map('strtolower', array_keys($query)), ['tempkey', 'key', 'pass_ticket'])) {
                return false;
            }

            return (bool) preg_match('~^/s/[A-Za-z0-9_-]+/?$~', $path)
                || ($path === '/s' && is_string($query['__biz'] ?? null) && $query['__biz'] !== ''
                    && is_string($query['mid'] ?? null) && ctype_digit($query['mid'])
                    && is_string($query['idx'] ?? null) && ctype_digit($query['idx'])
                    && is_string($query['sn'] ?? null) && $query['sn'] !== '');
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($platform === 'xiaohongshu' && ($host === 'creator.xiaohongshu.com'
            || preg_match('~^/user/profile(?:/|$)~i', $path))) {
            return false;
        }
        if ($platform === 'weibo' && (preg_match('~^/(?:u|n|profile|compose|drafts?)(?:/|$)~i', $path)
            || preg_match('~^/\d+/?$~', $path))) {
            return false;
        }

        // A platform homepage or editor is not evidence of a public post.
        return $path !== '/' && ! preg_match('~/(?:edit|editor|write|drafts?)(?:/|$)~i', $path);
    }
}
