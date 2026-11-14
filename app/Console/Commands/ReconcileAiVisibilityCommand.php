<?php

namespace App\Console\Commands;

use App\Models\AiVisibilityRun;
use App\Models\Tenant;
use App\Services\Geo\AiVisibility\AiVisibilityService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/** Recheck only previously persisted supplier attempts; never creates a new attempt ID. */
final class ReconcileAiVisibilityCommand extends Command
{
    protected $signature = 'geo:reconcile-ai-visibility {--limit=50}';

    protected $description = 'Recover owner-scoped AI visibility deliveries for unresolved runs';

    public function handle(AiVisibilityService $service): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $staleBefore = now()->subMinutes(10);
        $runs = AiVisibilityRun::query()->withoutGlobalScopes()
            ->where(function ($query) use ($staleBefore): void {
                $query->where('status', AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED)
                    ->orWhere(function ($stale) use ($staleBefore): void {
                        $stale->where('status', AiVisibilityRun::STATUS_RUNNING)
                            ->where('updated_at', '<', $staleBefore);
                    });
            })->orderBy('updated_at')->limit($limit)->get();

        $checked = 0;
        foreach ($runs as $run) {
            if (! is_string(data_get($run->metadata, 'model_client_request_id'))) {
                continue;
            }
            $tenant = Tenant::find($run->tenant_id);
            if (! $tenant || ! $tenant->isActive()) {
                continue;
            }
            TenantContext::set($tenant);
            try {
                if ($run->status === AiVisibilityRun::STATUS_RUNNING) {
                    AiVisibilityRun::query()->whereKey($run->id)
                        ->where('status', AiVisibilityRun::STATUS_RUNNING)
                        ->where('updated_at', '<', $staleBefore)
                        ->update(['status' => AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED,
                            'error_message' => '原监测尝试在工作进程中断后待核对。']);
                }
                $service->executeQuestion($run->id, true);
                $checked++;
            } catch (Throwable) {
                AiVisibilityRun::query()->whereKey($run->id)
                    ->where('status', AiVisibilityRun::STATUS_RUNNING)
                    ->update(['status' => AiVisibilityRun::STATUS_RECONCILIATION_REQUIRED,
                        'error_message' => '原监测尝试恢复中断，继续待核对。']);
            } finally {
                TenantContext::forget();
            }
        }
        $this->line("Checked {$checked} persisted visibility attempts.");

        return self::SUCCESS;
    }
}
