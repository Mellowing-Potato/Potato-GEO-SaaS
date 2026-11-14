<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Services/GeoFlow/TitleAiGenerationService.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Content;

use App\Services\AI\AiProviderServiceImpl;
use App\Services\Contracts\AiProvider;
use App\Services\DTOs\AiProviderCallResult;
use RuntimeException;
use Throwable;

/**
 * 标题 AI 生成服务（复刻 GEOFlow TitleAiGenerationService）
 *
 * 对标 GEOFlow 的标题批量 AI 生成能力：
 * 1. 基于平台统一 AI 抽象层（AiProvider，自动按租户选最优模型）发起真实模型调用；
 * 2. 明确未发送或成功回包但标题无效时使用模板；发送状态不明则待核验；
 * 3. 输出统一结构（titles / fallback_used / fallback_reason），便于控制器入库。
 *
 * 与 GEOFlow 的差异：GEOFlow 依赖单租 ai_models 表与 Laravel\Ai agent 运行时；
 * 本实现复用 geo-saas 既有的 AiProvider 抽象（租户 Key → 平台共享 Key 自动降级），
 * 不引入独立的多 provider 管理台（属 D-3 有意不做范畴）。
 */
class TitleAiGenerationService
{
    private const STYLE_MAP = [
        'professional' => '专业严谨的',
        'attractive' => '吸引眼球的',
        'seo' => 'SEO 优化的',
        'creative' => '创意新颖的',
        'question' => '疑问式的',
    ];

    public function __construct(
        private readonly AiProvider $ai,
    ) {}

    /**
     * 基于关键词批量生成文章标题。
     *
     * call_result 是本次真实模型调用的结果（含 token 数），交给控制器显式扣点用；
     * 供应商成功但输出无效时，模板兜底仍保留调用回执并记账。
     *
     * @param  list<string>  $keywords
     * @param  array<string, mixed>  $attemptOptions
     * @return array{titles: list<string>, fallback_used: bool, fallback_reason: ?string, call_result: ?AiProviderCallResult, pending: bool}
     */
    public function generateTitles(
        array $keywords,
        int $count,
        string $style = 'professional',
        string $customPrompt = '',
        string $model = AiProviderServiceImpl::GEO_TEXT_MODEL,
        array $attemptOptions = [],
    ): array {
        $keywords = array_values(array_filter(array_map('strval', $keywords), static fn (string $k): bool => $k !== ''));
        $count = max(1, min((int) $count, 50));

        $modelAttemptStarted = false;
        try {
            if ($keywords === []) {
                throw new RuntimeException('keywords_empty');
            }

            $modelAttemptStarted = true;
            $callResult = $this->requestTitlesFromModel($keywords, $count, $style, $customPrompt, $model, $attemptOptions);
            if (! $callResult->success) {
                if ($callResult->provider === '' && $callResult->model === ''
                    && ($callResult->gateway['pre_send_rejection'] ?? false)) {
                    return ['titles' => [], 'fallback_used' => false, 'fallback_reason' => null,
                        'call_result' => null, 'pending' => false, 'error' => $callResult->errorMessage];
                }
                // Only an explicit pre-send decision proves the model was not
                // reached. Missing receipt fields on a failed attempt are not
                // evidence that a supplier charge did not occur.
                if (isset($attemptOptions['task_id'])
                    && data_get($callResult->gateway, 'pre_send_rejection') !== true) {
                    return [
                        'titles' => [],
                        'fallback_used' => false,
                        'fallback_reason' => null,
                        'call_result' => $callResult,
                        'pending' => true,
                    ];
                }

                return [
                    'titles' => $this->generateMockTitles($keywords, $count, $style),
                    'fallback_used' => true,
                    'fallback_reason' => $callResult->errorMessage ?: 'ai_unavailable',
                    'call_result' => null,
                    'pending' => false,
                ];
            }
            $titles = $this->parseGeneratedTitles(trim((string) $callResult->content));

            if ($titles !== []) {
                return [
                    'titles' => $titles,
                    'fallback_used' => false,
                    'fallback_reason' => null,
                    'call_result' => $callResult,
                    'pending' => false,
                ];
            }

            // The supplier answered successfully even if the content is unusable.
            // Preserve its receipt so template fallback cannot become a free call.
            return [
                'titles' => $this->generateMockTitles($keywords, $count, $style),
                'fallback_used' => true,
                'fallback_reason' => 'empty_result',
                'call_result' => $callResult,
                'pending' => false,
            ];
        } catch (Throwable $exception) {
            if ($modelAttemptStarted && isset($attemptOptions['task_id'])) {
                return [
                    'titles' => [],
                    'fallback_used' => false,
                    'fallback_reason' => null,
                    'call_result' => null,
                    'pending' => true,
                ];
            }

            return [
                'titles' => $this->generateMockTitles($keywords, $count, $style),
                'fallback_used' => true,
                'fallback_reason' => $exception->getMessage(),
                'call_result' => null,
                'pending' => false,
            ];
        }
    }

    /**
     * 发起真实模型调用，返回完整调用结果（正文 + token 用量）。
     *
     * @param  list<string>  $keywords
     */
    private function requestTitlesFromModel(
        array $keywords,
        int $count,
        string $style,
        string $customPrompt,
        string $model,
        array $attemptOptions,
    ): AiProviderCallResult {
        $styleDescription = self::STYLE_MAP[$style] ?? self::STYLE_MAP['professional'];
        $keywordsText = implode('、', $keywords);

        $systemPrompt = "你是一个专业的内容标题生成专家。请根据提供的关键词生成{$styleDescription}文章标题。";
        $userPrompt = "请基于以下关键词生成 {$count} 个{$styleDescription}文章标题：\n\n关键词：{$keywordsText}\n\n";
        if ($customPrompt !== '') {
            $userPrompt .= "额外要求：{$customPrompt}\n\n";
        }
        $userPrompt .= "要求：\n1. 每个标题独占一行\n2. 标题要有吸引力和可读性\n3. 适合搜索引擎优化\n4. 不要添加序号或其他标记\n5. 直接输出标题内容";

        $result = $this->ai->generate($userPrompt, $systemPrompt, [
            'model' => $model,
            'feature_id' => 'batch-titles',
            'temperature' => 0.8,
            'max_tokens' => 1024,
            ...$attemptOptions,
        ]);

        return $result;
    }

    /**
     * 解析模型输出文本为标题列表（JSON 优先 / 纯文本兜底 / 结构噪声丢弃）。
     *
     * 纵深防御：无论网关返回什么，geo-saas 都不得把 JSON 结构噪声（{ } [ ] " , :）当标题入库。
     * - 内容为 JSON（{ 或 [ 开头）：json_decode 成功且能定位字符串列表 → 抽取标题；
     *   失败（畸形 JSON）→ 直接返回 [] 走兜底，绝不降级成逐行文本解析。
     * - 纯文本（每行一个标题）：维持现有逐行逻辑，但每行过 sanitizeTitle + 结构噪声过滤。
     *
     * @return list<string>
     */
    private function parseGeneratedTitles(string $content): array
    {
        $trimmed = trim($content);

        // 1. JSON 优先
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $candidates = $this->extractStringListFromDecoded($decoded);
                $titles = [];
                foreach ($candidates as $candidate) {
                    $title = $this->sanitizeTitle((string) $candidate);
                    if ($title !== null) {
                        $titles[] = $title;
                    }
                }

                return array_values(array_unique($titles));
            }
            // 畸形 JSON：绝不降级成逐行文本解析，返回空 → 上层 fallback
            return [];
        }

        // 2. 纯文本路径：逐行解析（带结构噪声过滤）
        $titles = [];
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $title = $this->sanitizeTitle((string) $line);
            if ($title !== null) {
                $titles[] = $title;
            }
        }

        return array_values(array_unique($titles));
    }

    /**
     * 从解码后的 JSON 结构中定位字符串标题列表。
     * 优先 `titles` 键；其次顶层若本身就是字符串数组；再次常见容器键（data/items/results/list）。
     *
     * @param  array<mixed>  $decoded
     * @return list<string>
     */
    private function extractStringListFromDecoded(array $decoded): array
    {
        if ($this->isListOfStrings($decoded)) {
            return array_values(array_map('strval', $decoded));
        }

        foreach (['titles', 'data', 'items', 'results', 'list'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key]) && $this->isListOfStrings($decoded[$key])) {
                return array_values(array_map('strval', $decoded[$key]));
            }
        }

        return [];
    }

    /**
     * 判断数组是否「全为字符串且非空」。
     *
     * @param  array<mixed>  $value
     */
    private function isListOfStrings(array $value): bool
    {
        if ($value === []) {
            return false;
        }
        // 必须是顺序列表（键 0..n-1）。排除关联数组（如 {"error":"x","code":"y"}），
        // 否则关联数组会被误当成标题列表、抽出其字符串值污染标题库。
        if (! array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 清洗单行候选标题；结构噪声 / 无效行返回 null（丢弃）。
     *
     * 去序号前缀 → 去尾随逗号 → 剥首尾成对引号 → 结构噪声判定 → 实效性校验（去标点后须有中日韩/字母/数字）→ 长度 >= 2。
     *
     * @return string|null
     */
    private function sanitizeTitle(string $line): ?string
    {
        $line = trim($line);
        $line = (string) preg_replace('/^\d+[\.\)\-、\s]*/u', '', $line);
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        // 先去尾随逗号（中英文，须 unicode 安全，不能用字节级 rtrim 以免截断多字节尾字节）
        $line = (string) preg_replace('/[，,]+$/u', '', $line);
        $line = $this->stripWrappingQuotes($line);
        $line = trim($line);

        if ($line === '') {
            return null;
        }

        // 结构噪声：去掉空白与结构字符后为空 → 纯 { } [ ] , : " 及其组合
        if (preg_replace('/[\s{}\[\],:"]/u', '', $line) === '') {
            return null;
        }

        // 实效性：去掉标点/符号/空白后须含至少一个可辨字符
        $stripped = (string) preg_replace('/[\p{P}\p{S}\s]/u', '', $line);
        if ($stripped === '' || mb_strlen($stripped) < 1) {
            return null;
        }

        // 长度 < 2 视为无效
        if (mb_strlen($line) < 2) {
            return null;
        }

        return $line;
    }

    /**
     * 剥离首尾成对引号（" ' 「」 『』）。
     */
    private function stripWrappingQuotes(string $line): string
    {
        $len = mb_strlen($line);
        if ($len < 2) {
            return $line;
        }

        $first = mb_substr($line, 0, 1);
        $last = mb_substr($line, -1, 1);
        $pairs = [
            '"' => '"',
            "'" => "'",
            '「' => '」',
            '『' => '』',
        ];

        if (isset($pairs[$first]) && $pairs[$first] === $last) {
            return mb_substr($line, 1, $len - 2);
        }

        return $line;
    }

    /**
     * 模板兜底标题生成（模型不可用时保证流程可用）。
     *
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function generateMockTitles(array $keywords, int $count, string $style): array
    {
        $styleTemplates = [
            'professional' => [
                '{keyword}的深度分析与研究',
                '关于{keyword}的专业见解',
                '{keyword}行业发展趋势报告',
            ],
            'attractive' => [
                '你绝对不知道的{keyword}秘密',
                '揭秘{keyword}背后的故事',
                '{keyword}让人意想不到的用途',
            ],
            'seo' => [
                '{keyword}完整指南：从入门到精通',
                '{keyword}常见问题解答大全',
                '如何选择最适合的{keyword}方案',
            ],
            'creative' => [
                '重新定义{keyword}的可能性',
                '如果{keyword}会说话，它会告诉你什么？',
                '当{keyword}遇上创新思维',
            ],
            'question' => [
                '{keyword}真的有用吗？',
                '为什么{keyword}如此重要？',
                '{keyword}的未来在哪里？',
            ],
        ];

        $templates = $styleTemplates[$style] ?? $styleTemplates['professional'];
        $pool = $keywords === [] ? ['内容'] : $keywords;

        $titles = [];
        for ($i = 0; $i < $count; $i++) {
            $template = $templates[$i % count($templates)];
            $keyword = $pool[$i % count($pool)];
            $titles[] = str_replace('{keyword}', $keyword, $template);
        }

        return array_values(array_unique($titles));
    }
}
