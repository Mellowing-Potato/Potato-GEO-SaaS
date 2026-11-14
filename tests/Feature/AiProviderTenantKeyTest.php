<?php

namespace Tests\Feature;

use App\Models\TenantAiKey;
use App\Services\AI\AiProviderServiceImpl;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\UsesWorkspaceModels;
use Tests\TestCase;

class AiProviderTenantKeyTest extends TestCase
{
    use RefreshDatabase, UsesWorkspaceModels;

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    public function test_generation_uses_workspace_text_and_vision_keys_independently(): void
    {
        $this->useWorkspaceModels();
        Http::fake(['*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'tenant key response'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 3, 'total_tokens' => 7],
        ])]);
        $provider = app(AiProviderServiceImpl::class);
        $text = $provider->generate('hello');
        $vision = $provider->generate('image', options: ['images' => ['data:image/jpeg;base64,AAAA']]);
        $this->assertTrue($text->success);
        $this->assertTrue($vision->success);
        $this->assertSame('tenant key response', $text->content);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer sk-test-text' && $request['model'] === 'test-text');
        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer sk-test-vision' && $request['model'] === 'test-vision');
    }

    public function test_embeddings_use_their_own_workspace_key_and_model(): void
    {
        $this->useWorkspaceModels();
        $vector = array_pad([0.1, 0.2, 0.3], 1024, 0);
        Http::fake(['*/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => $vector]], 'usage' => ['total_tokens' => 5]])]);
        $result = app(AiProviderServiceImpl::class)->embed('hello');
        $this->assertSame($vector, $result['embedding']);
        $this->assertSame(5, $result['tokens']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer sk-test-embedding'
            && $request['model'] === 'test-embedding' && $request['dimensions'] === 1024);
    }

    public function test_pick_best_model_is_the_saved_text_model_and_ignores_old_unassigned_keys(): void
    {
        $tenant = $this->useWorkspaceModels();
        TenantAiKey::create(['tenant_id' => $tenant->id, 'provider' => 'deepseek', 'encrypted_key' => 'sk-test-unassigned', 'is_active' => true]);
        $this->assertSame(['provider' => 'custom', 'model' => 'test-text'], app(AiProviderServiceImpl::class)->pickBestModel($tenant, 'other'));
        TenantAiKey::where('slot', 'text')->delete();
        $this->assertSame(['provider' => '', 'model' => ''], app(AiProviderServiceImpl::class)->pickBestModel($tenant));
        $this->assertFalse(app(AiProviderServiceImpl::class)->generate('hello')->success);
        Http::assertNothingSent();
    }

    public function test_structured_generation_passes_through_requested_max_tokens(): void
    {
        $this->useWorkspaceModels();
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 3, 'total_tokens' => 7]])]);
        $result = app(AiProviderServiceImpl::class)->generateStructured('hello', '{"type":"object"}');
        $this->assertTrue($result->success);
        $this->assertSame(['ok' => true], $result->structuredOutput);
        Http::assertSent(fn ($request) => $request->header('Authorization')[0] === 'Bearer sk-test-text'
            && $request['model'] === 'test-text' && $request['max_tokens'] === 16384);
        Http::assertSentCount(1);
    }
}
