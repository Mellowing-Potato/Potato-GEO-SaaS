<?php

namespace App\Services\AI;

use App\Models\ModelConnectionProfile;
use App\Models\SkillExecution;
use App\Support\TenantContext;

final class ModelConnectionResolver
{
    public function __construct(
        private readonly ModelConfiguration $legacy,
        private readonly ModelSources $sources,
        private readonly MagpieGateway $magpie,
    ) {}

    public function resolve(string $slot, ?int $tenantId = null, ?array $snapshot = null): ResolvedModelConnection
    {
        $tenantId ??= TenantContext::id();
        if (TenantContext::has() && $tenantId !== TenantContext::id()) {
            throw new ModelConnectionUnavailable('当前工作区没有此模型连接。');
        }
        if ($snapshot !== null && isset($snapshot['error'])) {
            throw new ModelConnectionUnavailable($snapshot['error']);
        }

        $binding = ($tenantId > 0 && in_array($slot, ['text', 'vision'], true)) ? $this->sources->selection($slot, $tenantId) : null;
        $connection = match ($binding?->source_type ?? 'legacy') {
            'profile' => $this->boundProfile($slot, $tenantId, $binding->profile_id),
            'legacy' => $this->legacyConnection($slot, $tenantId),
            'disabled' => throw new ModelConnectionUnavailable($this->label($slot).'已停用。'),
            default => throw new ModelConnectionUnavailable($this->label($slot).'来源无效。'),
        };

        if ($snapshot !== null) {
            $current = $connection->snapshot();
            // PostgreSQL JSONB can reorder object keys when a snapshot is read back.
            ksort($current);
            ksort($snapshot);
            if ($current !== $snapshot) {
                throw new ModelConnectionUnavailable('模型连接已变更，本次任务已停止。请确认当前模型后重新提交。');
            }
        }

        return $connection;
    }

    public function fromProfile(ModelConnectionProfile $profile): ResolvedModelConnection
    {
        $revision = $this->magpie->targetRevision($profile);
        if (! ModelConnectionProfile::validModelId($profile->model)) {
            throw new MagpieException('model_required');
        }

        return new ResolvedModelConnection(
            tenantId: $profile->tenant_id,
            slot: $profile->slot,
            provider: 'magpie',
            model: $profile->model,
            baseUrl: $profile->base_url,
            fingerprint: hash('sha256', json_encode([
                $profile->tenant_id, $profile->id, $profile->slot, $profile->revision, $profile->model, $profile->base_url, $revision,
            ], JSON_THROW_ON_ERROR)),
            profile: $profile,
            deploymentRevision: $revision,
        );
    }

    public function capabilities(ResolvedModelConnection $connection, bool $requiresImage = false): array
    {
        $model = $connection->profile === null
            ? ['image_input' => $connection->slot === 'vision', 'json_object' => $connection->jsonObject]
            : $this->magpie->model($connection->profile);
        if ($requiresImage && ($model['image_input'] ?? null) !== true) {
            throw new ModelConnectionUnavailable(($model['image_input'] ?? null) === false
                ? '当前 Magpie 模型不支持图片输入。' : '当前 Magpie 模型的图片输入能力尚未确认。');
        }

        return $model;
    }

    /** Null means that a historical execution predates connection snapshots. */
    public function executionSnapshot(?int $executionId): ?array
    {
        if ($executionId === null) {
            return null;
        }

        $execution = SkillExecution::query()->where('tenant_id', TenantContext::id())->find($executionId);
        if ($execution === null) {
            throw new ModelConnectionUnavailable('当前工作区没有此模型任务。');
        }

        return $execution->model_connection_snapshot;
    }

    /** Capture the saved selection without network checks or any paid request. */
    public function snapshot(string $slot): array
    {
        try {
            return $this->resolve($slot)->snapshot();
        } catch (ModelConnectionUnavailable|MagpieException $e) {
            return ['tenant_id' => TenantContext::id(), 'slot' => $slot, 'error' => $e->getMessage()];
        }
    }

    /** @return array{available:bool,message:?string} */
    public function availability(
        string $slot,
        ?int $tenantId = null,
        ?array $snapshot = null,
        bool $checkCapabilities = true,
    ): array {
        try {
            $connection = $this->resolve($slot, $tenantId, $snapshot);
            if ($checkCapabilities) {
                $this->capabilities($connection, $slot === 'vision');
            }

            return ['available' => true, 'message' => null];
        } catch (ModelConnectionUnavailable|MagpieException $e) {
            return ['available' => false, 'message' => $e->getMessage()];
        }
    }

    private function boundProfile(string $slot, int $tenantId, ?int $profileId): ResolvedModelConnection
    {
        $profile = $profileId === null ? null : $this->sources->profile($profileId, $tenantId);
        if ($profile === null || $profile->slot !== $slot || $profile->provider !== 'magpie') {
            throw new ModelConnectionUnavailable('绑定的 Magpie 连接不存在或不适用于当前模型用途。');
        }
        if (! $profile->enabled) {
            throw new ModelConnectionUnavailable('当前 Magpie 连接已停用。');
        }

        return $this->fromProfile($profile);
    }

    private function legacyConnection(string $slot, ?int $tenantId): ResolvedModelConnection
    {
        $key = $this->legacy->active($slot, $tenantId);
        if ($key === null) {
            throw new ModelConnectionUnavailable('未配置'.$this->label($slot));
        }

        $data = ['slot' => $slot, 'provider' => $key->provider, 'model' => $key->metadata['model'],
            'base_url' => $key->metadata['base_url'], 'api_key' => $key->getDecryptedKey()];

        return new ResolvedModelConnection(
            tenantId: $key->tenant_id,
            slot: $slot,
            provider: $data['provider'],
            model: $data['model'],
            baseUrl: $data['base_url'],
            fingerprint: $this->legacy->fingerprint($key->tenant_id, $data),
            legacy: $key,
            jsonObject: (bool) ($key->metadata['json_mode'] ?? false),
        );
    }

    private function label(string $slot): string
    {
        return match ($slot) {
            'vision' => '视觉模型',
            'embedding' => '向量模型',
            'visibility' => '豆包监测模型',
            default => '文本模型',
        };
    }
}
