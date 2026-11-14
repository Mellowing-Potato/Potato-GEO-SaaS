<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Support/Analytics/TrafficClassifier.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Support\Analytics;

/**
 * 流量分类器（对标 GEOFlow App\Support\Analytics\TrafficClassifier）。
 *
 * 通过 User-Agent 识别人类访问、搜索引擎爬虫、AI 爬虫（ChatGPT/Doubao/DeepSeek 等）。
 */
final class TrafficClassifier
{
    public const HUMAN = 'human';

    public const SEARCH_BOT = 'search_bot';

    public const AI_BOT = 'ai_bot';

    public const OTHER_BOT = 'other_bot';

    public const UNKNOWN = 'unknown';

    /**
     * @return list<string>
     */
    public static function aiBotPatterns(): array
    {
        return [
            'gptbot',
            'chatgpt-user',
            'chatgpt',
            'oai-searchbot',
            'openai',
            'claudebot',
            'claude-searchbot',
            'claude-user',
            'anthropic',
            'perplexitybot',
            'perplexity-user',
            'perplexity',
            'ccbot',
            'google-extended',
            'applebot-extended',
            'bytespider',
            'meta-externalagent',
            'cohere-ai',
            'youbot',
            // Phase 1：补齐主流 AI 爬虫 token（spike §1.3 漏网）
            'amazonbot',
            'petalbot',
            'duckassistbot',
            'meta-externalfetcher',
            'googleother',
            'velenpubliccrawler',
            'dataforseobot',
            'seznambot',
        ];
    }

    /**
     * @return list<string>
     */
    public static function searchBotPatterns(): array
    {
        return [
            'googlebot',
            'bingbot',
            'baiduspider',
            'yandexbot',
            'duckduckbot',
            'sogou',
            'slurp',
            '360spider',
            'semrushbot',
            'ahrefsbot',
        ];
    }

    /**
     * @return list<string>
     */
    public static function otherBotPatterns(): array
    {
        return [
            'bot',
            'spider',
            'crawler',
            'curl',
            'wget',
            'python-requests',
            'go-http-client',
            'okhttp',
            'httpclient',
            'headlesschrome',
            'postmanruntime',
            'axios',
            'java/',
            'libwww-perl',
            'scrapy',
            'facebookexternalhit',
            'telegrambot',
            'whatsapp',
        ];
    }

    /**
     * @return list<string>
     */
    public static function nonHumanPatterns(): array
    {
        return [
            ...self::aiBotPatterns(),
            ...self::searchBotPatterns(),
            ...self::otherBotPatterns(),
        ];
    }

    public static function classify(?string $userAgent): string
    {
        $normalized = mb_strtolower(trim((string) $userAgent));

        if ($normalized === '') {
            return self::UNKNOWN;
        }

        if (self::matches($normalized, self::aiBotPatterns())) {
            return self::AI_BOT;
        }

        if (self::matches($normalized, self::searchBotPatterns())) {
            return self::SEARCH_BOT;
        }

        if (self::matches($normalized, self::otherBotPatterns())) {
            return self::OTHER_BOT;
        }

        return self::HUMAN;
    }

    /**
     * 从 UA 中提取命中的具体机器人名称（如 gptbot / bytespider）。
     */
    public static function botName(?string $userAgent): ?string
    {
        $normalized = mb_strtolower(trim((string) $userAgent));
        if ($normalized === '') {
            return null;
        }

        foreach (self::nonHumanPatterns() as $pattern) {
            if ($pattern !== '' && str_contains($normalized, $pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, name: string|null}
     */
    public static function classifyDetailed(?string $userAgent): array
    {
        return [
            'type' => self::classify($userAgent),
            'name' => self::botName($userAgent),
        ];
    }

    /**
     * @param  list<string>  $patterns
     */
    private static function matches(string $userAgent, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($userAgent, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
