<?php

namespace App\Services\AI;

use App\Models\ModelConnectionProfile;
use App\Models\ModelSlotBinding;
use App\Models\Tenant;
use App\Services\Outbound\MagpieRequestScope;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ModelSources
{
    public function __construct(private readonly MagpieTargets $targets, private readonly MagpieGateway $gateway) {}

    public function profile(int $id, int $tenantId): ?ModelConnectionProfile
    {
        if ($tenantId !== TenantContext::id()) {
            return null;
        }

        return ModelConnectionProfile::query()->where('tenant_id', $tenantId)->find($id);
    }

    public function selection(string $slot, int $tenantId): ?ModelSlotBinding
    {
        // Scope both the binding and its profile by workspace. An explicit lookup is
        // also used by callers of the legacy resolver which pass a workspace ID.
        return ModelSlotBinding::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('slot', $slot)->first();
    }

    public function listing(): array
    {
        $tenantId = $this->workspace();
        $profiles = ModelConnectionProfile::query()->where('tenant_id', $tenantId)->orderBy('slot')->get();
        $bindings = ModelSlotBinding::query()->where('tenant_id', $tenantId)->get()->keyBy('slot');
        $current = [];
        foreach (ModelSlotBinding::SLOTS as $slot) {
            $current[$slot] = $this->publicBinding($bindings->get($slot));
        }

        return [
            'targets' => $this->targets->available(),
            'profiles' => $profiles->map($this->publicProfile(...))->all(),
            'bindings' => $current,
        ];
    }

    public function save(array $input, ?int $id = null): ModelConnectionProfile
    {
        $tenantId = $this->workspace();

        return DB::transaction(function () use ($tenantId, $input, $id): ModelConnectionProfile {
            $this->lockWorkspace($tenantId);
            $profile = $id === null ? new ModelConnectionProfile : $this->requireProfile($id);
            $data = Validator::make($input, [
                'slot' => [$id === null ? 'required' : 'sometimes', Rule::in(ModelSlotBinding::SLOTS)],
                'target_alias' => [$id === null ? 'required' : 'sometimes', 'string', 'max:100'],
                'label' => ['nullable', 'string', 'max:100'],
                'model' => ['nullable', fn ($attribute, $value, $fail) => ModelConnectionProfile::validModelId($value)
                    ?: $fail('模型 ID 无效。')],
                'api_key' => ['nullable', 'string', 'max:4096'],
                'enabled' => ['sometimes', 'boolean'],
                'revision' => ['sometimes', 'integer', 'min:1'],
            ])->validate();
            $this->checkRevision($profile, $data['revision'] ?? null);
            $slot = $data['slot'] ?? $profile->slot;
            if ($profile->exists && $profile->slot !== $slot) {
                throw ValidationException::withMessages(['slot' => '不能更改已保存连接的模型用途。']);
            }
            if (! $profile->exists && ModelConnectionProfile::query()->where('tenant_id', $tenantId)->where('slot', $slot)->exists()) {
                throw ValidationException::withMessages(['slot' => '此用途已有已保存的 Magpie 配置。']);
            }

            $target = $this->targets->forTenant($data['target_alias'] ?? $profile->target_alias);
            $sameConnection = $profile->exists && $profile->target_alias === $target->alias
                && $profile->base_url === $target->baseUrl && $profile->auth_mode === $target->authMode;
            $newKey = $data['api_key'] ?? null;
            if ($target->authMode === 'gateway_key') {
                try {
                    $canReuseKey = $sameConnection && $profile->target_revision === $target->revision;
                    MagpieRequestScope::gatewayCredential($newKey ?? ($canReuseKey ? $profile->credential : null));
                } catch (MagpieException $e) {
                    throw ValidationException::withMessages(['api_key' => $e->getMessage()]);
                }
                if ($newKey !== null && (! $sameConnection || $newKey !== $profile->credential)) {
                    $profile->credential = $newKey;
                }
            } else {
                if ($newKey !== null) {
                    throw ValidationException::withMessages(['api_key' => '此目标使用部署授权的本机身份，请清空网关 Key。']);
                }
                $profile->credential = null;
            }

            $profile->fill([
                'tenant_id' => $tenantId,
                'slot' => $slot,
                'provider' => 'magpie',
                'label' => array_key_exists('label', $data) ? $data['label'] : $profile->label,
                'target_alias' => $target->alias,
                'target_revision' => $target->revision,
                'base_url' => $target->baseUrl,
                'model' => array_key_exists('model', $data) ? $data['model'] : ($sameConnection ? $profile->model : null),
                'auth_mode' => $target->authMode,
                'enabled' => $data['enabled'] ?? $profile->enabled ?? true,
            ]);
            if (! $profile->exists || $profile->isDirty()) {
                $profile->revision = (int) $profile->revision + 1;
                $profile->save();
            }

            return $profile;
        });
    }

    public function bind(string $slot, array $input): ModelSlotBinding
    {
        abort_unless(in_array($slot, ModelSlotBinding::SLOTS, true), 404);
        $tenantId = $this->workspace();
        $data = Validator::make($input, [
            'source_type' => ['required', Rule::in(['legacy', 'profile', 'disabled'])],
            'profile_id' => ['required_if:source_type,profile', 'prohibited_unless:source_type,profile', 'integer', 'min:1'],
            'revision' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        $profile = null;
        if ($data['source_type'] === 'profile') {
            $profile = $this->requireProfile((int) $data['profile_id']);
            abort_unless($profile->slot === $slot, 404);
            $this->checkRevision($profile, $data['revision'] ?? null);
            $this->gateway->model($profile);
        }

        // No network calls under the workspace lock. Recheck the profile after the
        // live directory returns so a late result cannot activate a changed profile.
        return DB::transaction(function () use ($tenantId, $slot, $data, $profile): ModelSlotBinding {
            $this->lockWorkspace($tenantId);
            if ($profile !== null) {
                $current = $this->requireProfile($profile->id);
                $this->checkRevision($current, $profile->revision);
                $target = $this->targets->forTenant($current->target_alias);
                abort_unless($current->target_revision === $target->revision, 409, 'Magpie 的部署设置已修改，请重新保存连接。');
            }

            return ModelSlotBinding::query()->updateOrCreate(['tenant_id' => $tenantId, 'slot' => $slot], [
                'source_type' => $data['source_type'],
                'profile_id' => $profile?->id,
            ]);
        });
    }

    public function delete(int $id): ModelConnectionProfile
    {
        $tenantId = $this->workspace();

        return DB::transaction(function () use ($tenantId, $id): ModelConnectionProfile {
            $this->lockWorkspace($tenantId);
            $profile = $this->requireProfile($id);
            $binding = $this->selection($profile->slot, $tenantId);
            abort_if($binding?->source_type === 'profile' && (int) $binding->profile_id === $id, 409,
                '请先切换当前来源，再删除 Magpie 配置。');
            $profile->delete();

            return $profile;
        });
    }

    public function requireProfile(int $id): ModelConnectionProfile
    {
        $profile = $this->profile($id, $this->workspace());
        abort_if($profile === null, 404);

        return $profile;
    }

    public function publicProfile(ModelConnectionProfile $profile): array
    {
        $key = $profile->credential;
        $public = $profile->only([
            'id', 'slot', 'provider', 'label', 'target_alias', 'base_url', 'model', 'auth_mode', 'revision', 'enabled',
        ]);
        $public['target_revision'] = $profile->target_revision;
        $public['key_preview'] = match (true) {
            $key === null => null,
            strlen($key) > 8 => substr($key, 0, 4).'••••'.substr($key, -4),
            default => '••••',
        };

        return $public;
    }

    public function publicBinding(?ModelSlotBinding $binding): array
    {
        return ['source_type' => $binding?->source_type ?? 'legacy', 'profile_id' => $binding?->profile_id];
    }

    private function workspace(): int
    {
        abort_unless(TenantContext::has(), 403);

        return TenantContext::id();
    }

    private function lockWorkspace(int $tenantId): void
    {
        Tenant::query()->lockForUpdate()->findOrFail($tenantId);
    }

    private function checkRevision(ModelConnectionProfile $profile, int|string|null $revision): void
    {
        abort_if($revision !== null && (int) $revision !== $profile->revision, 409, 'Magpie 配置已更新，请刷新后重试。');
    }
}
