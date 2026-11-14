<?php

namespace App\Console\Commands;

use App\Services\Billing\UnknownModelAttemptRecovery;
use Illuminate\Console\Command;
use Throwable;

/** Inspect one saved GEO model attempt; explicit apply can repair its local usage record. */
final class RecoverUnknownModelAttempt extends Command
{
    protected $signature = 'geo:recover-unknown-model-attempt
        {execution : Original skill execution ID}
        {--apply : Record the original result, then resume eligible single-call delivery without a supplier replay}
        {--json : Machine-readable status without model content}';

    protected $description = 'Inspect or repair an unresolved GEO model attempt without a new supplier call';

    public function handle(UnknownModelAttemptRecovery $recovery): int
    {
        $id = filter_var($this->argument('execution'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->error('Provide a positive skill execution ID.');

            return self::FAILURE;
        }

        try {
            $result = $recovery->recover($id, (bool) $this->option('apply'));
        } catch (Throwable $e) {
            $result = ['status' => 'error', 'execution_id' => $id,
                'error_type' => class_basename($e)];
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Execution '.$id.': '.$result['status']);
            if (isset($result['model_call_log_id'])) {
                $this->line('Model call log: '.$result['model_call_log_id']);
            }
        }

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
