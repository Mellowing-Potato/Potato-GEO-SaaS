<?php

namespace App\Console\Commands;

use App\Services\Ops\PrivacyPurgeService;
use Illuminate\Console\Command;

/**
 * 保留期限执行器（计划 §9.5；口径由用户 2026-09-21 拍板：「可以，做吧」）。
 *
 * 与另外两条清理命令的分工（三条命令合起来才是完整的生命周期）：
 *   geo:projects-purge   垃圾桶期满 7 天 → 项目连同内容真删（调用流水保留）
 *   geo:account-purge    注销冷静期 15 天 → 清身份信息 + 名下内容
 *   geo:privacy-purge    上面两步之后**剩下的东西**按保留期限清：
 *                          内容类 90 天（磁盘产物 + 漏网的内容行）
 *                          钱相关 ≥24 个月，且默认只统计不动手
 *
 * 默认 **dry-run**；真清理要 `--apply`。钱相关表额外要 `--include-money` 才动手 ——
 * 两道闸是有意的：这条命令能删掉的东西里，只有钱那部分是不可再生的。
 */
class GeoPrivacyPurgeCommand extends Command
{
    protected $signature = 'geo:privacy-purge
        {--apply : 真的清理（默认只演练，不写库、不删文件）}
        {--include-money : 连钱相关表一起清（只清超过保留月的；不给就只统计）}
        {--json : 机读输出}';

    protected $description = '按保留期限清理内容类残留与磁盘产物（默认演练；钱相关需 --include-money）';

    public function handle(PrivacyPurgeService $purge): int
    {
        $apply = (bool) $this->option('apply');
        $includeMoney = (bool) $this->option('include-money');

        $report = $purge->run($apply, $includeMoney);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s：内容保留期 %d 天，钱相关保留 %d 个月%s。',
            $apply ? '清理执行完成' : '演练（未写库、未删文件）',
            $report['content_purge_days'],
            $report['money_retention_months'],
            $includeMoney ? '（含钱相关）' : '（本次不动钱相关）',
        ));
        foreach ($report['sections'] as $name => $section) {
            $this->line(sprintf('  %s：命中 %d 项%s', $name, $section['count'], $apply ? '（已处理）' : ''));
            foreach (array_slice($section['items'], 0, 10) as $item) {
                $this->line('      '.json_encode($item, JSON_UNESCAPED_UNICODE));
            }
            if ($section['count'] > 10) {
                $this->line(sprintf('      …其余 %d 项见 --json', $section['count'] - 10));
            }
        }
        if (! $apply) {
            $this->line('  真的清理请加 --apply。');
        }

        return self::SUCCESS;
    }
}
