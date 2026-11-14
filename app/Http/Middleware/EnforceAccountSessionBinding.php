<?php

namespace App\Http\Middleware;

use App\Support\LocalSession;
use App\Support\IndependentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Browser tokens require both the issuing cookie fingerprint and a live local session.
 * Full-access tokens and reserved session names are bound by default. Scoped machine
 * tokens use EnforceMachineTokenScope instead. Definitive revocation or an identity
 * mismatch deletes the browser token; database errors fail closed without deletion.
 */
class EnforceAccountSessionBinding
{
    /**
     * 保留名前缀：AuthController 签发的浏览器会话 token 都以此开头
     * （geo-saas-token / geo-saas-token-direct），ApiTokenService 明令禁止用户占用。
     */
    private const SESSION_TOKEN_PREFIX = 'geo-saas-token';

    public function handle(Request $request, Closure $next)
    {
        $bearer = $request->bearerToken();
        if ($bearer === null) {
            return $next($request);
        }

        $token = PersonalAccessToken::findToken($bearer);
        if ($token === null) {
            return $next($request);
        }

        if (! $this->mustBeBound($token)) {
            return $next($request);
        }

        if ($token->expires_at?->isPast()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $fp = LocalSession::fingerprintFromRequest($request);
        $stored = (string) $token->session_fingerprint;

        if ($fp === '' || $stored === '' || ! hash_equals($stored, $fp)) {
            $token->delete();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $sessionUser = LocalSession::userFor($request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 401) $token->delete();
            throw $e;
        }
        if ((int) $token->tokenable_id !== (int) $sessionUser->id || !$token->tokenable instanceof \App\Models\User) {
            $token->delete();
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        IndependentWorkspace::assertFor($token->tokenable);

        return $next($request);
    }

    /**
     * 默认拦截：满权限 token、或占用会话保留名的 token，都必须绑会话。
     * 只有「作用域受限的机器 API token」才豁免。
     */
    private function mustBeBound(PersonalAccessToken $token): bool
    {
        $abilities = $token->abilities;
        if (is_array($abilities) && in_array('*', $abilities, true)) {
            return true;
        }

        return str_starts_with((string) $token->name, self::SESSION_TOKEN_PREFIX);
    }
}
