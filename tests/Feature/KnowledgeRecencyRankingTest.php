<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\ModelConfiguration;
use App\Services\Contracts\AiProvider;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UsesWorkspaceModels;
use Tests\TestCase;

class KnowledgeRecencyRankingTest extends TestCase
{
    use RefreshDatabase, UsesWorkspaceModels;

    private Tenant $tenant;

    private KnowledgeBase $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'UTC'));
        $this->tenant = $this->useWorkspaceModels();
        $this->base = $this->base($this->tenant);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        TenantContext::forget();
        parent::tearDown();
    }

    #[DataProvider('materialAges')]
    public function test_retrieve_applies_the_age_bonus_to_real_material_vectors(int $ageSeconds, float $bonus): void
    {
        $chunk = $this->material($this->base, $ageSeconds, 0.70);

        $results = $this->retrieval()->retrieve('测试查询', topK: 5, threshold: 0.65, filters: ['project_id' => $this->base->project_id]);

        $this->assertCount(1, $results);
        $this->assertSame($chunk->id, $results[0]->chunkId);
        $this->assertEqualsWithDelta(0.70, $results[0]->vectorScore, 0.000001);
        $this->assertEqualsWithDelta($bonus, $results[0]->relevanceScore - $results[0]->vectorScore, 0.000001);
        $this->assertEqualsWithDelta(0.70 + $bonus, $results[0]->relevanceScore, 0.000001);
        $this->assertSame($chunk->document->created_at->toISOString(), $results[0]->documentCreatedAt);
        Http::assertNothingSent();
    }

    public static function materialAges(): array
    {
        return [
            'ten days' => [10 * 86400, 0.03],
            'sixty days' => [60 * 86400, 0.01],
            'one hundred twenty days' => [120 * 86400, 0.0],
            'twenty nine days' => [29 * 86400, 0.03],
            'just before thirty days' => [30 * 86400 - 1, 0.03],
            'exactly thirty days' => [30 * 86400, 0.01],
            'eighty nine days' => [89 * 86400, 0.01],
            'just before ninety days' => [90 * 86400 - 1, 0.01],
            'exactly ninety days' => [90 * 86400, 0.0],
            'created now' => [0, 0.03],
            'one second in the future' => [-1, 0.0],
            'one day in the future' => [-86400, 0.0],
            'far future' => [-120 * 86400, 0.0],
        ];
    }

    public function test_hybrid_reranking_prefers_recent_evidence_when_vector_scores_are_close(): void
    {
        // All three fit the nearest-neighbour candidate window. Their vector-only
        // ranking is old > medium > recent; the documented age bonuses reverse it.
        $old = $this->material($this->base, 120 * 86400, 0.715);
        $medium = $this->material($this->base, 60 * 86400, 0.71);
        $recent = $this->material($this->base, 10 * 86400, 0.70);

        $results = $this->retrieval()->retrieve('测试查询', topK: 3, threshold: 0.65, filters: ['project_id' => $this->base->project_id]);

        $this->assertSame([$recent->id, $medium->id, $old->id], array_column($results, 'chunkId'));
        foreach ([0.73, 0.72, 0.715] as $index => $expected) {
            $this->assertEqualsWithDelta($expected, $results[$index]->relevanceScore, 0.000001);
        }
        $this->assertLessThan($results[2]->vectorScore, $results[0]->vectorScore);
        Http::assertNothingSent();
    }

    public function test_recent_material_cannot_bypass_workspace_project_model_or_document_filters(): void
    {
        $wanted = $this->material($this->base, 10 * 86400, 0.70);
        foreach ([['provider' => 'other', 'model' => 'test-embedding'], ['provider' => 'custom', 'model' => 'other-model']] as $identity) {
            $wrongModel = $this->material($this->base, 0, 1.0);
            $wrongModel->document->update(['metadata' => ['embedding_identity' => $identity]]);
        }
        $archived = $this->material($this->base, 0, 1.0);
        $archived->document->update(['review_status' => 'archived']);
        $pending = $this->material($this->base, 0, 1.0);
        $pending->document->update(['status' => 'pending']);
        $inactiveBase = $this->base($this->tenant, $this->base->project_id);
        $this->material($inactiveBase, 0, 1.0);
        $inactiveBase->update(['status' => 'archived']);
        $this->material($this->base($this->tenant), 0, 1.0);
        $foreign = $this->useWorkspaceModels();
        $this->material($this->base($foreign), 0, 1.0);
        TenantContext::set($this->tenant);

        $results = $this->retrieval()->retrieve('测试查询', topK: 5, threshold: 0.65, filters: ['project_id' => $this->base->project_id]);

        $this->assertSame([$wanted->id], array_column($results, 'chunkId'));
        $this->assertEqualsWithDelta(0.73, $results[0]->relevanceScore, 0.000001);
        Http::assertNothingSent();
    }

    private function base(Tenant $tenant, ?int $projectId = null): KnowledgeBase
    {
        $projectId ??= Project::create([
            'tenant_id' => $tenant->id,
            'owner_id' => User::factory()->create(['tenant_id' => $tenant->id])->id,
            'name' => '时效评分测试项目',
        ])->id;

        return KnowledgeBase::create(['tenant_id' => $tenant->id, 'project_id' => $projectId, 'name' => '时效测试库', 'status' => 'active']);
    }

    private function material(KnowledgeBase $base, int $ageSeconds, float $similarity): KnowledgeChunk
    {
        /** @var KnowledgeDocument $document */
        $document = app(ModelConfiguration::class)->createDocument([
            'tenant_id' => $base->tenant_id, 'project_id' => $base->project_id,
            'knowledge_base_id' => $base->id, 'title' => '时效测试材料',
            'source_type' => 'manual', 'status' => 'ready', 'review_status' => 'approved',
        ]);
        $document->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();

        return KnowledgeChunk::create([
            'tenant_id' => $base->tenant_id, 'document_id' => $document->id,
            'chunk_index' => 0, 'content' => '合成材料内容', 'section_title' => null,
            'embedding' => array_pad([$similarity, sqrt(1 - $similarity ** 2)], 1024, 0.0),
        ]);
    }

    private function retrieval(): KnowledgeRetrievalServiceImpl
    {
        $provider = $this->createMock(AiProvider::class);
        $provider->expects($this->once())->method('embed')->with('测试查询', 'embedding', $this->isArray())
            ->willReturn(['embedding' => array_pad([1.0], 1024, 0.0), 'provider' => 'custom', 'model' => 'test-embedding']);

        return new KnowledgeRetrievalServiceImpl($provider);
    }
}
