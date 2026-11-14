<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// geo:health-check 的实现已迁入 app/Console/Commands/GeoHealthCheckCommand.php（计划 §7 第 4 条）。

// ─────────────────────────────────────────────────────────────
//  阶段 1.4 任务自动化编排：调度心跳 + 定时派发
// ─────────────────────────────────────────────────────────────

// tasks:tick 的实现已迁入 app/Console/Commands/TasksTickCommand.php（计划 §7 第 4 条）。

\Illuminate\Support\Facades\Schedule::command('tasks:tick')->everyMinute();

// 单 9 · 调度器心跳保活（geo-scheduler 不消费队列，Looping 永不响，靠每分钟调度写一条心跳）
\Illuminate\Support\Facades\Schedule::command('ops:heartbeat')->everyMinute();

// ─────────────────────────────────────────────────────────────
//  平台运营台授权（T6：禁止手工 SQL，统一走命令；幂等）
// ─────────────────────────────────────────────────────────────

// platform:ops-grant 的实现已迁入 app/Console/Commands/PlatformOpsGrantCommand.php（计划 §7 第 4 条）。

// platform:ops-revoke 的实现已迁入 app/Console/Commands/PlatformOpsRevokeCommand.php（计划 §7 第 4 条）。

// geo:distribution-smoke 的实现已迁入 app/Console/Commands/GeoDistributionSmokeCommand.php（计划 §7 第 4 条）。

/**
 * 清道夫：把真正卡死的知识文档标成 failed。
 *
 * 为什么必须有：geo-worker 的 mem_limit 是 512m，PHP 的 memory_limit 也是 512M。
 * 解析一份结构特殊的 PDF 撑爆内存时，容器直接被 OOM kill —— 这种死法不走 failed()，
 * job 从表里消失，文档永远停在 processing。调度器里没有 TenantContext，
 * 所以查询要 withoutGlobalScope(TenantScope::class) 跨租户扫。
 *
 * ⚠️ pending 和 processing 必须用两个不同的阈值，别合成一个（2026-08-20 评审拦下）：
 *
 *   processing = worker 已经开跑。job 自己的 $timeout 是 540 秒，超过就该由队列判失败，
 *     还停在 processing 只剩「容器被 kill」一种解释。15 分钟足够，也正是这条命令的本职。
 *
 *   pending = 还在排队，一切正常，只是前面有人。生产 worker 的 --queue 是**严格优先级**，
 *     knowledge 排在 skills 后面；技能执行和全景分析各自可跑 480 秒，两个连着就 16 分钟，
 *     而企业知识库草稿生成（540 秒）跟入库共用 knowledge 这一条队列。用 15 分钟砍 pending，
 *     等于把正常排队的文档判死：用户看到「失败，请重新上传」→ 前端轮询因为没有
 *     pending/processing 而停掉 → 任务其实随后跑成功了页面也不再更新 → 用户照提示重传 →
 *     多一份重复文档，**并且多付一次向量化的钱**。所以 pending 给 2 小时，且不说「请重新上传」。
 */
// knowledge:reap-stuck 的实现已迁入 app/Console/Commands/KnowledgeReapStuckCommand.php
// （计划 §7 第 4 条）。两个阈值（processing 15 分钟 / pending 120 分钟）与文案均未改。

Schedule::command('knowledge:reap-stuck')->everyFiveMinutes();

/**
 * 清道夫之二：卡死的技能执行 / 综合诊断（App\Console\Commands\ReapStuckSkillExecutions）。
 *
 * 与知识文档那条同一个病根、不同的表：写终态的人（Job 的 handle / failed）不在场时，
 * skill_executions 永远停在 running，界面上永远转圈。判死条件与阈值的完整理由见命令类头部注释。
 */
Schedule::command('geo:reap-stuck-executions')->everyFiveMinutes();

/**
 * 敏感词命中日志清理（执行书 T7）。
 *
 * 这张表是每次文章保存 / 每次出站都可能写一行的高频日志表，
 * 只进不出的话迟早把库撑爆。保留 SensitiveWordHit::RETENTION_DAYS 天。
 *
 * 90 天够用的理由：这张表的用途是「主动发现某条词在误伤」，
 * 看的是最近的命中趋势和聚合，不是长期审计（谁改了词表由 log-admin-activity 记）。
 */
// sensitive-word-hits:prune 的实现已迁入 app/Console/Commands/SensitiveWordHitsPruneCommand.php（计划 §7 第 4 条）。

Schedule::command('sensitive-word-hits:prune')->dailyAt('03:20');

/**
 * 文章风险扫描落库清理（出站闸门 complianceGate 经 ArticleRiskScanner::record 写入）。
 *
 * 与 sensitive-word-hits:prune 同源的考量：扫描落库也是高频写入（每次出站都记一条），
 * 只进不出迟早撑爆库。保留 90 天——用途是「近期扫描趋势回溯」，不是长期审计。
 *
 * 注意：article_risk_scans 没有 created_at，时间语义由 scanned_at 承担（见 ArticleRiskScan）。
 * 故清理按 scanned_at 而非 created_at 判龄。
 */
// article-risk-scans:prune 的实现已迁入 app/Console/Commands/ArticleRiskScansPruneCommand.php（计划 §7 第 4 条）。

Schedule::command('article-risk-scans:prune')->dailyAt('03:25');

// 清理过期的 Sanctum 个人访问令牌。B1 修复后每个壳页开页都新建一行 geo-saas-token
// （14 天有效），这张表只涨不落；本批前更是「永不过期满权限」token 只增不减。
// 注：上线前签发的老行 expires_at 为空，prune-expired 删不到，它们由登录时
// 「删指纹为空的历史 token」逻辑（fix A）在用户下次登录时各自清掉。此处只管增量。
Schedule::command('sanctum:prune-expired')->dailyAt('03:30');

// 主题包生成：清理超期产物（执行书 T8，红线 1）。
// 先删文件、再删库行（复用 Service::deleteReplication）；另扫孤儿目录防磁盘泄漏。
// 与 03:25 article-risk-scans / 03:30 sanctum 错峰，避免每日凌晨 IO 集中。
// 与路由共用同一个总开关：关掉模块时定时任务也不该再跑（第一道回滚要能整个关干净）。
if (config('geo.theme_package_enabled', false)) {
    Schedule::command('theme-packages:prune')->dailyAt('03:35');
}

// Only recheck existing visibility attempt IDs and owner-scoped gateway
// deliveries. Unknown or unconfirmed supplier state remains unresolved.
Schedule::command('geo:reconcile-ai-visibility')->everyFiveMinutes()->withoutOverlapping();

/**
 * 隐私生命周期（计划 §9.5，口径由用户 2026-09-21 拍板）。
 *
 * 项目垃圾桶：客户删项目保留 7 天，到期不可恢复 —— 清理必须自动化，
 * 靠人记着删就成了"永远留着"（那也是合规问题）。
 *
 * 账户注销：申请后 15 天内登录即恢复；期间没登录的，到期清除身份信息。
 * 两条都排在凌晨那批清理之后、对账之前，避免与 03:20/03:25/03:30/03:35/04:10 挤在一起。
 */
Schedule::command('geo:projects-purge --apply')->dailyAt('03:40');
Schedule::command('geo:account-purge --apply')->dailyAt('03:45');

// 保留期限执行器（§9.5，用户 2026-09-21 拍板）：项目/账户清理之后剩下的东西按期限清。
// ⚠️ 故意**不带** --include-money：钱相关表（调用流水/扣点对账）保留 ≥24 个月，
//    要清必须人工显式加 --include-money（那道闸不该交给定时任务）。
Schedule::command('geo:privacy-purge --apply')->dailyAt('03:50');
