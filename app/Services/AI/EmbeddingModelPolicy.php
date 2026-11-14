<?php

namespace App\Services\AI;

use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use Illuminate\Validation\ValidationException;

final class EmbeddingModelPolicy
{
    public const DIMENSIONS = 1024;
    public const NOTICE = '选定后，如需更换，需要清空知识库并重新导入。';

    public static function identity(string $provider, string $model): array
    {
        // URL and credential rotation do not change the user's chosen model identity.
        return ['provider' => $provider, 'model' => $model];
    }

    public static function sameIdentity(mixed $left, mixed $right): bool
    {
        // PostgreSQL jsonb may reorder object keys; identity is the two exact strings.
        return is_array($left) && is_array($right)
            && is_string($left['provider'] ?? null) && is_string($left['model'] ?? null)
            && $left['provider'] === ($right['provider'] ?? null)
            && $left['model'] === ($right['model'] ?? null);
    }

    /** Caller holds the tenant row lock, also used when registering material. */
    public function assertChangeAllowed(Tenant $tenant, array $identity): void
    {
        $stored = $tenant->settings['knowledge_embedding_identity'] ?? null;
        $oldKey = TenantAiKey::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('slot', 'embedding')->first();
        $stored ??= $oldKey ? self::identity($oldKey->provider, (string) ($oldKey->metadata['model'] ?? '')) : null;
        $hasMaterials = KnowledgeDocument::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists();
        if ($hasMaterials && ! self::sameIdentity($stored, $identity)) {
            $model = is_array($stored) ? ($stored['model'] ?? '未知') : '未知';
            throw ValidationException::withMessages([
                'model' => "当前知识库已用 {$model} 模型生成向量。更换模型前，请先删除知识库中的材料，再重新导入。",
            ]);
        }
    }

    public static function validVector(mixed $vector): bool
    {
        if (! is_array($vector) || ! array_is_list($vector) || count($vector) !== self::DIMENSIONS) {
            return false;
        }
        foreach ($vector as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                return false;
            }
        }

        return true;
    }
}
