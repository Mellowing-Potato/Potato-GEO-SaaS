<?php

namespace App\Console\Commands;

use App\Services\Ops\PrivacyLifecycleAuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * 隐私生命周期盘点（计划 §9.5、总台账 S11）。
 *
 * 只读，不删任何东西 —— 这是"技术盘点可先做、规则需确认"那句话的落点：
 * 先把"删完之后还剩什么"变成一条随时能跑的命令，规则定下来之后才有依据去写清理。
 */
class PrivacyLifecycleAuditCommand extends Command
{
    protected $signature = 'geo:privacy-lifecycle-audit
        {--project= : 盘点单个项目（软删的项目也查得到）：删完之后还剩哪些行}
        {--tenant= : 盘点单个租户（账户级）：按 tenant_id 统计每张表现存行数}
        {--json : 输出机器可读 JSON}
        {--strict : 发现残留（或孤儿报表目录）时返回非零状态}';

    protected $description = '只读盘点项目/账户删除后的后台残留与孤儿报表目录（隐私生命周期），不删除任何数据';

    public function handle(PrivacyLifecycleAuditService $audit): int
    {
        try {
            $report = $this->buildReport($audit);
            $hasFindings = $this->hasFindings($report);
            $status = $this->scopeStatus($report);
            // 状态不是 ok（项目不存在 / 租户不匹配）一律 2：那是"这次没盘成"，不是"盘完没事"。
            $report['exit_code'] = $status !== 'ok' ? 2 : (($hasFindings && $this->option('strict')) ? 1 : 0);
            $report['strict'] = (bool) $this->option('strict');

            if ($this->option('json')) {
                $this->line(json_encode(
                    $report,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ));
            } else {
                $this->render($report);
            }

            return $report['exit_code'];
        } catch (Throwable $exception) {
            $error = [
                'schema_version' => 1,
                'read_only' => true,
                'status' => 'inspection_failed',
                'message' => $exception->getMessage(),
                'exit_code' => 2,
            ];
            $this->line($this->option('json')
                ? json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '盘点未完成：'.$error['message']);

            return 2;
        }
    }

    /** @return array<string, mixed> */
    private function buildReport(PrivacyLifecycleAuditService $audit): array
    {
        $project = $this->option('project');
        $tenant = $this->option('tenant');

        if ($project !== null && $project !== '') {
            return [
                'schema_version' => 1,
                'read_only' => true,
                'generated_at' => now()->toISOString(),
                'scope' => 'project',
                'project' => $audit->auditProject(
                    (int) $project,
                    $tenant !== null && $tenant !== '' ? (int) $tenant : null,
                ),
                'report_directories' => $audit->reportDirectories(),
            ];
        }

        if ($tenant !== null && $tenant !== '') {
            return [
                'schema_version' => 1,
                'read_only' => true,
                'generated_at' => now()->toISOString(),
                'scope' => 'tenant',
                'tenant' => $audit->auditTenant((int) $tenant),
                'report_directories' => $audit->reportDirectories(),
            ];
        }

        return [
            'schema_version' => 1,
            'read_only' => true,
            'generated_at' => now()->toISOString(),
            'scope' => 'soft_deleted_projects',
            'projects' => $audit->softDeletedProjects(),
            'report_directories' => $audit->reportDirectories(),
        ];
    }

    /** @param  array<string, mixed>  $report */
    private function scopeStatus(array $report): string
    {
        return (string) match ($report['scope']) {
            'project' => $report['project']['status'] ?? 'unknown',
            'tenant' => $report['tenant']['status'] ?? 'unknown',
            default => 'ok',
        };
    }

    /** @param  array<string, mixed>  $report */
    private function hasFindings(array $report): bool
    {
        $directories = $report['report_directories'] ?? [];
        if (($directories['orphan_count'] ?? 0) > 0) {
            return true;
        }

        return match ($report['scope']) {
            'project' => ($report['project']['leftover_row_count'] ?? 0) > 0,
            'tenant' => ($report['tenant']['row_count'] ?? 0) > 0,
            'soft_deleted_projects' => ($report['projects']['count'] ?? 0) > 0,
            default => false,
        };
    }

    /** @param  array<string, mixed>  $report */
    private function render(array $report): void
    {
        $this->line('隐私生命周期盘点（只读，不删除任何数据）');

        if ($report['scope'] === 'project') {
            $project = $report['project'];
            if (($project['status'] ?? '') !== 'ok') {
                $this->error('项目盘点未完成：'.$project['status']);

                return;
            }

            $state = $project['project'];
            $this->line(sprintf(
                '项目 #%d %s（租户 %d）· %s',
                $state['id'], $state['name'], $state['tenant_id'],
                $state['deleted'] ? '已软删于 '.$state['deleted_at'] : '仍在用',
            ));
            $this->line(sprintf(
                '扫了 %d 张带 project_id 的表，现存 %d 行%s。',
                $project['tables_scanned'],
                $project['leftover_row_count'],
                $state['deleted'] ? '（这是删除之后剩下的）' : '（这是删除前会留下/牵动的）',
            ));
            $this->table(
                ['表', '现存行数', '说明'],
                array_map(fn (array $row): array => [$row['table'], $row['rows'], $row['note'] ?? ''], $project['leftovers']),
            );

            $external = $project['external_publications'];
            if ($external['total'] > 0) {
                $this->line(sprintf(
                    '外站发布：%d 条记录（%s），其中 %d 条有远端地址。',
                    $external['total'],
                    implode('、', array_map(fn (string $status, int $count): string => "{$status}×{$count}", array_keys($external['by_status']), array_values($external['by_status']))),
                    $external['with_remote_url'],
                ));
            }
            $this->line($external['note']);

            $this->renderNotes($project['notes'] ?? []);
        } elseif ($report['scope'] === 'tenant') {
            $tenant = $report['tenant'];
            if (($tenant['status'] ?? '') !== 'ok') {
                $this->error('租户盘点未完成：'.$tenant['status']);

                return;
            }

            $this->line(sprintf('租户 #%d %s · 现存 %d 行。', $tenant['tenant']['id'], $tenant['tenant']['subdomain'], $tenant['row_count']));
            $this->table(
                ['表', '现存行数'],
                array_map(fn (array $row): array => [$row['table'], $row['rows']], $tenant['tables_with_rows']),
            );
            $this->line('账户删除入口：'.($tenant['account_deletion']['entry_point_exists'] ? implode('、', $tenant['account_deletion']['routes']) : '不存在'));
            $this->line($tenant['account_deletion']['note']);
            $this->renderNotes($tenant['notes'] ?? []);
        } else {
            $projects = $report['projects'];
            $this->line(sprintf('已软删的项目：%d 个%s。', $projects['count'], $projects['truncated'] ? '（列表已截断）' : ''));
            $this->table(
                ['项目', '名称', '租户', '删除时间'],
                array_map(fn (array $row): array => [$row['id'], $row['name'], $row['tenant_id'], $row['deleted_at']], $projects['projects']),
            );
            $this->line($projects['hint']);
            $this->renderNotes($projects['notes'] ?? []);
        }

        $directories = $report['report_directories'];
        if (($directories['status'] ?? '') === 'ok') {
            $this->line(sprintf(
                '报表目录：扫了 %d 个执行目录，其中 %d 个是孤儿（共 %d 字节）—— 文件还在，但已经没有行能说明它属于谁。',
                $directories['scanned_directories'], $directories['orphan_count'], $directories['orphan_bytes'],
            ));
            foreach ($directories['orphans'] as $orphan) {
                $this->line(sprintf('  孤儿：%s（%d 字节）', $orphan['path'], $orphan['bytes']));
            }
            foreach ($directories['unmapped'] as $item) {
                $this->line(sprintf('  未登记前缀（不判定）：%s', $item['path']));
            }
        } else {
            $this->line('报表目录盘点未完成：'.($directories['reason'] ?? $directories['status'] ?? '未知'));
        }

        $this->line('本命令只读：没有删除任何行、没有移动任何文件。规则未定前不要拿它的输出去做清理。');
    }

    /** @param  list<string>  $notes */
    private function renderNotes(array $notes): void
    {
        foreach ($notes as $note) {
            $this->line('· '.$note);
        }
    }
}
