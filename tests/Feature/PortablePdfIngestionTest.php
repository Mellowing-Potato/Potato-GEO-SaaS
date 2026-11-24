<?php

namespace Tests\Feature;

use App\Exceptions\IngestionFailure;
use App\Models\KnowledgeBase;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\MockAiProvider;
use App\Services\DTOs\AiProviderCallResult;
use App\Services\Knowledge\DocumentIngestionService;
use App\Services\Knowledge\VisionPageExtractor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PortablePdfIngestionTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\UsesWorkspaceModels;

    private object $provider;
    private KnowledgeBase $base;
    private Project $project;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $tenant = $this->useWorkspaceModels();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->project = Project::create(['owner_id' => $this->user->id, 'name' => 'PDF fixture', 'status' => 'active']);
        $this->base = KnowledgeBase::create(['tenant_id' => $tenant->id, 'project_id' => $this->project->id,
            'name' => 'PDF fixture', 'status' => 'active']);
        $this->provider = new class extends MockAiProvider {
            public int $visionCalls = 0;
            public int $embeddingCalls = 0;
            public function generate(string $prompt, ?string $system = null, array $options = []): AiProviderCallResult
            {
                $this->visionCalls++;
                throw new \LogicException('No vision request is allowed without Poppler.');
            }
            public function embedBatch(array $texts, string $model = 'embedding', array $options = []): array
            {
                $this->embeddingCalls++;
                return parent::embedBatch($texts, $model, $options);
            }
        };
        config(['geo.knowledge.vision_enabled' => true,
            'geo.knowledge.poppler_bin' => storage_path('nonexistent-poppler')]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        parent::tearDown();
    }

    private function stage(string $name): \App\Models\KnowledgeDocument
    {
        return (new DocumentIngestionService($this->provider))->stageFile(
            $this->base->id, new UploadedFile(base_path('tests/fixtures/pdf/'.$name), $name, 'application/pdf', null, true),
            $name, $this->project->id, $this->user->id,
        );
    }

    public function test_text_pdf_keeps_text_and_records_actionable_poppler_notice(): void
    {
        $result = (new DocumentIngestionService($this->provider))->process($this->stage('doc10_crop.pdf'));
        $this->assertSame('ready', $result->status);
        $this->assertSame('text_layer', $result->metadata['extraction']['method']);
        $this->assertGreaterThan(0, $result->chunk_count);
        $this->assertStringContainsString('Poppler', $result->metadata['vision_notice'] ?? '');
        $this->assertStringContainsString('pdfinfo', $result->metadata['vision_notice']);
        $this->assertSame(0, $this->provider->visionCalls);
        $this->assertGreaterThan(0, $this->provider->embeddingCalls);
    }

    public function test_scan_without_poppler_fails_before_any_model_call_and_explains_recovery(): void
    {
        $document = $this->stage('doc11_p1_re.pdf');
        try {
            (new DocumentIngestionService($this->provider))->process($document);
            $this->fail('A scan with no available rasterizer must not be marked ready.');
        } catch (IngestionFailure $e) {
            $this->assertStringContainsString('Poppler', $e->getMessage());
            $this->assertStringContainsString('安装', $e->getMessage());
        }
        $this->assertSame('failed', $document->fresh()->status);
        $this->assertSame(0, $this->provider->visionCalls);
        $this->assertSame(0, $this->provider->embeddingCalls);
    }

    public function test_only_missing_rasterizer_is_reported_with_a_configured_directory_containing_spaces(): void
    {
        $directory = Storage::disk('local')->path('fixture-tools/bin with spaces');
        mkdir($directory, 0700, true);
        foreach (['pdfinfo', 'pdfimages', 'pdftotext'] as $name) {
            $path = $directory.'/'.$name.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            file_put_contents($path, "#!/bin/sh\nexit 1\n");
            chmod($path, 0700);
        }
        config(['geo.knowledge.poppler_bin' => $directory]);
        $result = (new DocumentIngestionService($this->provider))->process($this->stage('doc10_crop.pdf'));
        $this->assertSame('ready', $result->status);
        $this->assertStringContainsString('pdftoppm', $result->metadata['vision_notice']);
        $this->assertStringNotContainsString('pdfinfo', $result->metadata['vision_notice']);
        $this->assertSame(0, $this->provider->visionCalls);
    }

    public function test_direct_vision_extraction_skips_missing_pdftoppm_without_requesting_a_model(): void
    {
        $document = $this->stage('doc11_p1_re.pdf');
        $result = (new VisionPageExtractor($this->provider))->extract($document,
            Storage::disk('local')->path($document->file_path), [1 => ['page' => 1, 'needs_vision' => true, 'text' => '']]);
        $this->assertSame('skipped', $result['pages'][1]['status']);
        $this->assertSame('poppler_missing', $result['pages'][1]['reason']);
        $this->assertSame(0, $this->provider->visionCalls);
        $this->assertSame([], $result['gateway_requests']);
    }

    public function test_non_pdf_text_ingestion_does_not_require_poppler(): void
    {
        $result = (new DocumentIngestionService($this->provider))->ingestText($this->base->id,
            'Plain text fixture', str_repeat('This is a local text fixture. ', 40));
        $this->assertSame('ready', $result->status);
        $this->assertArrayNotHasKey('vision_notice', $result->metadata);
        $this->assertSame(0, $this->provider->visionCalls);
    }
}
