<?php

namespace App\Services;

use App\Services\AI\AiProviderServiceImpl;
use App\Services\AI\MockAiProvider;
use App\Services\Billing\ModelUsageRecorder;
use App\Services\Billing\AssistUsageRecorder;
use App\Services\Content\ArticleMarkdownRenderer;
use App\Services\Content\ContentPipelineService;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\ContentPipeline;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Contracts\ProjectWorkflow;
use App\Services\Contracts\SkillAgentRunner;
use App\Services\Contracts\SkillRegistry;
use App\Services\Content\PromptTemplateService;
use App\Services\Knowledge\DocumentIngestionService;
use App\Services\Knowledge\EnterpriseKnowledgeDraftService;
use App\Services\Knowledge\KnowledgeRetrievalServiceImpl;
use App\Services\Admin\ActivityLogService;
use App\Services\Reports\AnalyticsIngestionService;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillRegistryService;
use App\Services\Workflow\ProgressBroadcastService;
use App\Services\Workflow\TaskOrchestrationService;
use App\Services\Distribution\DistributionOrchestrator;
use App\Services\Distribution\DistributionPayloadBuilder;
use App\Services\Distribution\DistributionPublisherManager;
use App\Services\Distribution\DistributionRetryPolicy;
use App\Services\Distribution\DistributionSigningService;
use App\Services\Distribution\RewriteRuleGenerator;
use App\Services\Distribution\TaskDistributionChannelSelector;
use App\Services\Distribution\Publishers\GenericHttpPublisher;
use App\Services\Distribution\Publishers\WordPressRestPublisher;
use App\Services\Workflow\ProjectWorkflowService;
use App\Services\Workflow\ProjectCompletionEvidence;
use Illuminate\Support\ServiceProvider;

/**
 * Service 层注册
 *
 * 将所有 Service 契约绑定到具体实现，Controller 通过类型提示自动注入。
 *
 * 用法：
 *   class ProjectController {
 *       public function __construct(
 *           private readonly ProjectWorkflow $workflow,  // 自动注入 ProjectWorkflowService
 *       ) {}
 *   }
 */
class GeoServiceProvider extends ServiceProvider
{
    /**
     * 单例注册 — 整个请求生命周期共享同一实例
     */
    public array $singletons = [
        SkillRegistry::class => SkillRegistryService::class,
        KnowledgeRetrieval::class => KnowledgeRetrievalServiceImpl::class,
        PromptTemplateService::class => PromptTemplateService::class,
        ArticleMarkdownRenderer::class => ArticleMarkdownRenderer::class,
    ];

    public function register(): void
    {
        // 单例绑定（无外部依赖的简单 Service）
        foreach ($this->singletons as $contract => $implementation) {
            $this->app->singleton($contract, $implementation);
        }

        $this->app->bind(\App\Services\Geo\AiVisibility\AiProviderEndpointPolicy::class,
            fn ($app) => new \App\Services\Geo\AiVisibility\AiProviderEndpointPolicy(
                $app->make(\App\Services\Geo\AiVisibility\AiVisibilityConfigurationResolver::class)->allowedHosts(),
            ));

        // Mock is explicit test behavior; a missing workspace Key is a configuration error.
        $this->app->singleton(AiProvider::class, function () {
            if ($this->app->environment('testing') || (bool) config('geo.test_mock_ai', false)) {
                return new MockAiProvider();
            }
            return $this->app->make(AiProviderServiceImpl::class);
        });

        // 复杂 Service（需要构造函数注入其他 Service）
        $this->app->singleton(SkillAgentRunner::class, function ($app) {
            return new SkillAgentRunnerService(
                $app->make(SkillRegistry::class),
                $app->make(AiProvider::class),
                $app->make(KnowledgeRetrieval::class),
                new SkillAssetLoader(),
                new RenderBridgeService(),
                $app->make(PromptTemplateService::class),
                $app->make(ModelUsageRecorder::class),
            );
        });

        $this->app->singleton(ProjectWorkflow::class, function ($app) {
            return new ProjectWorkflowService(
                $app->make(SkillRegistry::class),
                $app->make(ProjectCompletionEvidence::class),
            );
        });

        $this->app->singleton(ContentPipeline::class, function ($app) {
            return new ContentPipelineService(
                $app->make(AiProvider::class),
                $app->make(KnowledgeRetrieval::class),
                $app->make(SkillRegistry::class),
            );
        });

        // 第二个参数 AssistUsageRecorder 必须注入：它是可空默认 null，若此处漏传，
        // 生产容器解析出的实例 assistRecorder 恒为 null → DocumentIngestionService
        // 内 B7/§7 的 charge() 永不调用 → 多模态调用白送、不扣费、不记账。
        // 单测因手动 new DocumentIngestionService(..., $charger) 注入而漏不掉这个坑，
        // 故另加容器绑定测试兜底（见 GeoServiceProviderBindingTest）。
        //
        // ⚠️ 第三、四个参数（出站安全链）**故意不传**，别照上面那条教训「补上」：
        // 本绑定是 singleton，一旦在这里注入实例就被永久缓存，测试再用
        // app()->instance(SafeOutboundHttpClient::class, ...) 打桩就失效了。
        // 留 null 时 DocumentIngestionService::safeOutbound() 每次从容器取，
        // 拿到的仍是真实实例（取不到直接抛），不存在「静默降级为无防护」——
        // 这一点和 assistRecorder 为 null 时静默跳过扣费的语义完全不同。
        $this->app->singleton(DocumentIngestionService::class, function ($app) {
            return new DocumentIngestionService(
                $app->make(AiProvider::class),
                $app->make(AssistUsageRecorder::class),
            );
        });

        // ─── 分发引擎（Distribution Engine）───
        $this->app->singleton(DistributionSigningService::class);
        $this->app->singleton(DistributionRetryPolicy::class);
        $this->app->singleton(DistributionPayloadBuilder::class);
        $this->app->singleton(TaskDistributionChannelSelector::class);
        $this->app->singleton(RewriteRuleGenerator::class);

        $this->app->singleton(WordPressRestPublisher::class);
        $this->app->singleton(GenericHttpPublisher::class);

        $this->app->singleton(DistributionPublisherManager::class, function ($app) {
            return new DistributionPublisherManager(
                $app->make(WordPressRestPublisher::class),
                $app->make(GenericHttpPublisher::class),
            );
        });

        $this->app->singleton(DistributionOrchestrator::class, function ($app) {
            return new DistributionOrchestrator(
                $app->make(DistributionPublisherManager::class),
                $app->make(DistributionPayloadBuilder::class),
                $app->make(DistributionRetryPolicy::class),
                $app->make(TaskDistributionChannelSelector::class),
            );
        });

        // ─── 任务自动化编排（阶段 1.4）───
        $this->app->singleton(ProgressBroadcastService::class);
        $this->app->singleton(TaskOrchestrationService::class, function ($app) {
            return new TaskOrchestrationService(
                $app->make(SkillAgentRunner::class),
                $app->make(ProgressBroadcastService::class),
                $app->make(SkillRegistry::class),
            );
        });

        // ─── 企业知识库 revision 流（阶段 1.5）───
        $this->app->singleton(EnterpriseKnowledgeDraftService::class, function ($app) {
            return new EnterpriseKnowledgeDraftService(
                $app->make(DocumentIngestionService::class),
            );
        });

        // ─── 数据分析访问日志接入（阶段 3.1）───
        $this->app->singleton(AnalyticsIngestionService::class);

        // ─── 平台活动日志（阶段 3.4）───
        $this->app->singleton(ActivityLogService::class);
    }

    public function boot(): void
    {
        //
    }

}
