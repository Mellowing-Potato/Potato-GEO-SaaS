<?php

namespace App\Services\SkillAgent;

use App\Models\SkillExecution;
use App\Services\Reports\ReportAssetService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Retry formatting from the saved model output without invoking a model or wallet. */
class SkillRenderRecovery
{
    public function __construct(
        private readonly SkillAssetLoader $assets,
        private readonly RenderBridgeService $renderer,
        private readonly SkillDeliveryAssembler $assembler,
        private readonly SkillArtifactWriter $writer,
        private readonly ReportAssetService $reports,
    ) {}

    public static function eligible(SkillExecution $execution): bool
    {
        $issues = $execution->quality_report['issues'] ?? [];

        return $execution->status === 'failed'
            && $execution->skill_id === 'yao-geo-explainer-builder'
            && ! $execution->article_id
            && empty($execution->billing_pending_result)
            && is_array(data_get($execution->output_data, 'structured'))
            && $issues !== []
            && is_array($issues)
            && array_diff($issues, ['docx_long_tokens_wrapped']) === [];
    }

    public function recover(SkillExecution $execution): SkillExecution
    {
        abort_unless((int) $execution->tenant_id === (int) TenantContext::id(), 404);
        $lock = Cache::lock('skill-render-recovery:'.$execution->tenant_id.':'.$execution->id, 240);
        abort_unless($lock->get(), 409, '这次重新排版仍在处理中，请稍后刷新。');

        try {
            $execution = $execution->fresh();
            if ($execution->status === 'completed' && $execution->article_id) {
                return $execution;
            }
            $this->assertRecoverable($execution);
            $structured = $execution->output_data['structured'];
            $fingerprint = hash('sha256', json_encode($structured, JSON_THROW_ON_ERROR));
            $result = $this->renderer->render($structured, $this->assets->loadAll($execution->skill_id), $execution->id);
            if (! $result->success || trim($result->publishableArticle ?? '') === '') {
                throw ValidationException::withMessages(['execution' => '重新排版未完成，原内容仍保留；本次没有调用模型。']);
            }
            $quality = $this->assembler->withPublishableChecks(
                $result->qualityReport ?? ['passed' => false, 'checks' => []],
                $execution->skill_id, $result->publishableArticle, $structured,
            );
            $quality['blocking'] = ! $quality['passed'];
            $paths = $result->renderedPaths;
            if (isset($paths['md'])) {
                $paths['markdown'] ??= $paths['md'];
                unset($paths['md']);
            }
            $this->writer->storeFinalQualityReport($quality, $paths);

            return DB::transaction(function () use ($execution, $structured, $fingerprint, $result, $quality, $paths): SkillExecution {
                $current = SkillExecution::whereKey($execution->id)->lockForUpdate()->firstOrFail();
                $this->assertRecoverable($current);
                abort_unless(hash_equals($fingerprint, hash('sha256', json_encode($current->output_data['structured'], JSON_THROW_ON_ERROR))),
                    409, '原内容已更新，请刷新后重新排版。');
                $output = array_merge($current->output_data, $paths, [
                    'rendered_formats' => array_values(array_intersect(['markdown', 'html', 'docx', 'pdf'], array_keys($paths))),
                    'render_recovery' => ['completed_at' => now()->toISOString(), 'model_calls' => 0, 'additional_points' => 0],
                ]);
                $article = $quality['passed'] ? $this->reports->createOrLinkForSkillExecution(
                    $current, $result->publishableArticle, structured: $structured, qualityReport: $quality,
                ) : null;
                if ($article) {
                    $output = $this->reports->outputDataWithArticle($output, $article);
                }
                $current->update([
                    'status' => $quality['passed'] ? 'completed' : 'failed',
                    'article_id' => $article?->id,
                    'output_data' => $output,
                    'quality_report' => $quality,
                    'error_message' => $quality['passed'] ? null : '重新排版后仍有质量项未通过，已保留编辑稿；本次未调用模型。',
                ]);

                return $current->fresh();
            });
        } finally {
            $lock->release();
        }
    }

    private function assertRecoverable(SkillExecution $execution): void
    {
        if (! self::eligible($execution)) {
            throw ValidationException::withMessages(['execution' => '这次执行暂不支持重新排版，请先核对原结果状态。']);
        }
    }
}
