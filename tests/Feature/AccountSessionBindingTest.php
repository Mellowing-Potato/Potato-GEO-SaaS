<?php

namespace Tests\Feature;

use App\Services\Api\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * 会话身份绑定（B）· 服务端闸门回归测试
 *
 * 覆盖执行书 §六 PHP 要求的 8 条 + 保留名护栏（ApiTokenService）：
 *   1. session-login 发出的 token，行上的 session_fingerprint = 该次 cookie 的 sha256
 *   2. 带同一张 cookie 请求 → 200
 *   3. 不带 cookie 请求 → 401（退出登录后旧标签页继续用）
 *   4. 带另一张 cookie 请求 → 401，且该 token 行已被删除（换账号）
 *   5. 机器 API token（另一个名字、无 cookie）→ 不受影响，照常 200
 *   6. 同一会话第二次 session-login（同一 cookie）→ 前一张 token 仍然有效（多标签页不互相踢）
 *   7. 不同会话 session-login → 两张 token 各自绑各自的会话、都保留（多设备同时在线）
 *   8. token 过期（expires_at 拨到过去）→ 401
 *   9. ApiTokenService 禁止把 token 起名叫 geo-saas-token（保留名护栏）
 *  10. 登录时清掉「无指纹」的历史 token（上线前签发的死数据）
 *
 * 探针端点用 GET /api/v1/auth/me（只挂 auth:sanctum，不需要租户头），
 * EnforceAccountSessionBinding 挂在 api 组、对所有 /api/* 生效，因此也会跑这道闸。
 */
class AccountSessionBindingTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = 'acct_binding_user_001';

    private function fakeAccount(string $cookieValue, string $userId = self::USER_ID): void
    {
        Http::preventStrayRequests();
        $email = $userId.'@example.com';
        $user = \App\Models\User::withoutGlobalScopes()->where('email', $email)->first()
            ?? app(\App\Services\Auth\LocalAccounts::class)->create($email, 'test-password-1', 'Binding User');
        \Illuminate\Support\Facades\DB::table('local_browser_sessions')->updateOrInsert(
            ['fingerprint' => hash('sha256', $cookieValue)],
            ['user_id' => $user->id, 'created_at' => now(), 'expires_at' => now()->addDays(14)]
        );
    }

    private function login(string $cookieValue, string $userId = self::USER_ID): array
    {
        $this->fakeAccount($cookieValue, $userId);

        $response = $this
            ->withHeader('Cookie', "geo_session={$cookieValue}")
            ->postJson('/api/v1/auth/session-login');

        $response->assertOk();

        $token = (string) $response->json('token');
        $id = (int) explode('|', $token)[0];

        return ['token' => $token, 'id' => $id];
    }

    private function me(string $token, ?string $cookie = null)
    {
        // ⚠️ 必须显式覆盖 Cookie 头：Laravel 测试客户端的 withHeader 是持久化默认头，
        // login() 里设的 geo_session 会被后续请求继承，造成「无 cookie」场景假阳性。
        // 无 cookie 场景传空串 → 请求不带 geo_session → 指纹为空。
        // 🔴 必须先清 guard 缓存：Laravel 测试客户端在同一个测试方法内不重启容器，
        // auth guard 会把上一次请求解析出的用户记住，导致「token 已被闸门删掉、第二个请求
        // 却仍返回 200」的假绿（实测过：删掉 token 后再请求一次拿到 200 + 完整用户信息）。
        // 生产每个请求都是全新容器，没有这个缓存，所以不清就等于在测一个不存在的场景。
        $this->app['auth']->forgetGuards();

        $cookieHeader = $cookie === null ? '' : "geo_session={$cookie}";
        $req = $this->withToken($token)->withHeader('Cookie', $cookieHeader);

        return $req->getJson('/api/v1/auth/me');
    }

    private function tokenRecord(int $id): ?PersonalAccessToken
    {
        return PersonalAccessToken::find($id);
    }

    public function test_issued_token_stores_session_fingerprint(): void
    {
        $session = $this->login('test-fake');

        $record = $this->tokenRecord($session['id']);
        $this->assertNotNull($record);
        $this->assertSame('geo-saas-token', $record->name);
        $this->assertSame(hash('sha256', 'test-fake'), $record->session_fingerprint);
    }

    public function test_same_cookie_is_allowed(): void
    {
        $session = $this->login('test-fake');

        $this->me($session['token'], 'test-fake')->assertOk();
    }

    public function test_missing_cookie_is_rejected(): void
    {
        $session = $this->login('test-fake');

        $this->me($session['token'], null)->assertStatus(401);
    }

    public function test_different_cookie_deletes_token(): void
    {
        $session = $this->login('test-fake');

        // 换账号（cookie 变了）→ 比对失败 → 401，且这张 token 被删
        $this->me($session['token'], 'session-B')->assertStatus(401);
        $this->assertNull($this->tokenRecord($session['id']), '换账号后旧 token 应被删除');
    }

    public function test_machine_api_token_not_bound(): void
    {
        $session = $this->login('test-fake');
        $user = $this->tokenRecord($session['id'])->tokenable;

        // 机器 API token：作用域受限、无浏览器 cookie，闸门放行。
        // ⚠️ 这里必须用真实的 scope，不能写 ['*']：ApiTokenService::normalizeScopes 拿
        // getAvailableScopes() 白名单过滤，用户自管 token 永远拿不到 '*'。闸门改成默认拦截
        // 之后，'*' 就是「必须绑会话」的判据，拿 ['*'] 造机器 token 会测出一个现实中不存在的场景。
        $machine = $user->createToken('my-machine-token', ['articles:read'], now()->addDay());
        $machineToken = $machine->plainTextToken;

        $this->me($machineToken, null)->assertOk();
    }

    public function test_star_ability_token_must_be_bound_whatever_its_name(): void
    {
        $session = $this->login('test-fake');
        $user = $this->tokenRecord($session['id'])->tokenable;

        // 🔴 评审发现 C 的根治回归：闸门判据是「满权限就必须绑」，不是「名字等于 geo-saas-token」。
        // 名字随便起、abilities 给 '*'、不带 cookie —— 旧的黑名单式闸门会直接放行，这是绕过口。
        $sneaky = $user->createToken('totally-unrelated-name', ['*'], now()->addDay());

        $this->me($sneaky->plainTextToken, null)->assertStatus(401);
        $this->assertNull(
            $this->tokenRecord((int) $sneaky->accessToken->getKey()),
            '满权限却没绑会话的 token 应被闸门删掉'
        );
    }

    public function test_direct_login_token_cannot_bypass_the_gate(): void
    {
        $session = $this->login('test-fake');
        $user = $this->tokenRecord($session['id'])->tokenable;

        // register-phone / login-phone-code 走服务端到服务端登录，浏览器拿不到 geo_session，
        // 签出来的就是这种「满权限、无指纹」的 token。改成默认拦截前它能横着走，现在必须 401。
        $direct = $user->createToken('geo-saas-token-direct', ['*'], now()->addHours(12));

        // 不带 cookie：闸门直接判死
        $this->me($direct->plainTextToken, null)->assertStatus(401);

        // 带一张**有效**的 geo_session cookie 也一样死：这张 token 行上的指纹是空的，
        // 空指纹永远匹配不上，不存在「借别人的 cookie 复活」这种事。
        $again = $user->createToken('geo-saas-token-direct', ['*'], now()->addHours(12));
        $this->me($again->plainTextToken, 'test-fake')->assertStatus(401);
    }

    public function test_duplicate_login_same_session_keeps_first_token(): void
    {
        $first = $this->login('test-fake');
        $second = $this->login('test-fake'); // 同一 cookie、同一用户

        // 多标签页：两张 token 指纹相同，前一张不应被后一张顶掉
        $this->me($first['token'], 'test-fake')->assertOk();
        $this->assertNotNull($this->tokenRecord($first['id']), '同会话二次登录不应清掉前一张 token');
        $this->me($second['token'], 'test-fake')->assertOk();
    }

    public function test_login_with_new_session_keeps_other_device_token(): void
    {
        $old = $this->login('test-fake');
        $new = $this->login('session-B'); // 同一用户、另一台设备（网关允许多会话并存）

        // 🔴 评审发现 A：不许清「指纹不同」的 token。清了会和前端 401 自愈组成
        // 「手机踢电脑、电脑踢手机」的无限互踢。两张 token 各自绑各自的会话，都该活着。
        $this->assertNotNull($this->tokenRecord($old['id']), '另一台设备的 token 不应被新会话清掉');
        $this->assertNotNull($this->tokenRecord($new['id']), '新会话 token 应保留');
        $this->me($old['token'], 'test-fake')->assertOk();
        $this->me($new['token'], 'session-B')->assertOk();
    }

    public function test_login_clears_legacy_token_without_fingerprint(): void
    {
        $session = $this->login('test-fake');
        $user = $this->tokenRecord($session['id'])->tokenable;

        // 模拟本批上线前签发的历史 token：名字对、但没有指纹，闸门本来就会一律 401
        $legacy = $user->createToken('geo-saas-token', ['*'], now()->addDay());
        $legacyId = (int) $legacy->accessToken->getKey();
        $this->assertNull($this->tokenRecord($legacyId)->session_fingerprint);

        $this->login('test-fake');

        $this->assertNull($this->tokenRecord($legacyId), '无指纹的历史 token 应在下次登录时清掉');
    }

    public function test_expired_token_is_rejected(): void
    {
        $session = $this->login('test-fake');
        $user = $this->tokenRecord($session['id'])->tokenable;

        // 直接造一张过期但指纹正确的 geo-saas-token
        $expired = $user->createToken('geo-saas-token', ['*'], now()->subHour());
        $expired->accessToken->forceFill([
            'session_fingerprint' => hash('sha256', 'test-fake'),
        ])->save();

        $this->me($expired->plainTextToken, 'test-fake')->assertStatus(401);
    }

    public function test_reserved_token_name_rejected_by_service(): void
    {
        $session = $this->login('test-fake');
        $user = $this->tokenRecord($session['id'])->tokenable;

        $service = new ApiTokenService;

        $this->expectException(ValidationException::class);
        $service->createToken(
            $user,
            'geo-saas-token',
            ['articles:read'],
            now()->addDay()->format('Y-m-d H:i:s')
        );
    }

    public function test_local_revocation_invalidates_old_cookie_and_token(): void
    {
        $session = $this->login('test-fake');
        $this->me($session['token'], 'test-fake')->assertOk();
        \Illuminate\Support\Facades\DB::table('local_browser_sessions')->where('fingerprint', hash('sha256', 'test-fake'))->delete();
        $this->me($session['token'], 'test-fake')->assertUnauthorized();
        $this->assertNull($this->tokenRecord($session['id']));
    }


    public function test_different_local_account_cannot_reuse_bound_token(): void
    {
        $session = $this->login('test-fake');
        $this->fakeAccount('test-fake', 'different-account');
        $this->me($session['token'], 'test-fake')->assertUnauthorized();
        $this->assertNull($this->tokenRecord($session['id']));
    }


    public function test_browser_session_can_still_create_project_and_machine_token(): void
    {
        $session = $this->login('test-fake');
        $user = $this->tokenRecord($session['id'])->tokenable;
        $this->withHeader('X-Tenant', $user->tenant->subdomain)->withToken($session['token']);
        $this->postJson('/api/v1/projects', ['name' => 'Browser project'])->assertCreated();
        $this->postJson('/api/v1/api-tokens', ['name' => 'Integration', 'scopes' => ['articles:read']])->assertCreated();
    }


    public function test_each_request_checks_local_revocation_without_contacting_a_service(): void
    {
        $session = $this->login('test-fake');
        $this->me($session['token'], 'test-fake')->assertOk();
        \Illuminate\Support\Facades\DB::table('local_browser_sessions')->where('fingerprint', hash('sha256', 'test-fake'))->update(['expires_at' => now()->subMinute()]);
        $this->me($session['token'], 'test-fake')->assertUnauthorized();
        Http::assertNothingSent();
    }

}
