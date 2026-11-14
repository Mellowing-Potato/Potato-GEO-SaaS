<?php

namespace App\Console\Commands;

use App\Services\Billing\PendingEmbeddingAttemptInspector;
use Illuminate\Console\Command;
use Throwable;

/** A write requires an explicit apply and a safe same-database queue. */
final class InspectPendingEmbeddingAttempt extends Command
{
    protected $signature = 'geo:inspect-pending-embedding-attempt
        {execution : Original skill execution ID}
        {--json : Machine-readable status without query or vector content}
        {--apply : Resume the original execution or automation run after gateway verification}';

    protected $description = 'Read the original GEO embedding financial attempt without a supplier replay';

    public function handle(PendingEmbeddingAttemptInspector $inspector): int
    {
        $id = filter_var($this->argument('execution'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->error('Provide a positive skill execution ID.');
            return self::FAILURE;
        }
        try {
            $result = $this->option('apply') ? $inspector->recover($id) : $inspector->inspect($id);
        } catch (Throwable $e) {
            $result = ['status' => 'error', 'execution_id' => $id,
                'error_type' => class_basename($e)];
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('Execution '.$id.': '.$result['status']);
        }

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
