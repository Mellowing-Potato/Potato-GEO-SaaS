<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Services/GeoFlow/ArticleRiskScanner.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Publishing;

use App\Models\Article;
use App\Models\ArticleRiskScan;
use App\Models\PlatformSensitiveWord;
use App\Models\SensitiveWord;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use LengthException;
use RuntimeException;

class ArticleRiskScanner
{
    // 规则结构变了（每条多一个 origin，且合并进了平台基线词），扫描算法版本与缓存
    // 前缀一起进位：不进位的话生产上残留的 v2 缓存里全是「没有 origin、没有平台词」
    // 的旧规则集，最长一小时内闸门仍按旧词表放行。
    public const SCAN_ALGORITHM_VERSION = '5';

    public const MAX_CONTENT_CHARACTERS = 200000;

    public const MAX_EXCERPT_CHARACTERS = 10000;

    /**
     * 平台基线词条数告警线（执行书 §〇-4 实测：5000 条扫一篇 5000 字正文约 130 ms）。
     *
     * ⚠️ 这个数字只用来触发告警，**不截断、不抛异常**，超了照样全量扫。
     * 与租户词的不对称是有意的：**平台词漏一条是合规事故，租户词漏一条不是。**
     * 红线规则宁可慢，不可漏。
     */
    public const MAX_PLATFORM_RULE_COUNT = 5000;

    /**
     * 单租户自定义词上限：防止某个租户粘贴几万条词拖慢全平台。
     * 超限**截断 + 告警**，同样不抛异常——租户自己粘贴超量是租户的问题，
     * 不该让整条发布链路 500。
     */
    public const MAX_TENANT_RULE_COUNT = 1000;

    public const ORIGIN_PLATFORM = 'platform';

    public const ORIGIN_TENANT = 'tenant';

    /**
     * 命中**平台基线词**时给作者的唯一提示（执行书 T10，老板 2026-08-30 拍板）。
     *
     * 三条约束刻在这一个常量上：
     * 1. **不点名**。平台词不回显给作者——作者试几次就能把红线清单试出来，
     *    等于一次一个词地公开发布这份表。租户自己加的词照旧点名（本来就是他加的）。
     * 2. **不出现「联系客服」「申诉」**。提示的作用就是让作者回去改稿，不开人工通道。
     * 3. **三处必须引用这一个常量**：T5 的文章角标、T6 的出站拦截落点、
     *    手动发布单闸门。三处文案有差异，作者就能从差异里反推出信息
     *    （比如「只有自动分发那条提示不一样 → 说明命中的是平台词」）。
     *    要改文案就改这里，别在调用点各写一份。
     */
    public const PLATFORM_BLOCK_NOTICE = '文中有违规用词，请重新检查后再提交。';

    private const RULE_CACHE_KEY_PREFIX = 'geoflow.article-risk-scanner.rules.v3.';

    private const FIELDS = [
        'title',
        'excerpt',
        'content',
        'keywords',
        'meta_description',
    ];

    private const FIELD_LIMITS = [
        'title' => 255,
        'excerpt' => self::MAX_EXCERPT_CHARACTERS,
        'content' => self::MAX_CONTENT_CHARACTERS,
        'keywords' => 500,
        'meta_description' => 500,
    ];

    /**
     * @param  array<string, mixed>  $content
     * @return array{status: string, match_count: int, matches: array<int, array{word: string, field: string, count: int, severity: string, category: string, origin: string, suggestion: ?string, snippet: string}>, content_hash: string, dictionary_hash: string}
     *
     * @throws JsonException
     * @throws RuntimeException 无租户上下文时（fail-close，见 rules()）
     */
    public function scan(array $content): array
    {
        $normalizedContent = $this->normalizedContent($content);
        $fieldSurfaces = [];

        foreach (self::FIELDS as $field) {
            $fieldSurfaces[$field] = $this->scanSurfaces($field, $normalizedContent[$field]);
        }

        $rules = $this->rules();
        $matched = $this->matchRules($fieldSurfaces, $rules);

        return [
            'status' => $matched['status'],
            'match_count' => $matched['match_count'],
            'matches' => $matched['matches'],
            'content_hash' => $this->hash($normalizedContent),
            'dictionary_hash' => $this->dictionaryHash($rules),
        ];
    }

    /**
     * 候选词试算（执行书 T8 的 dry-run）：拿一批**还没入库**的候选词去扫现有内容。
     *
     * ⚠️ 为什么复用 matchRules() 而不另写一套简化匹配：
     * 试算存在的唯一目的是回答「这批词上线后会拦掉多少篇现有稿子」。
     * 只要试算口径与真实闸门差一点点——少做全半角/大小写折叠，或少走
     * 「敏 感 词」这种插分隔符的紧凑匹配——试算就会系统性**低估**误伤面：
     * 运营看到「0 篇命中」放心加词，上线当天全平台作者同时卡住。
     * 那样试算不是安全阀，是安慰剂，比没有更坏。
     *
     * 候选词一律按 blocked 处理：平台基线词本来就是 blocked，而且只有 blocked
     * 才会启用紧凑匹配那一路，按 warning 试算同样会低估。
     *
     * @param  array<string, mixed>  $content
     * @param  array<int, string>  $candidateWords
     * @return array<int, array{word: string, field: string, count: int, severity: string, category: string, origin: string, suggestion: ?string, snippet: string}>
     *
     * @throws LengthException 字段超长时抛出；调用方必须**按篇** catch，
     *                         别让一篇超长稿废掉整个跨租户试算。
     */
    public function matchCandidateWords(array $content, array $candidateWords): array
    {
        $rules = [];

        foreach ($candidateWords as $candidate) {
            $word = trim((string) $candidate);

            if ($word === '') {
                continue;
            }

            $rules[] = [
                'word' => $word,
                'severity' => PlatformSensitiveWord::SEVERITY_BLOCKED,
                'category' => PlatformSensitiveWord::CATEGORY_POLITICS,
                'suggestion' => null,
                'applies_to' => [],
                'origin' => self::ORIGIN_PLATFORM,
            ];
        }

        if ($rules === []) {
            return [];
        }

        $normalizedContent = $this->normalizedContent($content);
        $fieldSurfaces = [];

        foreach (self::FIELDS as $field) {
            $fieldSurfaces[$field] = $this->scanSurfaces($field, $normalizedContent[$field]);
        }

        return $this->matchRules($fieldSurfaces, $rules)['matches'];
    }

    /**
     * 匹配核心：给定各字段的可匹配表面 + 规则集，返回命中与整体状态。
     *
     * 从 scan() 原地抽出来，唯一目的是让 T8 的候选词试算复用**同一套**匹配语义。
     * 抽离过程中不允许改动任何匹配行为——一改，闸门口径会跟着试算一起漂，
     * 而这两者恰恰是必须严格一致的（理由见 matchCandidateWords 的注释）。
     *
     * @param  array<string, list<array{value: string, folded: array{value: string, original_offsets: array<int, int>}, compact_folded: array{value: string, original_offsets: array<int, int>}}>>  $fieldSurfaces
     * @param  array<int, array{word: string, severity: string, category: string, suggestion: ?string, applies_to: array<int, string>, origin?: string}>  $rules
     * @return array{status: string, match_count: int, matches: array<int, array{word: string, field: string, count: int, severity: string, category: string, origin: string, suggestion: ?string, snippet: string}>}
     */
    private function matchRules(array $fieldSurfaces, array $rules): array
    {
        $matches = [];
        $matchCount = 0;
        $status = 'clean';

        foreach ($rules as $rule) {
            $normalizedWord = $this->normalize((string) $rule['word']);
            $needle = $this->fold($normalizedWord);
            $severity = $this->fold((string) $rule['severity']);
            $needles = [$needle];

            if ($severity === 'blocked') {
                $compactNeedle = $this->fold($this->withoutVisibleSeparators($normalizedWord));
                if ($compactNeedle !== '' && $compactNeedle !== $needle) {
                    $needles[] = $compactNeedle;
                } elseif ($compactNeedle !== '') {
                    $needles = [$compactNeedle];
                }
            }

            if ($needle === '') {
                continue;
            }

            $fields = $rule['applies_to'] === [] ? self::FIELDS : $rule['applies_to'];

            foreach (self::FIELDS as $field) {
                if (! in_array($field, $fields, true)) {
                    continue;
                }

                $matchedSurface = null;
                $matchedFolded = null;
                $matchedNeedle = null;
                $count = 0;

                foreach ($fieldSurfaces[$field] as $surface) {
                    $foldedSurfaces = [$surface['folded']];
                    if ($severity === 'blocked') {
                        $foldedSurfaces[] = $surface['compact_folded'];
                    }

                    foreach ($foldedSurfaces as $foldedSurface) {
                        foreach ($needles as $candidateNeedle) {
                            $surfaceCount = substr_count($foldedSurface['value'], $candidateNeedle);
                            if ($surfaceCount > $count) {
                                $count = $surfaceCount;
                                $matchedSurface = $surface;
                                $matchedFolded = $foldedSurface;
                                $matchedNeedle = $candidateNeedle;
                            }
                        }
                    }
                }

                if ($count === 0 || $matchedSurface === null || $matchedFolded === null || $matchedNeedle === null) {
                    continue;
                }

                $matchCount += $count;
                $status = $severity === 'blocked' ? 'blocked' : ($status === 'clean' ? 'warning' : $status);
                $matches[] = [
                    'word' => (string) $rule['word'],
                    'field' => $field,
                    'count' => $count,
                    'severity' => (string) $rule['severity'],
                    'category' => (string) $rule['category'],
                    // origin 是 T7 命中日志与 T10 提示文案的判据：平台词不点名、租户词照旧点名。
                    // 缺了它，作者侧就只能对所有命中一律点名或一律不点名，两种都不对。
                    'origin' => (string) ($rule['origin'] ?? self::ORIGIN_TENANT),
                    'suggestion' => $rule['suggestion'],
                    'snippet' => $this->snippet(
                        $matchedSurface['value'],
                        $matchedFolded['value'],
                        $matchedNeedle,
                        $matchedFolded['original_offsets']
                    ),
                ];
            }
        }

        return [
            'status' => $status,
            'match_count' => $matchCount,
            'matches' => $matches,
        ];
    }

    /** @throws JsonException */
    public function record(Article $article, string $trigger, ?int $userId = null): ArticleRiskScan
    {
        $result = $this->scan($this->articleContent($article));

        return $article->riskScans()->create([
            ...$result,
            'trigger' => $trigger,
            'user_id' => $userId,
            'scanned_at' => now(),
        ]);
    }

    /** @throws JsonException */
    public function isFresh(Article $article, ArticleRiskScan $scan): bool
    {
        if ((int) $scan->article_id !== (int) $article->getKey()) {
            return false;
        }

        $contentHash = $this->contentHash($this->articleContent($article));

        return hash_equals($scan->content_hash, $contentHash)
            && hash_equals($scan->dictionary_hash, $this->dictionaryHash($this->rules()));
    }

    /** 两个词源的版本号都要 bump——只 bump 一个，另一边的改动最长一小时才生效。 */
    public function clearRuleCache(): void
    {
        SensitiveWord::bumpRuleCacheVersion();
        PlatformSensitiveWord::bumpRuleCacheVersion();
    }

    /** @param array<string, mixed> $content */
    public function contentHash(array $content): string
    {
        return $this->hash($this->normalizedContent($content));
    }

    /**
     * 规则集 = 平台基线词（全部 is_enabled=true，无租户维度）+ 本租户词（显式过滤）。
     *
     * @return array<int, array{word: string, severity: string, category: string, suggestion: ?string, applies_to: array<int, string>, origin: string}>
     */
    private function rules(): array
    {
        // T1 改法 2：租户上下文为 null 时**抛异常，不返回空数组**。
        //
        // rules() 是拦截闸门的数据源。返回空数组 = 词表为空 = 扫描结果 clean = 闸门放行。
        // 一个「读不到词表」的故障不能表现成「内容干净」。
        // 真正走 fail-close 扫描的调用方：
        //   1. ManualPublicationService（建/改发布单时单次扫描）
        //   2. DistributionOrchestrator::complianceGate（出站闸门）
        // 二者都在租户上下文内（队列任务统一在 handle() 里 TenantContext::set），
        // 没有合法的 null 上下文调用路径。
        // 试算任务 DryRunPlatformSensitiveWordsJob 跨租户逐篇取内容，但走 matchCandidateWords()，
        // 不复用本 fail-close 路径，且每篇都显式 TenantContext::set，不影响上面的不变量。
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            throw new RuntimeException(
                'Article risk scan requires a tenant context; refusing to scan with an empty dictionary.'
            );
        }

        // 缓存 key 必须同时带**两个**版本号：只带租户词版本，运营台改了平台词，
        // 缓存里还是旧规则集，最长要等一小时才生效——直接违反「随时能更新」这条硬要求。
        $cacheKey = self::RULE_CACHE_KEY_PREFIX.$tenantId
            .'.t'.SensitiveWord::ruleCacheVersion()
            .'.p'.PlatformSensitiveWord::ruleCacheVersion();

        return Cache::remember(
            $cacheKey,
            now()->addHour(),
            fn (): array => $this->mergedRules($tenantId),
        );
    }

    /**
     * @return array<int, array{word: string, severity: string, category: string, suggestion: ?string, applies_to: array<int, string>, origin: string}>
     */
    private function mergedRules(int $tenantId): array
    {
        $rules = [];
        // 去重按「实际参与匹配的形态」（归一化 + 折叠后的词）建键，而不是原始字面量：
        // 平台与租户各自加了大小写/全半角不同但匹配等价的同一个词时，不重复计数。
        $seen = [];

        // ── 平台基线词：不带任何租户条件，永不截断 ──
        $platformWords = PlatformSensitiveWord::query()
            ->where('is_enabled', true)
            ->orderBy('word')
            ->orderBy('id')
            ->get(['word', 'severity', 'category', 'suggestion']);

        if ($platformWords->count() > self::MAX_PLATFORM_RULE_COUNT) {
            // 报警说明该做性能优化了（Aho-Corasick / 预编译正则），但**这一刻不丢词**。
            Log::warning('Platform sensitive-word baseline exceeds the performance advisory limit', [
                'count' => $platformWords->count(),
                'advisory_limit' => self::MAX_PLATFORM_RULE_COUNT,
                'action' => 'scanning all rules anyway (platform rules are never truncated)',
            ]);
        }

        foreach ($platformWords as $platformWord) {
            $key = $this->dedupeKey((string) $platformWord->word);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rules[] = [
                'word' => (string) $platformWord->word,
                'severity' => (string) $platformWord->severity,
                'category' => (string) $platformWord->category,
                // ⚠️ suggestion 只落进规则集给运营台/命中日志用，T10 保证它不会回显给作者。
                'suggestion' => $platformWord->suggestion === null ? null : (string) $platformWord->suggestion,
                'applies_to' => [],
                'origin' => self::ORIGIN_PLATFORM,
            ];
        }

        // ── 本租户自定义词：显式 where tenant_id，不再依赖全局作用域 ──
        // T1 改法 1：TenantScope 在租户上下文为 null 时不加条件（fail-open），
        // 靠它过滤等于「null 上下文下读到全平台所有租户的词」。写法对齐
        // SensitiveWordChecker::wordsForCurrentTenant()（那边一直是显式过滤的）。
        $tenantWords = SensitiveWord::query()
            ->where('tenant_id', $tenantId)
            ->where('is_enabled', true)
            ->orderBy('word')
            ->orderBy('id')
            ->limit(self::MAX_TENANT_RULE_COUNT + 1)
            ->get(['word', 'severity', 'category', 'suggestion', 'applies_to']);

        if ($tenantWords->count() > self::MAX_TENANT_RULE_COUNT) {
            Log::warning('Tenant sensitive-word rules exceed the scan limit; extra rules were truncated', [
                'tenant_id' => $tenantId,
                'limit' => self::MAX_TENANT_RULE_COUNT,
            ]);
            $tenantWords = $tenantWords->take(self::MAX_TENANT_RULE_COUNT);
        }

        foreach ($tenantWords as $tenantWord) {
            $key = $this->dedupeKey((string) $tenantWord->word);
            // 同一个词平台定 blocked、租户定 warning 时取平台的（平台词先入 $seen）。
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rules[] = [
                'word' => (string) $tenantWord->word,
                'severity' => (string) $tenantWord->severity,
                'category' => (string) $tenantWord->category,
                'suggestion' => $tenantWord->suggestion === null ? null : (string) $tenantWord->suggestion,
                'applies_to' => array_values(array_intersect(
                    self::FIELDS,
                    is_array($tenantWord->applies_to) ? $tenantWord->applies_to : []
                )),
                'origin' => self::ORIGIN_TENANT,
            ];
        }

        return $rules;
    }

    private function dedupeKey(string $word): string
    {
        return $this->fold($this->normalize($word));
    }

    /**
     * @return list<array{value: string, folded: array{value: string, original_offsets: array<int, int>}, compact_folded: array{value: string, original_offsets: array<int, int>}}>
     */
    private function scanSurfaces(string $field, string $value): array
    {
        $surfaces = [$value];
        $visible = $this->visibleText($field, $value);

        if ($visible !== '' && $visible !== $value) {
            $surfaces[] = $visible;
        }

        return array_map(fn (string $surface): array => [
            'value' => $surface,
            'folded' => $this->foldWithOriginalOffsetMap($surface),
            'compact_folded' => $this->compactFoldWithOriginalOffsetMap($surface),
        ], $surfaces);
    }

    private function visibleText(string $field, string $value): string
    {
        if ($field === 'content') {
            // 评审项 10：content 即 body_markdown，走真 Markdown 渲染（league/commonmark）。
            // html_input=strip：Markdown 里的裸 HTML（如 <script> 块）不出现在可见文本。
            $value = (string) Str::markdown($value, ['html_input' => 'strip']);
            $value = preg_replace('/<\/?(?:address|article|aside|blockquote|br|div|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tr|ul)\b[^>]*>/iu', ' ', $value) ?? $value;
            $value = strip_tags($value);
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\p{Default_Ignorable_Code_Point}+/u', '', $value) ?? $value;

        return $this->normalize($value);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{title: string, excerpt: string, content: string, keywords: string, meta_description: string}
     */
    private function normalizedContent(array $content): array
    {
        $normalized = [];

        foreach (self::FIELDS as $field) {
            $value = $content[$field] ?? '';
            $value = is_scalar($value) ? (string) $value : '';
            if (mb_strlen($value, 'UTF-8') > self::FIELD_LIMITS[$field]) {
                throw new LengthException("Article risk field [{$field}] exceeds the supported scan limit.");
            }
            $normalized[$field] = $this->normalize($value);
        }

        return $normalized;
    }

    /**
     * 本仓 Article 无 excerpt/content/keywords/meta_description 列（上游才有），
     * 按执行书 §一-3 同类适配映射到 summary / body_markdown / seo_description。
     *
     * 对外开放（T8）：候选词试算要跨租户逐篇取内容喂 matchCandidateWords()，
     * 若在试算侧自己拼一份字段映射，将来 Article 加列时两处会不同步 —— 试算漏字段
     * 就等于漏命中，正是「试算低估误伤」最容易发生的地方。只此一份映射。
     */
    public function articleContent(Article $article): array
    {
        return [
            'title' => $article->title,
            'excerpt' => $article->summary,
            'content' => $article->body_markdown,
            'keywords' => '',
            'meta_description' => $article->seo_description,
        ];
    }

    private function normalize(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }

        return Str::squish($value);
    }

    private function fold(string $value): string
    {
        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }

    /**
     * @return array{value: string, original_offsets: array<int, int>}
     */
    private function foldWithOriginalOffsetMap(string $value): array
    {
        $originalOffsets = [];
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $originalOffset => $character) {
            $foldedLength = mb_strlen($this->fold($character), 'UTF-8');

            for ($foldedOffset = 0; $foldedOffset < $foldedLength; $foldedOffset++) {
                $originalOffsets[] = $originalOffset;
            }
        }

        return [
            'value' => $this->fold($value),
            'original_offsets' => $originalOffsets,
        ];
    }

    /**
     * @return array{value: string, original_offsets: array<int, int>}
     */
    private function compactFoldWithOriginalOffsetMap(string $value): array
    {
        $foldedValue = '';
        $originalOffsets = [];
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($characters as $originalOffset => $character) {
            if (preg_match('/^[\p{Z}\p{P}]$/u', $character) === 1) {
                continue;
            }

            $foldedCharacter = $this->fold($character);
            $foldedValue .= $foldedCharacter;

            for ($foldedOffset = 0; $foldedOffset < mb_strlen($foldedCharacter, 'UTF-8'); $foldedOffset++) {
                $originalOffsets[] = $originalOffset;
            }
        }

        return [
            'value' => $foldedValue,
            'original_offsets' => $originalOffsets,
        ];
    }

    private function withoutVisibleSeparators(string $value): string
    {
        return preg_replace('/[\p{Z}\p{P}]+/u', '', $value) ?? $value;
    }

    /** @param array<int, int> $originalOffsets */
    private function snippet(string $value, string $foldedValue, string $foldedNeedle, array $originalOffsets): string
    {
        $position = mb_strpos($foldedValue, $foldedNeedle, 0, 'UTF-8');

        if ($position === false) {
            return Str::limit($value, 120);
        }

        $foldedMatchEnd = $position + mb_strlen($foldedNeedle, 'UTF-8') - 1;
        $originalMatchStart = $originalOffsets[$position];
        $originalMatchEnd = $originalOffsets[$foldedMatchEnd];
        $originalMatchLength = $originalMatchEnd - $originalMatchStart + 1;
        $start = max(0, $originalMatchStart - 40);
        $snippet = mb_substr($value, $start, $originalMatchLength + 80, 'UTF-8');

        return ($start > 0 ? '…' : '').$snippet.(($start + mb_strlen($snippet, 'UTF-8')) < mb_strlen($value, 'UTF-8') ? '…' : '');
    }

    /** @param array<mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, array{word: string, severity: string, category: string, suggestion: ?string, applies_to: array<int, string>}>  $rules
     */
    private function dictionaryHash(array $rules): string
    {
        return $this->hash([
            'scanner_version' => self::SCAN_ALGORITHM_VERSION,
            'rules' => $rules,
        ]);
    }
}
