<?php

namespace App\Support\Logging;

use App\Support\OperationalTextRedactor;
use Monolog\LogRecord;

/**
 * 日志脱敏（计划 §9.7 的"日志脱敏"）：写进 handler 之前，把记录里的凭据、联系方式、账本 ID 抹掉。
 *
 * **为什么做在日志管道上，而不是逐处记得调用 `OperationalTextRedactor`**：
 * 逐处调用靠人记。全仓 216 处 `Log::*()`，任何人任何一次新加一句
 * `Log::error('Embedding API call failed', ['body' => $response->body()])`（上游回包里可能
 * 带着 key 或账号）或 `['error' => $e->getMessage()]`（异常消息里可能带着 token），
 * 都可能是第一次泄露 —— 而且泄露之后没人会知道。做在管道上，是**默认安全**：
 * 新写的日志自动受同一套规则约束，漏掉的可能性只与规则本身有关。
 *
 * 两类命中：
 *   1. **键名命中**（`context` / `extra` 里的键）：凭据整段替换、联系方式整段替换、
 *      账本/身份 ID 换成指纹（`sha256:xxxxxxxx`，日志仍可对账，但拿不到原值）；
 *   2. **值里的凭据特征**（字符串，含 `message`）：复用 `OperationalTextRedactor` 的
 *      Bearer / `sk-…` / `api_key=…` / 手机号 / 邮箱 / `qh_txn…` 等模式。
 *
 * ⚠️ 键名规则**只做精确匹配**，不做"包含 token 就抹"这种模糊匹配：本仓
 * `token_usage`、`max_tokens`、`input_tokens`、`total_tokens` 这类统计键满地都是，
 * 模糊匹配会把账单和排障要看的数字全抹掉 —— 那样的脱敏上线第一天就会被关掉。
 */
final class RedactSensitiveContext
{
    public const REDACTED = '[redacted]';

    /** 账本/身份 ID 的专门标签：不是抹掉，是换成指纹（保留可关联性）。 */
    public const FINGERPRINT = '[fingerprint]';

    /**
     * 敏感键名 → 替换值（键名先小写、连字符归一成下划线再比对）。
     *
     * @var array<string, string>
     */
    private const SENSITIVE_KEYS = [
        // 凭据：整段替换
        'authorization' => self::REDACTED,
        'cookie' => self::REDACTED,
        'set_cookie' => self::REDACTED,
        'password' => self::REDACTED,
        'password_confirmation' => self::REDACTED,
        'current_password' => self::REDACTED,
        'new_password' => self::REDACTED,
        'secret' => self::REDACTED,
        'client_secret' => self::REDACTED,
        'app_secret' => self::REDACTED,
        'webhook_secret' => self::REDACTED,
        'signing_secret' => self::REDACTED,
        'private_key' => self::REDACTED,
        'encrypted_key' => self::REDACTED,
        'signature' => self::REDACTED,
        'x_signature' => self::REDACTED,

        // 令牌
        'token' => '[redacted_token]',
        'api_token' => '[redacted_token]',
        'access_token' => '[redacted_token]',
        'refresh_token' => '[redacted_token]',
        'auth_token' => '[redacted_token]',
        'id_token' => '[redacted_token]',
        'bearer_token' => '[redacted_token]',
        'csrf_token' => '[redacted_token]',
        'remember_token' => '[redacted_token]',
        'personal_access_token' => '[redacted_token]',
        'plain_text_token' => '[redacted_token]',

        // 密钥
        'api_key' => '[redacted_secret]',
        'apikey' => '[redacted_secret]',
        'gateway_key' => '[redacted_secret]',
        'platform_key' => '[redacted_secret]',
        'provider_api_key' => '[redacted_secret]',
        'openai_api_key' => '[redacted_secret]',

        // 联系方式
        'phone' => '[redacted_phone]',
        'mobile' => '[redacted_phone]',
        'contact_phone' => '[redacted_phone]',
        'phone_number' => '[redacted_phone]',
        'tel' => '[redacted_phone]',
        'email' => '[redacted_email]',
        'contact_email' => '[redacted_email]',
        'user_email' => '[redacted_email]',

        // 账本 / 身份 ID：换指纹（可对账，不可反查）
        'account_user_id' => self::FINGERPRINT,
        'mengde_user_id' => self::FINGERPRINT,
        'shared_auth_id' => self::FINGERPRINT,
        'wallet_id' => self::FINGERPRINT,
        'wallet_user_id' => self::FINGERPRINT,
        'wallet_internal_id' => self::FINGERPRINT,
        'external_transaction_id' => self::FINGERPRINT,
        'transaction_id' => self::FINGERPRINT,
        'idempotency_key' => self::FINGERPRINT,
        'points_deduction_idempotency_key' => self::FINGERPRINT,
        'session_id' => self::FINGERPRINT,
    ];

    /**
     * Monolog 处理器入口（`__invoke` 形式，与框架自带的 processor 同一个约定）。
     *
     * 两种入参都要认：Monolog 3 的 handler/processor 约定传 `LogRecord` 对象，
     * 而直接调用（测试、以及将来若退回 Monolog 2）传的是数组。少认一种的后果不是报错，
     * 是**这条通道的脱敏悄悄不生效** —— 所以这里显式分叉，并且测试把两条路都走一遍。
     *
     * @param  array<string, mixed>|LogRecord  $record
     * @return array<string, mixed>|LogRecord
     */
    public function __invoke(array|LogRecord $record): array|LogRecord
    {
        if ($record instanceof LogRecord) {
            try {
                return $record->with(
                    message: self::redactText((string) $record->message),
                    context: $this->redactArray($record->context),
                    extra: $this->redactArray($record->extra),
                );
            } catch (\Throwable) {
                return $record->with(message: '[log-redaction-failed]', context: [], extra: []);
            }
        }

        return $this->redactArrayRecord($record);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function redactArrayRecord(array $record): array
    {
        try {
            if (isset($record['message']) && is_string($record['message'])) {
                $record['message'] = self::redactText($record['message']);
            }

            foreach (['context', 'extra'] as $field) {
                if (isset($record[$field]) && is_array($record[$field])) {
                    $record[$field] = $this->redactArray($record[$field]);
                }
            }
        } catch (\Throwable) {
            // 失败时**闭口**：宁可丢这一条日志的内容，也不能因为脱敏器出问题就把原文写出去。
            $record['message'] = '[log-redaction-failed]';
            $record['context'] = [];
            $record['extra'] = [];
        }

        return $record;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function redactArray(array $value): array
    {
        foreach ($value as $key => $item) {
            $label = is_string($key) ? self::labelFor($key) : null;

            if ($label !== null) {
                $value[$key] = $this->mask($label, $item);

                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->redactArray($item);

                continue;
            }

            // 键名不敏感也要扫值：上游回包/异常消息里的凭据是靠特征抓的。
            if (is_string($item)) {
                $value[$key] = self::redactText($item);
            }
            // 对象（异常实例等）保持原样：Monolog 自己会格式化，替换反而可能炸。
        }

        return $value;
    }

    private function mask(string $label, mixed $value): mixed
    {
        if ($label !== self::FINGERPRINT) {
            return $label;
        }

        if (! is_string($value) && ! is_int($value)) {
            return self::REDACTED;
        }

        return OperationalTextRedactor::fingerprintIdentifier((string) $value) ?? self::REDACTED;
    }

    private static function labelFor(string $key): ?string
    {
        $normalized = strtolower(str_replace('-', '_', trim($key)));

        return self::SENSITIVE_KEYS[$normalized] ?? null;
    }

    /**
     * 文本级脱敏。
     *
     * 不走 `redact()` 的默认截断（`truncate: true` 会把长日志砍到 240 字）——
     * 那是给运营看板上的短字段用的口径，套到日志上等于把排障信息删掉一半；
     * 空串也原样返回（`redact()` 会把 '' 变成 null，凭空改掉值的类型）。
     */
    private static function redactText(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        return OperationalTextRedactor::redact($text, truncate: false) ?? $text;
    }
}
