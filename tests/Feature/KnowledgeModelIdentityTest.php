<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\ModelConfiguration;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesWorkspaceModels;
use Tests\TestCase;

class KnowledgeModelIdentityTest extends TestCase
{
    use RefreshDatabase, UsesWorkspaceModels;

    public function test_same_dimension_from_another_provider_or_model_is_never_recalled(): void
    {
        $tenant = $this->useWorkspaceModels();
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create(['owner_id' => $owner->id, 'name' => '向量隔离']);
        $base = KnowledgeBase::create(['project_id' => $project->id, 'name' => '材料', 'status' => 'active']);
        $expected = $this->chunk($tenant->id, $project->id, $base->id, '匹配模型');
        foreach ([['provider' => 'other', 'model' => 'test-embedding'], ['provider' => 'custom', 'model' => 'other-model']] as $identity) {
            $wrong = $this->chunk($tenant->id, $project->id, $base->id, '不匹配模型');
            $document = $wrong->document;
            $document->update(['metadata' => ['embedding_identity' => $identity]]);
        }
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->willReturn([
            'embedding' => $this->vector(), 'tokens' => 1, 'provider' => 'custom', 'model' => 'test-embedding',
        ]);
        $results = (new KnowledgeRetrievalServiceImpl($provider))->retrieve('问题', filters: ['project_id' => $project->id]);
        $this->assertCount(1, $results);
        $this->assertSame($expected->id, $results[0]->chunkId);
    }

    public function test_a_query_in_flight_cannot_match_material_imported_with_a_new_model(): void
    {
        $tenant = $this->useWorkspaceModels(provider: 'openai', url: 'https://api.openai.com/v1');
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create(['owner_id' => $owner->id, 'name' => '在途查询']);
        $base = KnowledgeBase::create(['project_id' => $project->id, 'name' => '材料', 'status' => 'active']);
        $old = $this->chunk($tenant->id, $project->id, $base->id, '原模型');
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->willReturnCallback(function () use ($tenant, $project, $base, $old): array {
            KnowledgeDocument::findOrFail($old->document_id)->delete();
            app(ModelConfiguration::class)->save($tenant->id, ['slot' => 'embedding', 'provider' => 'openai',
                'model' => 'text-embedding-3-small', 'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk-test-replacement']);
            $this->chunk($tenant->id, $project->id, $base->id, '新模型');
            return ['embedding' => $this->vector(), 'tokens' => 1, 'provider' => 'openai', 'model' => 'test-embedding'];
        });
        $this->assertSame([], (new KnowledgeRetrievalServiceImpl($provider))->retrieve('问题', filters: ['project_id' => $project->id]));
        $this->assertSame(1, KnowledgeDocument::count());
    }

    private function vector(): array
    {
        return array_pad([1.0], 1024, 0.0);
    }

    public function test_failed_or_unknown_query_is_recorded_and_never_reported_as_empty_success(): void
    {
        $tenant = $this->useWorkspaceModels();
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $project = Project::create(['owner_id' => $owner->id, 'name' => '失败查询']);
        \Illuminate\Support\Facades\Http::fake(['*/embeddings' => \Illuminate\Support\Facades\Http::sequence()
            ->push(['error' => ['message' => 'private detail']], 401)
            ->push(['error' => ['message' => 'private detail']], 503)]);
        $service = new KnowledgeRetrievalServiceImpl(app(\App\Services\AI\AiProviderServiceImpl::class));
        foreach (['模型服务拒绝了请求', '结果未知，可手动重新执行'] as $message) {
            try {
                $service->retrieve('问题', filters: ['project_id' => $project->id, 'user_id' => $owner->id]);
                $this->fail('Model failures must not become empty search results.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
                $this->assertStringNotContainsString('private detail', $error->getMessage());
            }
        }
        \Illuminate\Support\Facades\Http::assertSentCount(2);
        $logs = \App\Models\ModelCallLog::orderBy('id')->get();
        $this->assertSame(['failed', 'unknown'], $logs->pluck('status')->all());
        $this->assertSame([null, null], $logs->pluck('total_tokens')->all());
        $this->assertSame([$project->id], $logs->pluck('project_id')->unique()->values()->all());
    }

    private function chunk(int $tenant, int $project, int $base, string $title): KnowledgeChunk
    {
        $document = app(ModelConfiguration::class)->createDocument(['tenant_id' => $tenant, 'project_id' => $project,
            'knowledge_base_id' => $base, 'title' => $title, 'source_type' => 'manual', 'status' => 'ready', 'review_status' => 'approved']);
        return KnowledgeChunk::create(['tenant_id' => $tenant, 'document_id' => $document->id, 'chunk_index' => 0,
            'content' => $title, 'embedding' => $this->vector()]);
    }
}
