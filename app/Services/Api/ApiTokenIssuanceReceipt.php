<?php

namespace App\Services\Api;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/** Persist issuance identity, never a second copy of a bearer credential. */
final class ApiTokenIssuanceReceipt
{
    public const ROUTE = 'api/v1/api-tokens';

    public static function matches(string $route): bool
    {
        return trim($route, '/') === self::ROUTE;
    }

    public static function withoutSecret(array $payload): array
    {
        if (($payload['object'] ?? null) !== 'api_token' && ! array_key_exists('token', $payload)) {
            return $payload;
        }

        $record = is_array($payload['record'] ?? null) ? $payload['record'] : [];

        return [
            'object' => 'api_token',
            'record' => array_intersect_key($record, array_flip([
                'id', 'name', 'scopes', 'status', 'tokenable_id',
                'last_used_at', 'expires_at', 'created_at', 'updated_at',
            ])),
            'token_available' => false,
            'action' => 'api_token_already_issued',
            'message' => '令牌已创建，明文仅在首次签发时显示。若未保存，请吊销该令牌后重新创建。',
        ];
    }

    /** A receipt is not proof that the previously issued credential is still active. */
    public static function replay(array $payload, int $status, ?int $tenantId, ?int $userId): array
    {
        if ($status !== 201 || ($payload['object'] ?? null) !== 'api_token') {
            return ['status' => $status, 'payload' => $payload];
        }

        $ownerExists = $tenantId !== null && $userId !== null
            && User::query()->whereKey($userId)->where('tenant_id', $tenantId)->exists();
        $token = $ownerExists ? PersonalAccessToken::query()
            ->whereKey($payload['record']['id'] ?? 0)
            ->where('tokenable_type', User::class)->where('tokenable_id', $userId)->first() : null;

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            $payload['record']['status'] = 'unavailable';
            $payload['action'] = 'api_token_unavailable';
            $payload['message'] = '该次签发的令牌已不可用；本次未创建新令牌，请核对令牌列表后再操作。';

            return ['status' => 410, 'payload' => $payload];
        }

        $payload['record']['status'] = 'active';
        $payload['record']['expires_at'] = $token->expires_at?->format('Y-m-d H:i:s');

        return ['status' => $status, 'payload' => $payload];
    }
}
