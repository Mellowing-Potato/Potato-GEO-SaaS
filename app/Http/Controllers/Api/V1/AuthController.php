<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\AccountDeletionService;
use App\Services\Auth\LocalAccounts;
use App\Support\InstanceConfiguration;
use App\Support\LocalSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController extends Controller
{
    public function __construct(private readonly AccountDeletionService $accountDeletion) {}

    private function assertOrigin(Request $request, bool $requireJson = true): void
    {
        $origin = $request->headers->get('Origin');
        abort_if(($requireJson && !$request->isJson()) || ($origin && $origin !== rtrim((string) config('app.url'), '/')), 403, '请从本实例的登录页提交。');
    }

    private function email(Request $request): void
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
    }

    public function productLogin(Request $request): JsonResponse
    {
        $this->assertOrigin($request);
        $this->email($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            'terms_accepted' => [InstanceConfiguration::agreementsRequired() ? 'accepted' : 'sometimes'],
        ]);
        return DB::transaction(function () use ($request, $data) {
            $user = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$data['email']])->lockForUpdate()->first();
            if (!$user?->password || !Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => ['邮箱或密码不正确。']]);
            }
            LocalAccounts::assertActive($user);
            abort_if($user->deletion_purge_at?->isPast(), 403, '账户注销期限已过，无法恢复。');
            $restored = $this->accountDeletion->cancelIfRequested($user, (string) $request->ip());
            $response = LocalSession::issue($user, $request);
            $response->setData([...$response->getData(true), 'account_restored' => $restored !== null,
                'account_restored_at' => $restored['cancelled_at'] ?? null]);
            return $response;
        });
    }

    public function register(Request $request, LocalAccounts $accounts): JsonResponse
    {
        $this->assertOrigin($request);
        $this->email($request);
        return DB::transaction(function () use ($request, $accounts) {
            $settings = DB::table('instance_settings')->where('id', 1)->lockForUpdate()->first();
            abort_unless($settings?->registration_enabled, 403, '本实例尚未开放注册，请联系管理员。');
            $data = $request->validate([
                'email' => ['required', 'email', 'max:254', 'unique:users,email'],
                'name' => ['required', 'string', 'max:80'],
                'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
                'terms_accepted' => [InstanceConfiguration::agreementsRequired() ? 'accepted' : 'sometimes'],
            ]);
            return LocalSession::issue($accounts->create($data['email'], $data['password'], $data['name']), $request);
        });
    }

    public function sessionLogin(Request $request): JsonResponse
    {
        $this->assertOrigin($request, false);
        return DB::transaction(function () use ($request) {
            $user = LocalSession::userFor($request);
            // Serialize renewal with password changes/revocation for this user.
            $user = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
            return LocalSession::issue($user, $request, LocalSession::valueFromRequest($request));
        });
    }

    public function logout(Request $request): JsonResponse
    {
        LocalSession::revoke($request->user(), LocalSession::fingerprintFromRequest($request));
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => '已退出登录'])->withCookie(new Cookie('geo_session', '', 1, '/', null, $request->isSecure(), true, false, 'lax'));
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($request->isMethod('PATCH')) {
            $this->email($request);
            $data = $request->validate(['name' => ['required', 'string', 'max:80'],
                'email' => ['required', 'email', 'max:254', \Illuminate\Validation\Rule::unique('users')->ignore($user->id)],
                'current_password' => ['nullable', 'string']]);
            $user = DB::transaction(function () use ($user, $data) {
                $user = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
                if ($data['email'] !== $user->email) {
                    if (!Hash::check($data['current_password'] ?? '', $user->password)) {
                        throw ValidationException::withMessages(['current_password' => ['修改登录邮箱需要当前密码。']]);
                    }
                    // Reset links belong to the current email owner, not a future account
                    // that registers the released address.
                    DB::table('password_reset_tokens')->whereIn('email', [$user->email, $data['email']])->delete();
                    $user->email_verified_at = null;
                }
                $user->forceFill(['name' => trim($data['name']), 'email' => $data['email']])->save();
                return $user;
            });
        }
        return response()->json(['user' => $user, 'has_password' => filled($user->password)]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);
        return DB::transaction(function () use ($request, $data) {
            $user = User::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->user()->id);
            if (!Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['当前密码不正确。']]);
            }
            $user->forceFill(['password' => $data['password']])->save();
            LocalSession::revokeAll($user);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            return LocalSession::issue($user, $request);
        });
    }

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = LocalSession::fingerprintFromRequest($request);
        if ($request->isMethod('POST')) {
            return DB::transaction(function () use ($user, $current) {
                User::withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
                $others = DB::table('local_browser_sessions')->where('user_id', $user->id)->where('fingerprint', '!=', $current)->pluck('fingerprint');
                foreach ($others as $fingerprint) LocalSession::revoke($user, $fingerprint);
                return response()->json(['revoked_count' => $others->count()]);
            });
        }
        $rows = DB::table('local_browser_sessions')->where('user_id', $user->id)->where('expires_at', '>', now())
            ->orderBy('created_at')->get()->map(fn ($s) => [
                'current' => hash_equals($s->fingerprint, $current),
                'created_at' => \Illuminate\Support\Carbon::parse($s->created_at)->toISOString(),
                'expires_at' => \Illuminate\Support\Carbon::parse($s->expires_at)->toISOString(),
            ]);
        return response()->json(['data' => $rows]);
    }

    public static function mailResetAvailable(): bool
    {
        return in_array(config('mail.default'), ['smtp', 'sendmail', 'ses', 'postmark', 'resend'], true);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->assertOrigin($request);
        abort_unless(self::mailResetAvailable(), 503, '本实例未配置邮件服务，请联系管理员重置密码。');
        $this->email($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        Password::sendResetLink($data);
        return response()->json(['message' => '如果邮箱已注册，将收到密码重置邮件。']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $this->assertOrigin($request);
        $this->email($request);
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed']]);
        $status = DB::transaction(function () use ($data) {
            // Lock before broker validation so a reset token cannot be consumed twice.
            $user = User::withoutGlobalScopes()->where('email', $data['email'])->lockForUpdate()->first();
            if ($user) LocalAccounts::assertActive($user);
            return Password::reset($data, function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => null])->save();
                LocalSession::revokeAll($user);
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => ['重置链接无效或已过期，请重新申请。']]);
        }
        return response()->json(['message' => '密码已重置，请重新登录。']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant');
        return response()->json(['authenticated' => true, 'user' => $user, 'tenant' => $user->tenant,
            'account_user' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email]]);
    }

    /**
     * GET /api/v1/account/deletion
     * 注销申请状态（个人设置页读它显示冷静期倒计时）。
     */
    public function deletionStatus(Request $request): JsonResponse
    {
        $user = User::withoutGlobalScopes()->findOrFail($request->user()->id);

        return response()->json(['data' => $this->accountDeletion->status($user)]);
    }

    /**
     * POST /api/v1/account/deletion
     * 提交注销申请（用户口径 2026-09-21：申请 + 15 天冷静期，期内登录即恢复）。
     *
     * 二次确认是硬要求：注销是不可逆的终点，一次误点不该走到那一步。
     *  - 文案确认（confirm_text = 注销账户）：所有账号都必须过这一关；
     *  - 密码确认：设置过密码的账号还要再验一次密码（照 password() 的做法，
     *    没设密码的账号用不了这条，别把登录方式不同的客户挡在门外）。
     */
    public function requestDeletion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirm_text' => ['required', 'in:注销账户'],
            'password' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $user = User::withoutGlobalScopes()->findOrFail($request->user()->id);

        if (filled($user->password) && ! Hash::check((string) ($data['password'] ?? ''), $user->password)) {
            throw ValidationException::withMessages(['password' => ['请填写当前登录密码以确认注销。']]);
        }

        $result = $this->accountDeletion->request($user, $data['reason'] ?? null, (string) $request->ip());

        return response()->json([
            'message' => sprintf(
                '注销申请已提交，账号已从所有设备退出。%d 天内重新登录即可恢复账户，逾期将无法恢复。',
                $result['grace_days'],
            ),
            'data' => $result,
        ]);
    }

}
