<?php

namespace App\Console\Commands;

use App\Services\Account\AccountDeletionService;
use Illuminate\Console\Command;

/**
 * 账户注销期满清理（计划 §9.5；用户口径 2026-09-21：「15 天以内再次登陆就等于恢复账户」）。
 *
 * 冷却期内登录过的账号，申请已被登录路径撤销（deletion_purge_at 被清空），本命令扫不到；
 * 只有"申请之后再没登录过"的账号才会到期。清理动作 = 身份信息抹除 + （租户只剩这一个账号时）
 * 其名下项目真删；调用流水照留。
 *
 * 默认 dry-run，真清理要 --apply（不可逆）。
 */
class GeoAccountPurgeCommand extends Command
{
    protected $signature = 'geo:account-purge
        {--apply : 真的清理（默认只演练，不写库）}
        {--json : 机读输出}';

    protected $description = '清理冷静期（默认 15 天）已过且期间未登录的注销账户；调用流水保留';

    public function handle(AccountDeletionService $service): int
    {
        $apply = (bool) $this->option('apply');
        $expired = $service->expiredUsers();

        $results = [];
        foreach ($expired as $user) {
            $results[] = $apply
                ? $service->purge($user)
                : [
                    'user_id' => (int) $user->id,
                    'tenant_id' => (int) $user->tenant_id,
                    'requested_at' => optional($user->deletion_requested_at)->toISOString(),
                    'purge_at' => optional($user->deletion_purge_at)->toISOString(),
                ];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'object' => 'geo_account_purge',
                'mode' => $apply ? 'apply' : 'dry-run',
                'grace_days' => $service->graceDays(),
                'count' => count($results),
                'accounts' => $results,
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s：冷静期 %d 天，命中 %d 个账户。',
            $apply ? '清理完成' : '演练（未写库）',
            $service->graceDays(),
            count($results),
        ));
        foreach ($results as $row) {
            $this->line($apply
                ? sprintf('  ✗ user #%d：清理项目 %d 个（内容清理：%s）', $row['user_id'], count($row['projects']), $row['content_purged'] ? '是' : '否，租户还有别的成员')
                : sprintf('  · user #%d（%s 到期）', $row['user_id'], $row['purge_at']));
        }
        if (! $apply && $results !== []) {
            $this->line('  真的清理请加 --apply。');
        }

        return self::SUCCESS;
    }
}
