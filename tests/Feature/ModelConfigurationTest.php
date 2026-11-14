<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Models\TenantAiKey;
use App\Services\AI\EmbeddingModelPolicy;
use App\Services\AI\ModelConfiguration;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModelConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private ModelConfiguration $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(HostResolver::class, fn () => new class implements HostResolver {
            public function resolve(string $host): array { return ['93.184.216.34']; }
        });
        $this->tenant = Tenant::create(['name' => '模型测试工作区', 'subdomain' => 'model-test', 'status' => 'active']);
        TenantContext::set($this->tenant);
        $this->configuration = app(ModelConfiguration::class);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['slot' => 'embedding', 'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1', 'model' => 'text-embedding-3-small',
            'api_key' => 'sk-test-workspace-model'], $overrides);
    }

    private function material(): KnowledgeDocument
    {
        $base = KnowledgeBase::create(['tenant_id' => $this->tenant->id, 'name' => '测试知识库', 'type' => 'general']);

        return $this->configuration->createDocument(['tenant_id' => $this->tenant->id, 'knowledge_base_id' => $base->id,
            'title' => '示例材料', 'source_type' => 'upload', 'status' => 'pending']);
    }

    private function assertRejected(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Expected configuration to be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    public function test_same_provider_has_independent_encrypted_slots_and_no_shared_fallback(): void
    {
        foreach (['text', 'vision', 'embedding'] as $slot) {
            $key = $this->configuration->save($this->tenant->id, $this->input(['slot' => $slot, 'api_key' => 'sk-test-'.$slot]));
            $this->assertSame('sk-test-'.$slot, $key->getDecryptedKey());
            $this->assertArrayNotHasKey('encrypted_key', $key->toArray());
            $this->assertNotSame('sk-test-'.$slot, DB::table('tenant_ai_keys')->where('id', $key->id)->value('encrypted_key'));
        }
        $this->assertSame(3, TenantAiKey::count());
        $other = Tenant::create(['name' => '其他工作区', 'subdomain' => 'model-other', 'status' => 'active']);
        $this->assertNull($this->configuration->active('embedding', $other->id));
        TenantContext::forget();
        $this->assertNull($this->configuration->active('embedding'));
    }

    public function test_material_blocks_model_and_provider_changes_but_allows_key_rotation(): void
    {
        $first = $this->configuration->save($this->tenant->id, $this->input());
        $material = $this->material();
        $message = '当前知识库已用 text-embedding-3-small 模型生成向量。更换模型前，请先删除知识库中的材料，再重新导入。';
        $this->assertRejected(fn () => $this->configuration->save($this->tenant->id,
            $this->input(['model' => 'text-embedding-3-large'])), $message);
        $this->assertRejected(fn () => $this->configuration->save($this->tenant->id,
            $this->input(['provider' => 'custom'])), $message);
        $rotated = $this->configuration->save($this->tenant->id, $this->input(['api_key' => 'sk-test-rotated']));
        $this->assertSame($first->id, $rotated->id);
        $this->assertSame('sk-test-rotated', $rotated->getDecryptedKey());
        $this->assertSame(['provider' => 'openai', 'model' => 'text-embedding-3-small'], $material->metadata['embedding_identity']);
        $this->assertDatabaseCount('knowledge_documents', 1);
    }

    public function test_deleting_or_disabling_key_does_not_bypass_material_lock(): void
    {
        $key = $this->configuration->save($this->tenant->id, $this->input());
        $material = $this->material();
        $key->update(['is_active' => false]);
        $this->assertRejected(fn () => $this->configuration->save($this->tenant->id,
            $this->input(['model' => 'text-embedding-3-large'])), '当前知识库已用 text-embedding-3-small');
        $key->delete();
        $this->assertRejected(fn () => $this->configuration->save($this->tenant->id,
            $this->input(['model' => 'text-embedding-3-large'])), '当前知识库已用 text-embedding-3-small');
        $material->delete();
        $replacement = $this->configuration->save($this->tenant->id, $this->input(['model' => 'text-embedding-3-large']));
        $this->assertSame('text-embedding-3-large', $replacement->metadata['model']);
    }

    public function test_other_workspace_material_does_not_lock_this_workspace(): void
    {
        $this->configuration->save($this->tenant->id, $this->input());
        $this->material();
        $other = Tenant::create(['name' => '其他工作区', 'subdomain' => 'model-other', 'status' => 'active']);
        $key = $this->configuration->save($other->id, $this->input(['model' => 'text-embedding-3-large']));
        $this->assertSame($other->id, $key->tenant_id);
        $this->assertSame('text-embedding-3-small', $this->configuration->active('embedding')->metadata['model']);
    }

    public function test_material_registration_requires_active_embedding_key(): void
    {
        $this->assertRejected(fn () => $this->material(), '未配置向量模型');
        $key = $this->configuration->save($this->tenant->id, $this->input());
        $key->update(['is_active' => false]);
        $this->assertRejected(fn () => $this->material(), '未配置向量模型');
        $this->assertDatabaseCount('knowledge_documents', 0);
    }

    public function test_existing_material_with_unknown_identity_fails_closed(): void
    {
        $base = KnowledgeBase::create(['tenant_id' => $this->tenant->id, 'name' => '旧知识库', 'type' => 'general']);
        KnowledgeDocument::create(['tenant_id' => $this->tenant->id, 'knowledge_base_id' => $base->id,
            'title' => '旧材料', 'source_type' => 'upload', 'status' => 'ready']);
        $this->assertRejected(fn () => $this->configuration->save($this->tenant->id, $this->input()), '当前知识库已用 未知');
    }

    public function test_unverified_embedding_model_and_non_embedding_provider_are_rejected(): void
    {
        $this->assertRejected(fn () => $this->configuration->save($this->tenant->id,
            $this->input(['model' => 'text-embedding-ada-002'])), '只接受 1024 维');
        $this->assertRejected(fn () => $this->configuration->save($this->tenant->id,
            $this->input(['provider' => 'deepseek'])), '没有向量接口');
        $this->assertDatabaseCount('tenant_ai_keys', 0);
    }

    public function test_connection_proof_is_bound_to_tenant_key_model_and_url(): void
    {
        $input = $this->input(['provider' => 'custom', 'base_url' => 'https://models.example.test/v1', 'model' => 'test-embedding']);
        $proof = $this->configuration->verificationToken($this->tenant->id, $input);
        $input['verification_token'] = $proof;
        foreach (['api_key' => 'sk-test-changed', 'model' => 'test-other', 'base_url' => 'https://other.example.test/v1'] as $field => $value) {
            $this->assertRejected(fn () => $this->configuration->save($this->tenant->id,
                array_replace($input, [$field => $value])), '只接受 1024 维');
        }
        $other = Tenant::create(['name' => '其他工作区', 'subdomain' => 'proof-other', 'status' => 'active']);
        $this->assertRejected(fn () => $this->configuration->save($other->id, $input), '只接受 1024 维');
        $key = $this->configuration->save($this->tenant->id, $input);
        $this->assertSame(1024, $key->metadata['dimensions']);
        $this->assertArrayNotHasKey('embedding_verification', $this->configuration->publicConfiguration($key));
    }

    public function test_saved_key_is_never_reused_for_another_host_without_explicit_key(): void
    {
        $this->configuration->save($this->tenant->id, $this->input());
        $this->assertRejected(fn () => $this->configuration->save($this->tenant->id,
            $this->input(['base_url' => 'https://other.example.test/v1', 'api_key' => ''])), '请填写此模型服务');
        $same = $this->configuration->save($this->tenant->id, $this->input(['api_key' => '']));
        $this->assertSame('sk-test-workspace-model', $same->getDecryptedKey());
    }

    public function test_vector_validation_rejects_wrong_dimension_non_numbers_and_nonfinite_values(): void
    {
        $this->assertTrue(EmbeddingModelPolicy::validVector(array_fill(0, 1024, 0.25)));
        foreach ([[], [0.1], array_fill(0, 1536, 0.25), array_fill(0, 1024, '0.25'), array_fill(0, 1024, INF), array_fill(0, 1024, NAN)] as $vector) {
            $this->assertFalse(EmbeddingModelPolicy::validVector($vector));
        }
    }

    private function login(): void
    {
        $user = \App\Models\User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => 'admin']);
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
        config(['app.tenant_domain' => 'geo.example.test']);
        $this->withServerVariables(['HTTP_HOST' => 'geo.example.test'])->withHeader('X-Tenant', $this->tenant->subdomain);
    }

    public function test_existing_key_endpoints_enforce_the_material_lock_and_never_return_keys(): void
    {
        $this->login();
        $saved = $this->postJson('/api/v1/ai-keys', $this->input())->assertCreated()
            ->assertJsonPath('slot', 'embedding');
        $this->assertStringNotContainsString('sk-test-workspace-model', $saved->getContent());
        $this->material();
        $this->postJson('/api/v1/ai-keys', $this->input(['model' => 'text-embedding-3-large']))
            ->assertUnprocessable()->assertJsonValidationErrors('model');
        $index = $this->getJson('/api/v1/ai-keys')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('embedding_notice', EmbeddingModelPolicy::NOTICE);
        $this->assertStringNotContainsString('sk-test-workspace-model', $index->getContent());
        $this->deleteJson('/api/v1/ai-keys/'.$saved->json('id'))->assertOk();
        $this->postJson('/api/v1/ai-keys', $this->input(['model' => 'text-embedding-3-large']))
            ->assertUnprocessable()->assertJsonValidationErrors('model');
        $this->assertDatabaseCount('knowledge_documents', 1);
    }

    public function test_key_routes_do_not_list_modify_or_delete_other_workspace_credentials(): void
    {
        $other = Tenant::create(['name' => '其他工作区', 'subdomain' => 'api-other', 'status' => 'active']);
        $key = $this->configuration->save($other->id, $this->input(['api_key' => 'sk-test-other-workspace']));
        $this->login();
        $this->getJson('/api/v1/ai-keys')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/ai-keys/'.$key->id)->assertNotFound();
        $this->patchJson('/api/v1/ai-keys/'.$key->id.'/toggle')->assertNotFound();
        $this->assertTrue($key->fresh()->is_active);
        $this->assertSame('sk-test-other-workspace', $key->fresh()->getDecryptedKey());
    }

    public function test_local_environment_without_keys_returns_unconfigured_instead_of_mock_content(): void
    {
        $this->app['env'] = 'local';
        config(['app.env' => 'local', 'geo.test_mock_ai' => false]);
        $this->app->forgetInstance(\App\Services\Contracts\AiProvider::class);
        $provider = app(\App\Services\Contracts\AiProvider::class);
        $this->assertInstanceOf(\App\Services\AI\AiProviderServiceImpl::class, $provider);
        $result = $provider->generate('no key');
        $this->assertFalse($result->success);
        $this->assertNull($result->content);
        $this->assertSame('未配置文本模型', $result->errorMessage);
        $this->assertTrue($result->gateway['pre_send_rejection']);
    }
}
