<?php

namespace Tests\Feature;

use App\Services\Billing\ModelUsageRecorder;
use App\Services\Content\PromptTemplateService;
use App\Services\Contracts\AiProvider;
use App\Services\Contracts\KnowledgeRetrieval;
use App\Services\Contracts\SkillRegistry;
use App\Services\DTOs\SkillBrief;
use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\SkillAgentRunnerService;
use App\Services\SkillAgent\SkillAssetLoader;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SkillRunnerExecutionTimeLimitTest extends TestCase
{
    #[DataProvider('entryPoints')]
    public function test_each_runner_entry_restores_limit_on_failure(string $entry, int $previous): void
    {
        Log::spy();
        $error = new RuntimeException('stopped before AI or database access');
        $failInsideScope = function () use ($error): never {
            $this->assertSame(420, (int) ini_get('max_execution_time'));
            throw $error;
        };

        $registry = $this->createMock(SkillRegistry::class);
        if ($entry === 'execute') {
            $registry->expects($this->once())->method('find')->willReturnCallback($failInsideScope);
        }
        $ai = $this->createMock(AiProvider::class);
        $ai->expects($this->never())->method('generate');
        $runner = new SkillAgentRunnerService(
            $registry, $ai, $this->createMock(KnowledgeRetrieval::class),
            $this->createMock(SkillAssetLoader::class), $this->createMock(RenderBridgeService::class),
            $this->createMock(PromptTemplateService::class), $this->createMock(ModelUsageRecorder::class),
        );
        $brief = new SkillBrief('timeout-probe', 1, 1, executionId: 1);

        // Relay entry points read their state first. Stop at connection resolution,
        // before opening a connection; this test requires no database or migrations.
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        if ($entry === 'execute') {
            $resolver->expects($this->never())->method('connection');
        } else {
            $resolver->expects($this->once())->method('connection')->willReturnCallback($failInsideScope);
        }
        $originalResolver = Model::getConnectionResolver();
        $originalLimit = (int) ini_get('max_execution_time');
        Model::setConnectionResolver($resolver);
        set_time_limit($previous);

        try {
            if ($entry === 'execute') {
                // The synchronous path catches exceptions and returns a failed
                // result early; its timer still has to be restored.
                $result = $runner->execute($brief);
                $this->assertFalse($result->success);
                $this->assertSame($error->getMessage(), $result->errorMessage);
            } else {
                try {
                    $runner->{$entry}($brief);
                    $this->fail('The relay exception must propagate unchanged.');
                } catch (RuntimeException $caught) {
                    $this->assertSame($error, $caught);
                }
            }
            $this->assertSame($previous, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($originalLimit);
            if ($originalResolver) {
                Model::setConnectionResolver($originalResolver);
            } else {
                Model::unsetConnectionResolver();
            }
        }
    }

    public static function entryPoints(): array
    {
        $cases = [];
        foreach (['execute', 'advanceRelay', 'finalizeRelay'] as $entry) {
            foreach ([0, 37] as $previous) {
                $cases[$entry.' with previous limit '.$previous] = [$entry, $previous];
            }
        }

        return $cases;
    }
}
