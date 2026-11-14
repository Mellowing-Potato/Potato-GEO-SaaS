<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Services/Api/ApiTokenService.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Api;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 作用域 API Token 服务（复刻 GEOFlow ApiTokenService，适配 geo-saas 多租户）。
 *
 * 与 GEOFlow 的差异：
 * - GEOFlow 以 Admin 为 tokenable（平台超管全局签发）；geo-saas 以租户成员 User 为 tokenable，
 *   且为「自建自管」模式：列表/吊销仅限当前登录用户自己的 Token（成员隔离）。
 * - scopes 词汇对齐 geo-saas 资源权限（articles / materials / projects / tasks / analytics…）。
 *
 * 外部调用方持作用域 Token 调用租户 API，由 EnsureApiScope 中间件按 scope 强制授权。
 */
class ApiTokenService
{
    /**
     * 当前用户自己的 Token 列表（按创建时间倒序）。
     *
     * @return list<array<string,mixed>>
     */
    public function listTokensForUser(User $user): array
    {
        /** @var Collection<int, PersonalAccessToken> $rows */
        $rows = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return $rows->map(fn (PersonalAccessToken $row): array => $this->hydrate($row))->all();
    }

    /**
     * 撤销指定 Token（仅允许撤销当前用户自己的 Token）。
     */
    public function revokeTokenForUser(User $user, int $tokenId): void
    {
        $affected = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->whereKey($tokenId)
            ->delete();

        if ($affected !== 1) {
            throw new NotFoundHttpException('Token 不存在或不属于当前用户');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveTokenByPlaintext(string $plainToken): ?array
    {
        $row = PersonalAccessToken::findToken($plainToken);

        if (! $row) {
            return null;
        }

        if ($row->tokenable_type !== User::class) {
            return null;
        }

        if ($row->expires_at && $row->expires_at->isPast()) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @param  array<string, mixed>  $token
     */
    public function tokenHasScope(array $token, string $scope): bool
    {
        $scopes = $token['scopes'] ?? [];

        return in_array('*', $scopes, true) || in_array($scope, $scopes, true);
    }

    public function defaultExpiresAt(): Carbon
    {
        $days = max(1, (int) config('geo.api_token_default_ttl_days', 30));

        return now()->addDays($days);
    }

    public function defaultExpiresAtInputValue(): string
    {
        return $this->defaultExpiresAt()->format('Y-m-d\TH:i');
    }

    /**
     * @param  list<string>  $scopes
     * @return array{token: string, record: array<string, mixed>}
     */
    public function createToken(User $user, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Token 名称不能为空']]);
        }

        // 保留名护栏：geo-saas-token 是壳页 session-login 专用、由服务端按
        // gateway_session 指纹签发的会话 token 名。用户若用自己的 API token 也起这名，
        // 会被 EnforceAccountSessionBinding 当成会话 token 拦掉、且因无浏览器 cookie 永远 401。
        // 直接禁止，报错文案写清楚，见 docs/GEO-会话身份绑定-工兵执行书-2026-08-31.md §四之四。
        if ($name === 'geo-saas-token' || $name === 'geo-saas-token-direct') {
            throw ValidationException::withMessages([
                'name' => ['该名称是系统保留名，不可用于自管 API Token，请换一个名字'],
            ]);
        }

        $scopes = $this->normalizeScopes($scopes);
        if ($scopes === []) {
            throw ValidationException::withMessages(['scopes' => ['至少选择一个 scope']]);
        }

        $expires = $this->normalizeExpiresAt($expiresAt);

        $tokenResult = $user->createToken(
            $name,
            array_values($scopes),
            Carbon::parse($expires)
        );
        $model = $tokenResult->accessToken->fresh();
        if (! $model instanceof PersonalAccessToken) {
            throw new HttpException(500, 'Token 创建失败');
        }

        return [
            'token' => $tokenResult->plainTextToken,
            'record' => $this->hydrate($model),
        ];
    }

    /**
     * @return list<string>
     */
    public function getAvailableScopes(): array
    {
        return [
            'articles:read',
            'articles:write',
            'articles:publish',
            'materials:read',
            'materials:write',
            'projects:read',
            'projects:write',
            'tasks:read',
            'tasks:write',
            'categories:read',
            'categories:write',
            'analytics:read',
            'knowledge:read',
            'knowledge:write',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hydrate(PersonalAccessToken $row): array
    {
        $scopes = $row->abilities;
        if (! is_array($scopes)) {
            $scopes = [];
        }

        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'token_hash' => '',
            'scopes' => $scopes,
            'status' => 'active',
            'tokenable_id' => $row->tokenable_id !== null ? (int) $row->tokenable_id : null,
            'last_used_at' => $row->last_used_at?->format('Y-m-d H:i:s'),
            'expires_at' => $row->expires_at?->format('Y-m-d H:i:s'),
            'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $row->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeScopes(array $scopes): array
    {
        $allowed = $this->getAvailableScopes();
        $normalized = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope !== '' && in_array($scope, $allowed, true)) {
                $normalized[] = $scope;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeExpiresAt(?string $expiresAt): string
    {
        $expiresAt = $expiresAt !== null ? trim($expiresAt) : null;
        if ($expiresAt === null || $expiresAt === '') {
            return $this->defaultExpiresAt()->format('Y-m-d H:i:s');
        }

        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            throw ValidationException::withMessages(['expires_at' => ['过期时间格式无效']]);
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
