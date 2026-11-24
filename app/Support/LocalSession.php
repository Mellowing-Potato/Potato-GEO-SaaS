<?php

namespace App\Support;

use App\Models\User;
use App\Services\Auth\LocalAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final class LocalSession
{
    public static function cookieName(): string
    {
        $name = (string) config('instance.browser_cookie_name', 'geo_session');
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,79}\z/', $name) !== 1) {
            throw new \InvalidArgumentException('实例浏览器 Cookie 名称无效。');
        }
        return $name;
    }

    public static function valueFromRequest(Request $request): string
    {
        $name = self::cookieName();
        foreach (explode(';', (string) $request->headers->get('cookie', '')) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if (($parts[0] ?? '') === $name) return $parts[1] ?? '';
        }
        return (string) $request->cookies->get($name, '');
    }

    public static function fingerprintFromRequest(Request $request): string
    {
        $raw = self::valueFromRequest($request);
        return $raw === '' ? '' : hash('sha256', $raw);
    }

    public static function userFor(Request $request): User
    {
        $session = DB::table('local_browser_sessions')
            ->where('fingerprint', self::fingerprintFromRequest($request))
            ->where('expires_at', '>', now())->first();
        abort_unless($session, 401, '请重新登录。');
        $user = User::withoutGlobalScopes()->find($session->user_id);
        abort_unless($user, 401, '请重新登录。');
        LocalAccounts::assertActive($user);
        return $user;
    }

    public static function issue(User $user, Request $request, ?string $existingCookie = null): \Illuminate\Http\JsonResponse
    {
        $name = self::cookieName();
        LocalAccounts::assertActive($user);
        $cookie = $existingCookie ?? Str::random(64);
        $fingerprint = hash('sha256', $cookie);
        $expires = now()->addDays(14);
        if ($existingCookie === null) {
            DB::table('local_browser_sessions')->insert([
                'fingerprint' => $fingerprint, 'user_id' => $user->id,
                'created_at' => now(), 'expires_at' => $expires,
            ]);
        } else {
            $session = DB::table('local_browser_sessions')->where('fingerprint', $fingerprint)
                ->where('user_id', $user->id)->where('expires_at', '>', now())->first();
            abort_unless($session, 401, '请重新登录。');
            $expires = \Illuminate\Support\Carbon::parse($session->expires_at);
        }
        $token = $user->createToken('geo-saas-token', ['*'], $expires);
        $token->accessToken->forceFill(['session_fingerprint' => $fingerprint])->save();
        $user->tokens()->where('name', 'like', 'geo-saas-token%')->whereNull('session_fingerprint')->delete();
        $user->load('tenant');
        return response()->json([
            'token' => $token->plainTextToken, 'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer', 'user' => $user, 'tenant' => $user->tenant,
            'account_user' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email],
        ])->withCookie(new Cookie($name, $cookie, $expires, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    public static function revokeAll(User $user): void
    {
        DB::table('local_browser_sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
    }

    public static function revoke(User $user, string $fingerprint): void
    {
        DB::table('local_browser_sessions')->where('user_id', $user->id)->where('fingerprint', $fingerprint)->delete();
        $user->tokens()->where('session_fingerprint', $fingerprint)->delete();
    }
}
