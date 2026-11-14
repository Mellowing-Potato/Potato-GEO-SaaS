<?php

namespace App\Services\Account;

use App\Models\Project;
use App\Models\User;
use App\Services\Admin\ActivityLogService;
use App\Services\Projects\ProjectTrashService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 账户注销（计划 §9.5；用户口径 2026-09-21）。
 *
 * 用户原话：「账户可以注销，但是要提交申请注销，15 天以内再次登陆就等于恢复账户」。
 *
 * 于是注销不是"点一下就没"，而是一段**可见、可撤销**的冷静期：
 *
 *   1. request()       —— 提交申请：写 deletion_requested_at / deletion_purge_at（+15 天）、
 *                         撤销该账户的全部登录。客户立刻回到登录页，但账号与内容都还在。
 *   2. cancelIfRequested() —— 冷静期内**任何一次成功登录**都自动撤销申请（这就是"恢复账户"），
 *                         响应里带回 account_restored=true，界面据此提示"账户已恢复"。
 *   3. purge()         —— 期满清理：抹掉身份信息（手机号 / 邮箱 / 密码 / 网关身份 id），
 *                         租户里只剩这一个账号时，其名下项目走"真删"（调用流水照留）。
 *
 * 三处都写审计（admin_activity_logs）：申请、撤销、执行清理。注销是敏感操作，
 * 事后必须能答出"谁在什么时候申请/撤销的"。
 *
 * 口径边界（写清楚，避免过度承诺）：
 *  - 账户清理**不动租户与订阅**：钱和套餐在网关/账户中心那一侧，本地命令不越权去改
 *    （与 §9.2/§9.3 的"资金动作只由网关的复核流程执行"是同一条红线）。
 *  - 租户里还有别的成员时**不清内容**：那是别人的工作区，不是这一个账号的私产。
 */
final class AccountDeletionService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly ProjectTrashService $projectTrash,
    ) {}

    /** 冷静期天数（用户口径 15 天，可由 GEO_ACCOUNT_DELETION_GRACE_DAYS 覆盖）。 */
    public function graceDays(): int
    {
        return max(1, (int) config('geo.lifecycle.account_deletion_grace_days', 15));
    }

    /**
     * 提交注销申请。
     *
     * @return array{requested_at:string,purge_at:string,grace_days:int}
     */
    public function request(User $user, ?string $reason = null, ?string $ip = null): array
    {
        $requestedAt = CarbonImmutable::now();
        $purgeAt = $requestedAt->addDays($this->graceDays());

        $user->forceFill([
            'deletion_requested_at' => $requestedAt,
            'deletion_purge_at' => $purgeAt,
            'deletion_reason' => $reason,
        ])->save();

        // 申请即退出全部登录：界面立刻回到登录页，不会"申请了还继续能用"。
        // 恢复的方式也由此唯一化 —— 重新登录一次（用户口径："再次登陆就等于恢复账户"）。
        \App\Support\LocalSession::revokeAll($user);

        $this->activityLog->record('account.deletion_requested', [
            'tenant_id' => $user->tenant_id,
            'actor_id' => $user->id,
            'actor_type' => 'user',
            'actor_name' => $user->name,
            'ip_address' => (string) $ip,
            'target_type' => 'user',
            'target_id' => $user->id,
            'details' => [
                'purge_at' => $purgeAt->toISOString(),
                'grace_days' => $this->graceDays(),
                'reason' => $reason,
            ],
        ]);

        return [
            'requested_at' => $requestedAt->toISOString(),
            'purge_at' => $purgeAt->toISOString(),
            'grace_days' => $this->graceDays(),
        ];
    }

    /** 冷静期内的登录 = 恢复账户。返回撤销信息；没有待撤销的申请时返回 null。 */
    public function cancelIfRequested(User $user, ?string $ip = null): ?array
    {
        if ($user->deletion_requested_at === null && $user->deletion_purge_at === null) {
            return null;
        }

        $requestedAt = $user->deletion_requested_at;

        $user->forceFill([
            'deletion_requested_at' => null,
            'deletion_purge_at' => null,
            'deletion_reason' => null,
        ])->save();

        $this->activityLog->record('account.deletion_cancelled', [
            'tenant_id' => $user->tenant_id,
            'actor_id' => $user->id,
            'actor_type' => 'user',
            'actor_name' => $user->name,
            'ip_address' => (string) $ip,
            'target_type' => 'user',
            'target_id' => $user->id,
            'details' => ['requested_at' => optional($requestedAt)->toISOString()],
        ]);

        return [
            'cancelled_at' => CarbonImmutable::now()->toISOString(),
            'requested_at' => optional($requestedAt)->toISOString(),
        ];
    }

    /** 账户注销状态的对外视图（个人设置页用）。 */
    public function status(User $user): array
    {
        $requestedAt = $user->deletion_requested_at;
        $purgeAt = $user->deletion_purge_at;

        return [
            'pending' => $requestedAt !== null,
            'requested_at' => optional($requestedAt)->toISOString(),
            'purge_at' => optional($purgeAt)->toISOString(),
            'days_left' => $purgeAt === null
                ? 0
                : max(0, (int) ceil(CarbonImmutable::now()->diffInSeconds(CarbonImmutable::instance($purgeAt), false) / 86400)),
            'grace_days' => $this->graceDays(),
            'purged_at' => optional($user->deletion_purged_at)->toISOString(),
        ];
    }

    /**
     * 扫出冷静期已过、该清理的账户（跨租户）。
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    public function expiredUsers(?CarbonImmutable $now = null): \Illuminate\Support\Collection
    {
        $now ??= CarbonImmutable::now();

        return User::withoutGlobalScopes()
            ->whereNotNull('deletion_purge_at')
            ->whereNull('deletion_purged_at')
            ->where('deletion_purge_at', '<=', $now)
            ->orderBy('id')
            ->get();
    }

    /**
     * 执行清理：抹身份 + （仅当租户里只剩这一个账号时）真删其名下项目。
     *
     * @return array{user_id:int,purged_at:string,projects:array<int,array<string,mixed>>,content_purged:bool}
     */
    public function purge(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $tenantId = (int) $user->tenant_id;
            $userId = (int) $user->id;

            $remainingUsers = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', '!=', $userId)
                ->count();

            $purged = [];
            if ($remainingUsers === 0) {
                $projects = Project::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->withTrashed()
                    ->orderBy('id')
                    ->get();

                foreach ($projects as $project) {
                    $purged[] = $this->projectTrash->purgeForAccountDeletion($project, $user);
                }
            }

            \App\Support\LocalSession::revokeAll($user);
            $user->forceFill([
                'name' => '已注销账户',
                'email' => null,
                'email_verified_at' => null,
                'phone' => null,
                'phone_verified_at' => null,
                'password' => null,
                'remember_token' => null,
                'deletion_reason' => null,
                'deletion_purged_at' => CarbonImmutable::now(),
            ])->save();

            $this->activityLog->record('account.deletion_purged', [
                'tenant_id' => $tenantId,
                'actor_type' => 'system',
                'actor_name' => 'geo:account-purge',
                'target_type' => 'user',
                'target_id' => $userId,
                'details' => [
                    'remaining_users' => $remainingUsers,
                    'projects_purged' => count($purged),
                ],
            ]);

            return [
                'user_id' => $userId,
                'purged_at' => CarbonImmutable::now()->toISOString(),
                'projects' => $purged,
                'content_purged' => $remainingUsers === 0,
            ];
        });
    }
}
