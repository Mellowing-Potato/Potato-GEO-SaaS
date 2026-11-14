<?php

namespace App\Console\Commands;

use App\Services\SkillAgent\SkillAssetAuditService;
use Illuminate\Console\Command;
use Throwable;

class AuditSkillAssets extends Command
{
    protected $signature = 'geo:audit-skill-assets {--json : 输出完整 JSON} {--strict : 有告警也返回非零状态}';

    protected $description = '只读核对已审定技能清单、资产来源和上游版本锁，不执行模型或修复配置';

    public function handle(SkillAssetAuditService $audit): int
    {
        try {
            $report = $audit->audit();
            $exitCode = $report['summary']['errors'] > 0 || ($this->option('strict') && $report['summary']['warnings'] > 0) ? 1 : 0;
            $report['exit_code'] = $exitCode;
            $report['strict'] = (bool) $this->option('strict');
            if ($this->option('json')) {
                $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->line(sprintf('技能资产检查：%s；注册 %d，开放 %d，错误 %d，告警 %d。',
                    $report['status'], ...array_values($report['summary'])));
                $this->line('注册表来源：'.$report['registry']['selected_source'].'；'.$report['registry']['path']);
                $this->line('上游版本：'.($report['upstream_version']['head'] ?? '未知'));
                $this->table(['技能', '开放', '指令', '渲染脚本'], array_map(fn ($skill) => [
                    $skill['id'], $skill['available'] ? '是' : '否',
                    $skill['instruction']['path'] ?? '未检查', $skill['render_script']['path'] ?? '未选择',
                ], $report['skills']));
                foreach ($report['findings'] as $finding) {
                    $this->line('['.$finding['severity'].'] '.$finding['code'].'：'.$finding['message'].' '.($finding['path'] ?? ''));
                }
                $this->line('本次仅检查文件与配置，未验证模型、数据库提示词覆盖或渲染运行环境；完整指纹与来源见 --json。');
            }

            return $exitCode;
        } catch (Throwable $exception) {
            $error = ['schema_version' => 1, 'read_only' => true, 'status' => 'inspection_failed', 'exit_code' => 2, 'message' => $exception->getMessage()];
            $this->line($this->option('json') ? json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '自检未完成：'.$error['message']);

            return 2;
        }
    }
}
