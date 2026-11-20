<?php

namespace App\Services\Outbound;

use App\Models\ModelConnectionProfile;
use App\Services\AI\MagpieException;
use App\Services\AI\MagpieTarget;
use App\Services\AI\MagpieTargets;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use SensitiveParameter;

/** Authorizes one exact Magpie operation for a persisted, current tenant profile. */
final readonly class MagpieRequestScope
{
    private const METHODS = [
        '/api/hello' => 'GET',
        '/v1/models' => 'GET',
        '/v1/chat/completions' => 'POST',
    ];

    private function __construct(
        private MagpieTargets $targets,
        public MagpieTarget $target,
        private int $tenantId,
        private int $profileId,
        private string $slot,
        private string $profileRevision,
        private string $path,
        public ?string $pinnedIp,
    ) {}

    public static function forProfile(
        MagpieTargets $targets,
        ModelConnectionProfile $profile,
        string $path,
        ?string $pinnedIp = null,
    ): self {
        $tenantId = TenantContext::id();
        if ($tenantId === null || $tenantId <= 0 || $tenantId !== (int) $profile->tenant_id
            || ! $profile->exists || ! in_array($profile->slot, ['text', 'vision'], true)
            || ! isset(self::METHODS[$path])) {
            throw new MagpieException('target_unauthorized');
        }

        $scope = new self($targets, $targets->forTenant((string) $profile->target_alias), $tenantId,
            (int) $profile->id, $profile->slot, (string) $profile->revision, $path, $pinnedIp);
        if ($scope->currentProfile()->model !== $profile->model) {
            throw new MagpieException('connection_changed');
        }

        return $scope;
    }

    public function url(): string
    {
        return $this->target->origin.$this->path;
    }

    public function method(): string
    {
        return self::METHODS[$this->path];
    }

    public function currentProfile(): ModelConnectionProfile
    {
        if (TenantContext::id() !== $this->tenantId) {
            throw new MagpieException('target_unauthorized');
        }
        $target = $this->targets->forTenant($this->target->alias);
        $profile = ModelConnectionProfile::query()
            ->where('tenant_id', $this->tenantId)
            ->where('slot', $this->slot)
            ->find($this->profileId);
        if ($profile === null || $profile->provider !== 'magpie'
            || (string) $profile->revision !== $this->profileRevision
            || $profile->target_revision !== $target->revision
            || $this->target->revision !== $target->revision
            || $profile->target_alias !== $target->alias
            || $profile->base_url !== $target->baseUrl
            || $profile->auth_mode !== $target->authMode) {
            throw new MagpieException('connection_changed');
        }
        if (! $profile->enabled) {
            throw new MagpieException('connection_disabled');
        }

        return $profile;
    }

    public function bearer(): string
    {
        $profile = $this->currentProfile();
        if ($this->target->authMode === 'loopback') {
            return 'magpie-yanji';
        }
        try {
            $credential = $profile->credential;
        } catch (DecryptException) {
            throw new MagpieException('invalid_credential');
        }

        return self::gatewayCredential($credential);
    }

    public static function gatewayCredential(#[SensitiveParameter] ?string $credential): string
    {
        if ($credential === null || $credential === '') {
            throw new MagpieException('credential_required');
        }
        if (preg_match('/\A[!-~]{1,4096}\z/', $credential) !== 1
            || str_starts_with(strtolower($credential), 'magpie-')
            || str_contains($credential, '*')) {
            throw new MagpieException('invalid_credential');
        }

        return $credential;
    }

    public function assertAuthorized(string $method, string $url): void
    {
        if ($method !== $this->method() || $url !== $this->url()) {
            throw new OutboundRequestBlockedException('magpie_operation_forbidden');
        }

        try {
            $this->currentProfile();
        } catch (MagpieException $e) {
            throw new OutboundRequestBlockedException('magpie_'.$e->reason);
        }
    }
}
