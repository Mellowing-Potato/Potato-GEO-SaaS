<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantAiKey;
use App\Services\AI\EmbeddingModelPolicy;
use App\Services\AI\ModelConfiguration;
use App\Services\AI\ModelConnectionTester;
use App\Support\Concerns\RecordsSensitiveAction;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantAiKeyController extends Controller
{
    use RecordsSensitiveAction;

    public function __construct(private readonly ModelConfiguration $configuration) {}

    public function index(): JsonResponse
    {
        $keys = TenantAiKey::query()->whereIn('slot', ModelConfiguration::SLOTS)->orderBy('slot')->get();

        return response()->json([
            'data' => $keys->map(fn (TenantAiKey $key) => $this->configuration->publicConfiguration($key)),
            'presets' => config('ai-providers'),
            'embedding_notice' => EmbeddingModelPolicy::NOTICE,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $key = $this->configuration->save(TenantContext::id(), $request->all());
        $this->auditSensitive('tenant_ai_key.saved', $key, ['provider' => $key->provider, 'slot' => $key->slot]);

        return response()->json($this->configuration->publicConfiguration($key) + ['message' => '模型配置已保存'], 201);
    }

    public function testConnection(Request $request, ModelConnectionTester $tester): JsonResponse
    {
        $data = $this->configuration->validate($request->all(), TenantContext::id());
        $result = $tester->test($data, TenantContext::id(), (int) $request->user()->id);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function destroy(TenantAiKey $key): JsonResponse
    {
        abort_unless(in_array($key->slot, ModelConfiguration::SLOTS, true), 404);
        $this->auditSensitive('tenant_ai_key.deleted', $key, ['provider' => $key->provider, 'slot' => $key->slot]);
        // Knowledge model identity intentionally survives credential deletion.
        $key->delete();

        return response()->json(['message' => 'API Key 已删除']);
    }

    public function toggle(TenantAiKey $key): JsonResponse
    {
        abort_unless(in_array($key->slot, ModelConfiguration::SLOTS, true), 404);
        $key->update(['is_active' => ! $key->is_active]);
        $this->auditSensitive('tenant_ai_key.toggled', $key, ['provider' => $key->provider, 'slot' => $key->slot, 'is_active' => $key->is_active]);

        return response()->json(['id' => $key->id, 'is_active' => $key->is_active,
            'message' => $key->is_active ? '已启用' : '已禁用']);
    }
}
