<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Api\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 作用域 API Token 管理控制器（阶段 4 缺口补齐：复刻 GEOFlow ApiTokenController）。
 *
 * 自建自管：当前登录的租户成员可签发/查看/吊销自己的作用域 Token；
 * 列表与吊销按 tokenable（当前用户）隔离，无法看到或操作他人 Token。
 * 路由级权限由 can:team:manage 控制（团队管理员管理集成令牌）。
 */
use App\Support\Concerns\RecordsSensitiveAction;
class ApiTokenController extends Controller
{
    use RecordsSensitiveAction;

    public function __construct(
        private readonly ApiTokenService $apiTokenService
    ) {}

    /**
     * 当前用户自己的 Token 列表 + 可用 scope 清单。
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        return response()->json([
            'object' => 'api_token_list',
            'data' => $this->apiTokenService->listTokensForUser($user),
            'available_scopes' => $this->apiTokenService->getAvailableScopes(),
            'default_expires_at' => $this->apiTokenService->defaultExpiresAtInputValue(),
        ]);
    }

    /**
     * 创建作用域 Token（明文仅在此一次性回显）。
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string'],
            'expires_at' => ['nullable', 'string', 'max:32'],
        ]);

        // ValidationException / HttpException 由 Laravel 异常处理器渲染为 422 / 500
        $created = $this->apiTokenService->createToken(
            $user,
            (string) $validated['name'],
            $validated['scopes'],
            $validated['expires_at'] ?? null
        );

        // 敏感操作审计（计划 §9.7）：令牌是凭据，发出去必须留痕。
        // ⚠️ 明细里**不放令牌明文**（那等于把凭据抄进审计表）—— 只记作用域与过期时间。
        $this->auditSensitive('api_token.created', null, [
            'token_id' => (int) ($created['record']['id'] ?? 0),
            'name' => (string) $validated['name'],
            'scopes' => $validated['scopes'],
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'object' => 'api_token',
            'token' => $created['token'],
            'record' => $created['record'],
        ], 201);
    }

    /**
     * 吊销当前用户自己的 Token。
     */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $user = $this->requireUser($request);

        // NotFoundHttpException 由 Laravel 异常处理器渲染为 404
        $this->apiTokenService->revokeTokenForUser($user, $id);
        $this->auditSensitive('api_token.revoked', null, ['token_id' => $id]);

        return response()->json(['message' => 'Token 已吊销。']);
    }

    private function requireUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401, '未认证');
        }

        return $user;
    }
}
