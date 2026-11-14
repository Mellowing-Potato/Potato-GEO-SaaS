<?php

namespace Tests\Feature;

use App\Services\Billing\ModelUsageRecorder;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\ContentPipeline;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Contracts\ProjectWorkflow;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\Distribution\DistributionHttpClient;
use App\Services\Distribution\DistributionOrchestrator;
use App\Services\Distribution\DistributionPayloadBuilder;
use App\Services\Distribution\DistributionPublisherManager;
use App\Services\Distribution\DistributionRetryPolicy;
use App\Services\Distribution\DistributionSigningService;
use App\Services\Knowledge\DocumentIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DI 回归护栏
 *
 * 断言 GeoServiceProvider 中所有绑定都能被容器正确解析，
 * 防止"加了构造参数忘了接线"导致运行时 500。
 *
 * 新增构造参数时若忘记在此处补充断言，本测试不会报错，
 * 但容器解析会在运行时失败——因此每次新增绑定都应同步更新本文件。
 */
class ContainerResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.tenant_domain' => 'geo.example.test']);
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function geoServiceProviderBindings(): array
    {
        return [
            'SkillRegistry'       => [SkillRegistry::class],
            'KnowledgeRetrieval'  => [KnowledgeRetrieval::class],
            'AiProvider'          => [AiProvider::class],
            'SkillAgentRunner'    => [SkillAgentRunner::class],
            'ProjectWorkflow'     => [ProjectWorkflow::class],
            'ContentPipeline'     => [ContentPipeline::class],
            'DocumentIngestion'   => [DocumentIngestionService::class],
            'ModelUsageRecorder'  => [ModelUsageRecorder::class],
            // 分发引擎
            'DistributionSigning'   => [DistributionSigningService::class],
            'DistributionRetry'     => [DistributionRetryPolicy::class],
            'DistributionPayload'   => [DistributionPayloadBuilder::class],
            'DistributionHttp'      => [DistributionHttpClient::class],
            'DistributionPublisher' => [DistributionPublisherManager::class],
            'DistributionOrchestrator' => [DistributionOrchestrator::class],
        ];
    }

    #[DataProvider('geoServiceProviderBindings')]
    public function test_geo_service_provider_binding_can_be_resolved(string $abstract): void
    {
        $instance = app()->make($abstract);

        $this->assertNotNull($instance, "Container failed to resolve [{$abstract}]");
        $this->assertInstanceOf($abstract, $instance);
    }

    public function test_skill_agent_runner_has_model_usage_recorder_injected(): void
    {
        $runner = app()->make(SkillAgentRunner::class);

        $reflection = new \ReflectionClass($runner);
        $params = $reflection->getConstructor()->getParameters();
        $paramNames = array_map(fn (\ReflectionParameter $p) => $p->getName(), $params);

        $this->assertContains(
            'usageRecorder',
            $paramNames,
            'SkillAgentRunnerService constructor must include ModelUsageRecorder parameter (DI wiring regression)',
        );
    }

    /**
     * B7/§7 扣费收口回归护栏。
     *
     * DocumentIngestionService 的 assistRecorder 是可空构造参数（默认 null），
     * 单测里手动 new 注入能跑、容器若漏接则解析仍"成功"（只是 null），
     * 导致生产环境多模态调用白送、不扣费、不记账。本测试强制断言容器解析出的
     * 实例 assistRecorder 非 null，杜绝"测试过、生产挂"。
     */
    public function test_document_ingestion_has_assist_usage_charger_injected(): void
    {
        $service = app()->make(DocumentIngestionService::class);

        $reflection = new \ReflectionClass($service);
        $this->assertTrue(
            $reflection->hasProperty('assistRecorder'),
            'DocumentIngestionService must declare an assistRecorder property',
        );

        $property = $reflection->getProperty('assistRecorder');
        $property->setAccessible(true);
        $charger = $property->getValue($service);

        $this->assertNotNull(
            $charger,
            'GeoServiceProvider must inject AssistUsageRecorder into DocumentIngestionService; '
            . 'null means vision billing (B7/§7) is silently skipped in production.',
        );
    }
}
