<?php

namespace App\Services\Content;

use App\Models\PlatformSensitiveWord;
use App\Models\SensitiveWord;
use App\Support\TenantContext;

/**
 * 敏感词校验服务（复刻 GEOFlow SensitiveWord 实体能力，租户隔离）。
 *
 * 在文章创建/更新时扫描标题与正文，命中任一敏感词则标记 flagged_sensitive。
 *
 * 本服务是**两个消费者之一**（执行书 T4）：
 *   - 本类（SensitiveWordChecker）：文章侧角标，只负责「要不要标红」，不阻断
 *   - ArticleRiskScanner          ：发布/分发闸门，负责「放不放行」
 * 两条是独立代码路径，规则集必须一致（平台词 + 本租户词），
 * 只改一边的后果：文章标红了但发布照样放行，或者反过来。
 *
 * `severity` 在本类里**故意不看**（执行书 T2）：
 * `flagged_sensitive` 的产品语义是「命中敏感词，发布前请复核」
 * （geo-entry.js:5473 的 title 文案），warning 级也应该标记。
 * 不是漏了判断，别「顺手补上」。
 */
class SensitiveWordChecker
{
    /**
     * 命中处上下文各取多少字（写进 sensitive_word_hits.context，给运营判误伤用，见 T7）。
     */
    private const CONTEXT_RADIUS = 30;

    /**
     * 请求级规则缓存。
     *
     * key 形如 `{tenantId}:t{租户词版本}:p{平台词版本}`——
     * 平台词版本号必须进 key（执行书 T4 硬要求）：运营台在同一进程里改完平台词，
     * 下一次读必须拿到新词，不能等静态数组自然消亡。
     *
     * 容器是 FrankenPHP 普通模式（php_server，未开 worker），PHP 静态变量按请求重置，
     * 所以这一层不存在跨请求脏数据；跨请求那层在 ArticleRiskScanner 的 Cache::remember 里。
     *
     * @var array<string, array<int, array{word: string, severity: string, origin: string}>>
     */
    private static array $cache = [];

    /**
     * 扫描文本，返回命中的第一个敏感词；未命中返回 null。
     */
    public function contains(string $text): ?string
    {
        $match = $this->firstMatch($text);

        return $match['word'] ?? null;
    }

    /**
     * 结构化检查结果。
     *
     * @return array{hit: bool, word: ?string}
     */
    public function check(string $text): array
    {
        $hit = $this->contains($text);

        return ['hit' => $hit !== null, 'word' => $hit];
    }

    /**
     * 扫描多个字段（标题/摘要/正文），任一命中即返回该词。
     */
    public function checkAny(string ...$texts): ?string
    {
        foreach ($texts as $text) {
            $hit = $this->contains((string) $text);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * 返回第一条命中规则的完整信息（含来源与上下文），未命中返回 null。
     *
     * 命中日志（T7）需要 origin / severity / context，只拿一个词字符串不够。
     *
     * @return array{word: string, severity: string, origin: string, context: string}|null
     */
    public function firstMatch(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        foreach ($this->rulesForCurrentTenant() as $rule) {
            $position = mb_strpos($text, $rule['word']);
            if ($position === false) {
                continue;
            }

            return [
                'word' => $rule['word'],
                'severity' => $rule['severity'],
                'origin' => $rule['origin'],
                'context' => $this->contextAround($text, $position, mb_strlen($rule['word'])),
            ];
        }

        return null;
    }

    /**
     * 按字段扫描，返回第一条命中及其所在字段名（surface）。
     *
     * @param  array<string, ?string>  $fields  形如 ['title' => '...', 'content' => '...']
     * @return array{surface: string, word: string, severity: string, origin: string, context: string}|null
     */
    public function firstMatchAmong(array $fields): ?array
    {
        foreach ($fields as $surface => $text) {
            $match = $this->firstMatch((string) $text);
            if ($match !== null) {
                return ['surface' => (string) $surface] + $match;
            }
        }

        return null;
    }

    /**
     * 规则集 = 平台词（全部 is_enabled=true，不带租户条件）+ 本租户词（tenant_id = ? and is_enabled = true）。
     *
     * @return array<int, array{word: string, severity: string, origin: string}>
     */
    private function rulesForCurrentTenant(): array
    {
        $tenantId = TenantContext::id();

        $cacheKey = sprintf(
            '%s:t%d:p%d',
            $tenantId === null ? 'null' : (string) $tenantId,
            SensitiveWord::ruleCacheVersion(),
            PlatformSensitiveWord::ruleCacheVersion(),
        );

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $rules = [];
        $seen = [];

        // 平台词先进，因此同名时平台的 severity 胜出（平台 blocked + 租户 warning → 取 blocked）。
        // 这张表没有 tenant_id 列，也就不需要租户上下文——
        // 租户上下文为 null 时（如未来某个未设上下文的调用方）平台红线词依然生效，
        // 只是读不到该租户自定义词。对角标场景这比整体放空安全得多。
        $platformWords = PlatformSensitiveWord::query()
            ->where('is_enabled', true)
            ->orderBy('id')
            ->get(['word', 'severity']);

        foreach ($platformWords as $platformWord) {
            $word = trim((string) $platformWord->word);
            // 空词会让 mb_strpos 恒真/恒命中，等于把整条链路搞乱，直接跳过。
            if ($word === '' || isset($seen[$word])) {
                continue;
            }
            $seen[$word] = true;
            $rules[] = [
                'word' => $word,
                'severity' => (string) $platformWord->severity,
                'origin' => 'platform',
            ];
        }

        if ($tenantId !== null) {
            // 显式 where tenant_id：不依赖 TenantScope 全局作用域
            // （它在上下文为 null 时 fail-open，这里不能把别的租户词读进来）。
            $tenantWords = SensitiveWord::query()
                ->where('tenant_id', $tenantId)
                ->where('is_enabled', true)   // T2：租户能在自己面板停用自己加的词，这里必须认这个开关
                ->orderBy('id')
                ->get(['word', 'severity']);

            foreach ($tenantWords as $tenantWord) {
                $word = trim((string) $tenantWord->word);
                if ($word === '' || isset($seen[$word])) {
                    continue;
                }
                $seen[$word] = true;
                $rules[] = [
                    'word' => $word,
                    'severity' => (string) $tenantWord->severity,
                    'origin' => 'tenant',
                ];
            }
        }

        return self::$cache[$cacheKey] = $rules;
    }

    /**
     * 取命中处前后各 CONTEXT_RADIUS 字。
     */
    private function contextAround(string $text, int $position, int $length): string
    {
        $start = max(0, $position - self::CONTEXT_RADIUS);
        $end = min(mb_strlen($text), $position + $length + self::CONTEXT_RADIUS);

        return mb_substr($text, $start, $end - $start);
    }

    /**
     * 清空请求级缓存（测试或租户切换时调用）。
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
