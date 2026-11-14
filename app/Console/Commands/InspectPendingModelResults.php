<?php

namespace App\Console\Commands;

use App\Models\SkillExecution;
use Illuminate\Console\Command;

/** Read-only inventory of encrypted model recovery checkpoints. */
final class InspectPendingModelResults extends Command
{
    protected $signature = 'geo:inspect-pending-model-results
        {--older-than=2 : Minimum checkpoint age in minutes}
        {--scan=500 : Maximum pending executions to inspect}
        {--limit=100 : Maximum matching rows to return}
        {--json : Machine-readable output}';

    protected $description = 'List unresolved GEO model checkpoints without decrypting content into output';

    public function handle(): int
    {
        $minutes = max(0, (int) $this->option('older-than'));
        $scanLimit = max(1, min(1000, (int) $this->option('scan')));
        $limit = max(1, min(500, (int) $this->option('limit')));
        $kinds = ['embedding_attempt_prepared', 'embedding_attempt_unknown', 'embedding_result_recorded',
            'model_attempt_prepared', 'model_attempt_unknown', 'model_result_unrecorded', 'model_result_recorded'];
        $rows = [];
        $scanned = 0;
        $query = SkillExecution::withoutGlobalScopes()
            ->whereIn('status', ['billing_pending', 'cancelled'])
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->select(['id', 'tenant_id', 'status', 'updated_at', 'billing_pending_result']);
        foreach ($query->cursor() as $execution) {
            if ($scanned++ >= $scanLimit) {
                break;
            }
            $snapshot = $execution->billing_pending_result;
            $kind = is_array($snapshot) ? ($snapshot['kind'] ?? null) : null;
            if (! in_array($kind, $kinds, true)) {
                continue;
            }
            $rows[] = [
                'execution_id' => $execution->id,
                'tenant_id' => $execution->tenant_id,
                'execution_status' => $execution->status,
                'checkpoint_kind' => $kind,
                'model_call_log_id' => $kind === 'model_result_recorded'
                    ? ($snapshot['model_call_log_id'] ?? null) : null,
                'updated_at' => $execution->updated_at?->toISOString(),
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }

        $summary = ['object' => 'geo_model_checkpoint_backlog',
            'older_than_minutes' => $minutes, 'scanned' => min($scanned, $scanLimit),
            'count' => count($rows), 'rows' => $rows];
        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line("GEO model checkpoints: {$summary['count']} matched, {$summary['scanned']} scanned");
            $this->table(['execution', 'tenant', 'status', 'kind', 'model log', 'updated'],
                array_map(static fn (array $row): array => [
                    $row['execution_id'], $row['tenant_id'], $row['execution_status'],
                    $row['checkpoint_kind'], $row['model_call_log_id'] ?? '-', $row['updated_at'],
                ], $rows));
        }

        return self::SUCCESS;
    }
}
