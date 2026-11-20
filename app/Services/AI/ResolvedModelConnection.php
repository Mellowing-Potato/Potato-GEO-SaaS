<?php

namespace App\Services\AI;

use App\Models\ModelConnectionProfile;
use App\Models\TenantAiKey;

final readonly class ResolvedModelConnection
{
    public function __construct(
        public int $tenantId,
        public string $slot,
        public string $provider,
        public string $model,
        public string $baseUrl,
        public string $fingerprint,
        public ?ModelConnectionProfile $profile = null,
        private ?TenantAiKey $legacy = null,
        public ?string $deploymentRevision = null,
        public bool $jsonObject = false,
    ) {}

    public function legacyConfiguration(): array
    {
        if ($this->legacy === null) {
            throw new \LogicException('A legacy connection is required.');
        }

        return ['slot' => $this->slot, 'provider' => $this->provider, 'model' => $this->model,
            'base_url' => $this->baseUrl, 'api_key' => $this->legacy->getDecryptedKey()];
    }

    public function snapshot(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'slot' => $this->slot,
            'provider' => $this->provider,
            'model' => $this->model,
            'source' => $this->profile !== null ? 'profile' : 'legacy',
            'connection_id' => $this->profile?->id ?? $this->legacy?->id,
            'revision' => $this->profile?->revision,
            'deployment_revision' => $this->deploymentRevision,
            'endpoint_fingerprint' => hash('sha256', $this->baseUrl),
            'credential_fingerprint' => $this->fingerprint,
            'json_object' => $this->jsonObject,
        ];
    }
}
