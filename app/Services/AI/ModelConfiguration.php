<?php

namespace App\Services\AI;

use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ModelConfiguration
{
    public const SLOTS = ['text', 'vision', 'embedding', 'visibility'];

    public function __construct(private readonly SafeOutboundHttpClient $outbound) {}

    public function active(string $slot, ?int $tenantId = null): ?TenantAiKey
    {
        $tenantId ??= TenantContext::has() ? TenantContext::id() : null;
        if (! $tenantId) {
            return null;
        }

        return TenantAiKey::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('slot', $slot)->active()->first();
    }

    /** Validation never calls a model or sends credentials to an endpoint. */
    public function validate(array $input, int $tenantId): array
    {
        $data = Validator::make($input, [
            'slot' => ['required', Rule::in(self::SLOTS)],
            'provider' => ['required', Rule::in(array_keys(config('ai-providers')))],
            'base_url' => ['required', 'string', 'max:2048'],
            'model' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._:\/\-]*$/'],
            'api_key' => ['nullable', 'string', 'min:1', 'max:500', 'regex:/^[^\s\x00-\x1f\x7f]+$/'],
            'label' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'json_mode' => ['sometimes', 'boolean'],
            'verification_token' => ['nullable', 'string', 'max:100'],
        ])->validate();
        $data['base_url'] = rtrim(trim($data['base_url']), '/');
        $parts = parse_url($data['base_url']);
        if (! is_array($parts) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['base_url' => '模型地址不能包含账号、密码、查询参数或片段。']);
        }
        try {
            $this->outbound->withEnforcement()->resolveTarget($data['base_url']);
        } catch (OutboundRequestBlockedException|OutboundRequestFailedException) {
            throw ValidationException::withMessages(['base_url' => '模型地址未通过出站检查。请检查域名；本机或内网服务需要管理员配置精确的主机白名单。']);
        }
        if ($data['slot'] === 'visibility' && $data['provider'] !== 'volces') {
            throw ValidationException::withMessages(['provider' => '豆包监测需要火山方舟配置。']);
        }
        if ($data['slot'] === 'visibility') {
            try {
                (new \App\Services\Geo\AiVisibility\AiProviderEndpointPolicy(
                    config('geo.visibility.allowed_hosts', ['ark.cn-beijing.volces.com']),
                ))->assertAllowed($data['base_url']);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['base_url' => '豆包监测地址必须使用 HTTPS，并在管理员配置的方舟主机白名单内。']);
            }
        }
        if ($data['slot'] === 'embedding' && ! config("ai-providers.{$data['provider']}.embeddings")) {
            throw ValidationException::withMessages(['provider' => '该供应商没有向量接口，请为知识库选择其他供应商。']);
        }
        $old = TenantAiKey::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('slot', $data['slot'])->first();
        if (empty($data['api_key'])) {
            // Never forward an old provider's credential to a newly entered endpoint.
            if (! $old || $old->provider !== $data['provider'] || ($old->metadata['base_url'] ?? '') !== $data['base_url']) {
                throw ValidationException::withMessages(['api_key' => '请填写此模型服务的 API Key。']);
            }
            $data['api_key'] = $old->getDecryptedKey();
        }
        $data['json_mode'] = $data['provider'] === 'custom'
            ? (bool) ($data['json_mode'] ?? false) : (bool) config("ai-providers.{$data['provider']}.json_mode");

        return $data;
    }

    public function save(int $tenantId, array $input): TenantAiKey
    {
        $data = $this->validate($input, $tenantId);

        return DB::transaction(function () use ($tenantId, $data): TenantAiKey {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            if ($data['slot'] === 'embedding') {
                $identity = EmbeddingModelPolicy::identity($data['provider'], $data['model']);
                app(EmbeddingModelPolicy::class)->assertChangeAllowed($tenant, $identity);
                $known = in_array($data['model'], config("ai-providers.{$data['provider']}.embedding_models", []), true)
                    && $data['base_url'] === config("ai-providers.{$data['provider']}.base_url");
                $old = TenantAiKey::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('slot', 'embedding')->first();
                $fingerprint = $this->fingerprint($tenantId, $data);
                $verified = $old && hash_equals((string) ($old->metadata['embedding_verification'] ?? ''), $fingerprint);
                $proof = Cache::get('model-connection:'.($data['verification_token'] ?? ''));
                if (! $known && ! $verified && (! is_string($proof) || ! hash_equals($fingerprint, $proof))) {
                    throw ValidationException::withMessages(['model' => '知识库只接受 1024 维向量。请先测试连接，确认模型支持 dimensions=1024 后再保存。']);
                }
                $settings = $tenant->settings ?? [];
                $settings['knowledge_embedding_identity'] = $identity;
                $tenant->update(['settings' => $settings]);
            }
            $metadata = ['base_url' => $data['base_url'], 'model' => $data['model'], 'json_mode' => $data['json_mode']];
            if ($data['slot'] === 'embedding') {
                $metadata += ['dimensions' => EmbeddingModelPolicy::DIMENSIONS, 'embedding_verification' => $this->fingerprint($tenantId, $data)];
            }

            return TenantAiKey::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'slot' => $data['slot']],
                ['provider' => $data['provider'], 'encrypted_key' => $data['api_key'],
                    'label' => $data['label'] ?? null, 'is_active' => $data['is_active'] ?? true, 'metadata' => $metadata],
            );
        });
    }

    /** Only called after a successful embedding probe with a validated 1024-vector. */
    public function verificationToken(int $tenantId, array $data): string
    {
        $token = 'test-'.bin2hex(random_bytes(24));
        Cache::put('model-connection:'.$token, $this->fingerprint($tenantId, $data), now()->addMinutes(10));

        return $token;
    }

    public function fingerprint(int $tenantId, array $data): string
    {
        return hash_hmac('sha256', json_encode([$tenantId, $data['slot'], $data['provider'], $data['base_url'], $data['model'], $data['api_key']]), (string) config('app.key'));
    }

    /** Shares the lock used by save(), closing first-upload/configuration races. */
    public function createDocument(array $attributes): KnowledgeDocument
    {
        return DB::transaction(function () use ($attributes): KnowledgeDocument {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($attributes['tenant_id']);
            $key = $this->active('embedding', $tenant->id);
            if (! $key) {
                throw ValidationException::withMessages(['model' => '未配置向量模型']);
            }
            $identity = EmbeddingModelPolicy::identity($key->provider, $key->metadata['model']);
            app(EmbeddingModelPolicy::class)->assertChangeAllowed($tenant, $identity);
            $settings = $tenant->settings ?? [];
            $settings['knowledge_embedding_identity'] = $identity;
            $tenant->update(['settings' => $settings]);
            $attributes['metadata'] = array_merge($attributes['metadata'] ?? [], ['embedding_identity' => $identity]);

            return KnowledgeDocument::create($attributes);
        });
    }

    public function publicConfiguration(TenantAiKey $key): array
    {
        $secret = $key->getDecryptedKey();

        return ['id' => $key->id, 'slot' => $key->slot, 'provider' => $key->provider,
            'provider_name' => config("ai-providers.{$key->provider}.name", $key->provider),
            'label' => $key->label, 'is_active' => $key->is_active,
            'key_preview' => strlen($secret) > 8 ? substr($secret, 0, 4).'••••'.substr($secret, -4) : '••••',
            'base_url' => $key->metadata['base_url'] ?? '', 'model' => $key->metadata['model'] ?? '',
            'json_mode' => (bool) ($key->metadata['json_mode'] ?? false)];
    }

    public function assertDocumentModel(KnowledgeDocument $document): void
    {
        $key = $this->active('embedding', $document->tenant_id);
        if (! $key) {
            throw new \App\Exceptions\IngestionFailure('未配置向量模型');
        }
        $identity = EmbeddingModelPolicy::identity($key->provider, $key->metadata['model']);
        if (! EmbeddingModelPolicy::sameIdentity($document->metadata['embedding_identity'] ?? null, $identity)) {
            throw new \App\Exceptions\IngestionFailure('材料的向量模型与当前配置不一致，请删除这份材料后重新导入。');
        }
    }
}
