<?php

namespace App\Support;

class OperationalTextRedactor
{
    private const OPERATIONAL_TEXT_KEYS = [
        'error_message',
        'points_deduction_failure_reason',
    ];

    private const SENSITIVE_VALUE_KEYS = [
        'account_user_id',
        'email',
        'external_transaction_id',
        'idempotency_key',
        'mengde_user_id',
        'phone',
        'points_deduction_idempotency_key',
        'shared_auth_id',
        'transaction_id',
        'wallet_id',
        'wallet_internal_id',
        'wallet_user_id',
    ];

    public static function redact(?string $value, bool $truncate = true): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $replacements = [
            '/(["\']?\b(?:external[_-]?transaction[_-]?id|transaction[_-]?id|idempotency[_-]?key|points[_-]?deduction[_-]?idempotency[_-]?key)\b["\']?\s*[:=]\s*["\']?)[^,\s"\'}]+/i' => '$1[redacted_id]',
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[redacted_email]',
            '/\b1[3-9]\d{9}\b/' => '[redacted_phone]',
            '/\b(Bearer)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 [redacted_token]',
            '/\b(api[_-]?key|token|secret|password)\s*[:=]\s*["\']?[^,\s"\']+/i' => '$1=[redacted_secret]',
            '/\b(?:sk|pk)-[A-Za-z0-9_\-]{8,}\b/i' => '[redacted_secret]',
            '/\b(account[_-]?user[_-]?id|mengde[_-]?user[_-]?id|shared[_-]?auth[_-]?id|wallet[_-]?id|wallet[_-]?user[_-]?id)\s*[:=]\s*["\']?[^,\s"\']+/i' => '$1=[redacted_id]',
            '/\b(external[_-]?transaction[_-]?id|transaction[_-]?id|idempotency[_-]?key|points[_-]?deduction[_-]?idempotency[_-]?key)\s*[:=]\s*["\']?[^,\s"\']+/i' => '$1=[redacted_id]',
            '/\bqh_(?:geo|txn)[A-Za-z0-9_\-]{4,}\b/i' => '[redacted_id]',
            '/\bgeo-model-call-[A-Za-z0-9_\-]+\b/i' => '[redacted_id]',
            '/\bacct[_-][A-Za-z0-9_\-]{4,}\b/i' => '[redacted_account_id]',
            '/\bwallet[_-](?!user[_-]?id\b|id\b)[A-Za-z0-9_\-]{4,}\b/i' => '[redacted_wallet_id]',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $truncate && mb_strlen($text) > 240 ? mb_substr($text, 0, 237).'...' : $text;
    }

    public static function fingerprintIdentifier(?string $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return 'sha256:'.substr(hash('sha256', $text), 0, 12);
    }

    public static function redactOperationalFields(array $value): array
    {
        foreach ($value as $key => $item) {
            $normalizedKey = strtolower(str_replace('-', '_', (string) $key));

            if (is_array($item)) {
                $value[$key] = self::redactOperationalFields($item);
                continue;
            }

            if (in_array((string) $key, self::OPERATIONAL_TEXT_KEYS, true) && is_string($item)) {
                $value[$key] = self::redact($item);
                continue;
            }

            if (in_array($normalizedKey, self::SENSITIVE_VALUE_KEYS, true) && filled($item)) {
                $value[$key] = str_contains($normalizedKey, 'email')
                    ? '[redacted_email]'
                    : (str_contains($normalizedKey, 'phone') ? '[redacted_phone]' : '[redacted_id]');
            }
        }

        return $value;
    }
}
