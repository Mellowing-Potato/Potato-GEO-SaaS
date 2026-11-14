<?php

namespace App\Console\Commands;

use App\Services\Billing\KnownChunkIdentityConflictRecovery;
use Illuminate\Console\Command;
use Throwable;

final class RecoverKnownChunkIdentityConflict extends Command
{
    protected $signature = 'geo:recover-known-chunk-conflict
        {execution : Original skill execution ID}
        {--tenant= : Expected tenant ID; required when applying}
        {--apply : Resume only the saved core_answer repair with its new step identity}
        {--json : Print machine-readable preflight without model content}';

    protected $description = 'Preview or resume a legacy explainer core_answer identity conflict';

    public function handle(KnownChunkIdentityConflictRecovery $recovery): int
    {
        $id = filter_var($this->argument('execution'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        $tenant = $this->option('tenant') === null ? null : filter_var($this->option('tenant'),
            FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $tenant === false || ($this->option('apply') && $tenant === null)) {
            $this->error('Provide a positive execution ID and, for --apply, a positive --tenant ID.');

            return self::FAILURE;
        }
        try {
            $result = $recovery->inspect($id, $tenant, (bool) $this->option('apply'));
        } catch (Throwable $e) {
            $result = ['status' => 'error', 'execution_id' => $id, 'error_type' => class_basename($e)];
        }
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return in_array($result['status'], ['ready', 'resumed'], true) ? self::SUCCESS : self::FAILURE;
    }
}
