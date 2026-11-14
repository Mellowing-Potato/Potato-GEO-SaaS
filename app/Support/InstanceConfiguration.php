<?php

namespace App\Support;

final class InstanceConfiguration
{
    public static function validate(): void
    {
        $url = (string) config('app.url');
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \RuntimeException('请配置 APP_URL 为本实例的完整地址（http 或 https，不含路径）。');
        }
        foreach (['terms_url', 'privacy_url', 'website_url'] as $key) {
            $value = (string) config('instance.'.$key);
            if ($value !== '' && (!filter_var($value, FILTER_VALIDATE_URL)
                || !in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true))) {
                throw new \RuntimeException('实例网址配置无效：'.$key);
            }
        }
    }

    public static function userAgent(): string
    {
        self::validate();
        return 'YanjiBot/'.config('instance.version').' (+'.rtrim(config('app.url'), '/').')';
    }

    public static function agreementsRequired(): bool
    {
        return filled(config('instance.terms_url')) || filled(config('instance.privacy_url'));
    }
}
