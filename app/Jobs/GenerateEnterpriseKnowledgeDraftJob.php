<?php

namespace App\Jobs;

use App\Models\EnterpriseKnowledgeProject;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantScope;
use App\Services\Knowledge\EnterpriseKnowledgeDraftService;
use App\Support\BillingContext;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateEnterpriseKnowledgeDraftJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * 540 而不是 600：database 队列的 retry_after 就是 600，两个数相等时，
     * 任务跑到边界会被第二个 worker 重新领取。而 $tries=1 意味着第二个 worker
     * 在执行任务体之前就把它判失败（Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts
     * 在 fire() 之前跑），于是一次正在成功的草稿生成被写成「生成失败」。
     * 2026-08-20 评审时连带修掉（它和文档入库共用 knowledge 这一条队列）。
     */
    public int $timeout = 540;

    public function __construct(
        public readonly int $projectId,
        public readonly ?int $userId = null,
        public readonly ?int $draftVersion = null,
    ) {}

    public function handle(EnterpriseKnowledgeDraftService $draftService): void
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            return;
        }
        try {
            TenantContext::set($tenant);
            $project = EnterpriseKnowledgeProject::query()->with('sources')
                ->whereKey($this->projectId)->first();
            if (! $project) {
                return;
            }
            $baseVersion = (int) $project->draft_version;
            if (isset($this->draftVersion) && $this->draftVersion !== $baseVersion) {
                return;
            }
            if ($this->userId !== null && ! User::query()
                ->whereKey($this->userId)->where('tenant_id', $tenant->id)->exists()) {
                throw new \RuntimeException('Draft requester does not belong to the project tenant');
            }

            // 已处于复核/发布态且已有草稿则跳过，避免覆盖人工编辑。
            if (in_array((string) $project->status, [
                    EnterpriseKnowledgeProject::STATUS_REVIEWING,
                    EnterpriseKnowledgeProject::STATUS_PUBLISHED,
                ], true)
                && trim((string) $project->draft_content) !== '') {
                return;
            }

            $started = EnterpriseKnowledgeProject::query()->whereKey($project->id)
                ->where('draft_version', $baseVersion)->update(['status' => EnterpriseKnowledgeProject::STATUS_PROCESSING]);
            if ($started !== 1) {
                return;
            }
            $project->status = EnterpriseKnowledgeProject::STATUS_PROCESSING;

            // 认领计费主体：队列里 Auth 是空的，事前余额闸认不出该扣谁的钱。
            $draft = BillingContext::scoped(
                $this->userId,
                'enterprise-knowledge:draft',
                fn (): array => $draftService->generateDraft($project),
            );
            $content = trim((string) $draft['content']);
            $validationItems = $draftService->validateDraft($content);

            DB::transaction(function () use ($project, $baseVersion, $content, $validationItems, $draft, $draftService) {
                $claimed = $project->claimDraftVersion($baseVersion);
                if ($claimed) {
                    $project->forceFill([
                        'status' => EnterpriseKnowledgeProject::STATUS_REVIEWING,
                        'draft_content' => $content,
                        'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
                        'error_message' => $draft['error'],
                    ])->save();
                }
                // A completed result is retained, but cannot replace a newer manual draft.
                $draftService->recordRevision(
                    $project, $content,
                    $draft['source'] === 'ai'
                        ? \App\Models\EnterpriseKnowledgeRevision::SOURCE_AI
                        : \App\Models\EnterpriseKnowledgeRevision::SOURCE_SYSTEM,
                    $claimed ? '系统生成草稿' : '生成期间草稿已更新；生成结果仅保留为修订',
                    $this->userId
                );
            });
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            if (isset($project)) {
                EnterpriseKnowledgeProject::query()->whereKey($project->id)
                    ->where('draft_version', $baseVersion)->update([
                    'status' => EnterpriseKnowledgeProject::STATUS_FAILED,
                    'error_message' => $message,
                ]);
            }
        } finally {
            TenantContext::forget();
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        if (! $exception || ! ($tenant = $this->resolveTenant())) {
            return;
        }
        try {
            TenantContext::set($tenant);
            $project = EnterpriseKnowledgeProject::query()->whereKey($this->projectId)->first();
            if ($project && isset($this->draftVersion)) {
                EnterpriseKnowledgeProject::query()->whereKey($project->id)
                    ->where('draft_version', $this->draftVersion)->update([
                    'status' => EnterpriseKnowledgeProject::STATUS_FAILED,
                    'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                ]);
            }
        } finally {
            TenantContext::forget();
        }
    }

    private function resolveTenant(): ?Tenant
    {
        // Old database-queue payloads contain only projectId/userId. The one
        // unscoped lookup resolves ownership; all subsequent reads are scoped.
        $project = EnterpriseKnowledgeProject::withoutGlobalScope(TenantScope::class)
            ->whereKey($this->projectId)->first();
        if (! $project || (int) $project->tenant_id <= 0) {
            return null;
        }
        return Tenant::query()->find($project->tenant_id);
    }
}
