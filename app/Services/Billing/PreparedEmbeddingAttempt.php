<?php

namespace App\Services\Billing;

use App\Models\SkillExecution;
use App\Services\Enums\ExecutionStatus;
use Illuminate\Support\Facades\DB;

/** Persist the original skill RAG request before it can reach the paid gateway. */
final class PreparedEmbeddingAttempt
{
    public static function save(int $executionId, int $projectId, int $userId, string $featureId,
        string $taskId, string $query, array $briefData = []): string
    {
        $clientId = 'embed:'.hash('sha256', json_encode([
            $featureId, $taskId, 'knowledge-query',
        ], JSON_THROW_ON_ERROR));
        $queryHash = hash('sha256', $query);

        DB::transaction(function () use ($executionId, $projectId, $userId, $featureId, $taskId, $query,
            $queryHash, $clientId, $briefData): void {
            $execution = SkillExecution::query()->whereKey($executionId)->lockForUpdate()->first();
            if (! $execution || $execution->status !== ExecutionStatus::Running->value
                || $execution->project_id !== $projectId || $execution->user_id !== $userId
                || $execution->skill_id !== $featureId
                || $taskId !== 'skill:'.$executionId) {
                throw new \RuntimeException('Embedding attempt cannot be prepared for this execution.');
            }

            $prior = $execution->billing_pending_result;
            if ($prior !== null) {
                if (in_array(data_get($prior, 'kind'),
                    ['embedding_result_recorded'], true)
                    && data_get($prior, 'gateway.attribution.client_request_id') === $clientId
                    && data_get($prior, 'query_hash') === $queryHash) {
                    return;
                }
                if (in_array(data_get($prior, 'kind'), ['embedding_attempt_prepared', 'embedding_attempt_unknown'], true)) {
                    throw new UnresolvedEmbeddingAttempt(is_array($prior['gateway'] ?? null) ? $prior['gateway'] : []);
                }
                throw new \RuntimeException('Another paid attempt already owns this execution.');
            }

            $execution->update(['billing_pending_result' => [
                'version' => 1, 'kind' => 'embedding_attempt_prepared',
                'gateway' => ['attribution' => [
                    'client_request_id' => $clientId, 'task_id' => $taskId,
                    'feature_id' => $featureId, 'step_id' => 'knowledge-query',
                ]],
                'query_hash' => $queryHash,
                'query' => $query,
                'brief_data' => $briefData,
            ]]);
        });

        return $clientId;
    }

    /** Release only our own intent after a confirmed failure, never an unknown result. */
    public static function clearRejected(int $executionId, string $clientId): void
    {
        DB::transaction(function () use ($executionId, $clientId): void {
            $execution = SkillExecution::query()->whereKey($executionId)->lockForUpdate()->first();
            if ($execution && data_get($execution->billing_pending_result, 'kind') === 'embedding_attempt_prepared'
                && data_get($execution->billing_pending_result, 'gateway.attribution.client_request_id') === $clientId) {
                $execution->update(['billing_pending_result' => null]);
            }
        });
    }

    /** Keep a successful vector on the original encrypted execution before local RAG work. */
    public static function recordResult(int $executionId, string $clientId, array $vector,
        array $gateway = []): void
    {
        if (! \App\Services\AI\EmbeddingModelPolicy::validVector($vector)) {
            throw new \RuntimeException('Embedding result is not a finite vector.');
        }
        if (isset($gateway['attribution']['client_request_id'])
            && $gateway['attribution']['client_request_id'] !== $clientId) {
            throw new \RuntimeException('Embedding result belongs to another gateway attempt.');
        }
        DB::transaction(function () use ($executionId, $clientId, $vector, $gateway): void {
            $execution = SkillExecution::query()->whereKey($executionId)->lockForUpdate()->first();
            $prior = $execution?->billing_pending_result;
            if (! $execution || $execution->status !== ExecutionStatus::Running->value
                || data_get($prior, 'kind') !== 'embedding_attempt_prepared'
                || data_get($prior, 'gateway.attribution.client_request_id') !== $clientId) {
                throw new \RuntimeException('Original embedding checkpoint is unavailable.');
            }
            $prior['kind'] = 'embedding_result_recorded';
            $prior['vector'] = $vector;
            $prior['gateway'] = array_replace_recursive($prior['gateway'] ?? [], $gateway);
            $execution->update(['billing_pending_result' => $prior]);
        });
    }

    public static function recordedVector(int $executionId, string $clientId): ?array
    {
        $snapshot = SkillExecution::query()->whereKey($executionId)->first()?->billing_pending_result;
        if (data_get($snapshot, 'kind') !== 'embedding_result_recorded'
            || data_get($snapshot, 'gateway.attribution.client_request_id') !== $clientId) {
            return null;
        }
        $vector = $snapshot['vector'] ?? null;
        if (! \App\Services\AI\EmbeddingModelPolicy::validVector($vector)) {
            throw new \RuntimeException('Recorded embedding vector is unavailable.');
        }
        return $vector;
    }
}
