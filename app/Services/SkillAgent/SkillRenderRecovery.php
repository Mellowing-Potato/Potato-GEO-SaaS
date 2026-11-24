<?php

namespace App\Services\SkillAgent;

use App\Models\SkillExecution;
use App\Services\Reports\ReportAssetService;
use App\Services\Reports\ReportStorage;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        if (! in_array($execution->status, ['completed', 'failed'], true)
            || ! empty($execution->billing_pending_result)
            || ! is_array(data_get($execution->output_data, 'structured'))) {
            return false;
        }

        return self::recoverableFormats($execution) !== [] || self::needsArticleRecovery($execution);
    }

    private static function recoverableFormats(SkillExecution $execution): array
    {
        $output = $execution->output_data;
        if (! isset($output['format_status']) && ($output['pipeline_mode'] ?? null) === 'full'
            && (! empty($output['render_success']) || ! empty($output['render_error']))) {
            return array_values(array_filter(RenderResult::supportedFormats($execution->skill_id),
                static fn (string $format): bool => empty($output[$format])));
        }

        return array_values(array_filter(['markdown', 'html', 'docx', 'pdf'], static fn (string $format): bool =>
            in_array(data_get($output, "format_status.{$format}.status"), ['unavailable', 'failed'], true)
        ));
    }

    private static function needsArticleRecovery(SkillExecution $execution): bool
    {
        $issues = $execution->quality_report['issues'] ?? [];

        return $execution->status === 'failed'
            && $execution->skill_id === 'yao-geo-explainer-builder'
            && ! $execution->article_id
            && $issues !== []
            && is_array($issues)
            && array_diff($issues, ['docx_long_tokens_wrapped', 'html_kami_fonts_packaged', 'html_download_fonts_embedded']) === [];
    }

    public function recover(SkillExecution $execution): SkillExecution
    {
        abort_unless((int) $execution->tenant_id === (int) TenantContext::id(), 404);
        $lock = Cache::lock('skill-render-recovery:'.$execution->tenant_id.':'.$execution->id, 240);
        abort_unless($lock->get(), 409, '这次重新排版仍在处理中，请稍后刷新。');

        try {
            $execution = $execution->fresh();
            if (! self::eligible($execution) && empty($execution->billing_pending_result) && (
                ($execution->status === 'completed' && $execution->article_id)
                || (in_array($execution->status, ['completed', 'failed'], true)
                    && data_get($execution->output_data, 'render_recovery.kind') === 'formats')
            )) {
                return $execution;
            }
            $this->assertRecoverable($execution);
            $structured = $execution->output_data['structured'];
            $fingerprint = hash('sha256', json_encode($structured, JSON_THROW_ON_ERROR));
            $result = $this->renderer->render($structured, $this->assets->loadAll($execution->skill_id), $execution->id, true);
            if (! self::needsArticleRecovery($execution)) {
                try {
                    $saved = $this->restoreFormats($execution, $fingerprint, $result);
                } catch (ValidationException $exception) {
                    $this->discardUnpublishedFiles($execution, $result);
                    throw $exception;
                }
                $this->discardUnpublishedFiles($saved, $result);

                return $saved;
            }

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
                $current = $this->lockUnchangedExecution($execution, $fingerprint);
                $output = array_merge($current->output_data, $paths, [
                    'rendered_formats' => array_values(array_intersect(['markdown', 'html', 'docx', 'pdf'], array_keys($paths))),
                    'render_recovery' => ['completed_at' => now()->toISOString(), 'model_calls' => 0, 'additional_points' => 0],
                ]);
                if ($result->formatStatus !== []) {
                    $output['format_status'] = $result->formatStatus;
                }
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

    /** Only attach newly available files. The original content verdict and article remain authoritative. */
    private function restoreFormats(SkillExecution $execution, string $fingerprint, RenderResult $result): SkillExecution
    {
        if (! $result->success || ($result->qualityReport['passed'] ?? true) === false) {
            throw ValidationException::withMessages(['execution' => '重新排版未通过交付检查。请检查渲染组件和报告质量后重试。']);
        }

        return DB::transaction(function () use ($execution, $fingerprint, $result): SkillExecution {
            $current = $this->lockUnchangedExecution($execution, $fingerprint);
            $output = $current->output_data;
            $restored = [];
            foreach (self::recoverableFormats($current) as $format) {
                $path = $result->renderedPaths[$format] ?? null;
                if (($result->formatStatus[$format]['status'] ?? null) === 'available' && is_string($path)
                    && ReportStorage::belongsToExecution($current->id, $path) && Storage::disk('reports')->exists($path)) {
                    $output[$format] = $path;
                    $output['format_status'][$format] = ['status' => 'available'];
                    $restored[] = $format;
                }
            }
            if ($restored === []) {
                throw ValidationException::withMessages(['execution' => '尚未补齐可下载格式，请核对缺失组件后重试。']);
            }
            foreach ($result->formatStatus as $format => $status) {
                // Backfill legacy reports while preserving files already delivered.
                if (isset($output['format_status'][$format])) {
                    continue;
                }
                if (isset($output[$format]) && is_string($output[$format])) {
                    $output['format_status'][$format] = ['status' => 'available'];
                } elseif (($status['status'] ?? null) !== 'available') {
                    $output['format_status'][$format] = $status;
                } else {
                    $output['format_status'][$format] = ['status' => 'failed', 'reason' => 'missing_output'];
                }
            }
            $output['rendered_formats'] = array_values(array_filter(['markdown', 'html', 'docx', 'pdf'],
                static fn (string $format): bool => isset($output[$format]) && is_string($output[$format])));
            $output['render_recovery'] = [
                'kind' => 'formats', 'formats' => $restored, 'completed_at' => now()->toISOString(),
                'model_calls' => 0, 'additional_points' => 0,
            ];
            $current->update(['output_data' => $output]);

            return $current;
        });
    }

    private function discardUnpublishedFiles(SkillExecution $saved, RenderResult $result): void
    {
        $published = array_filter($saved->output_data ?? [], 'is_string');
        $prefix = "executions/{$saved->id}/";
        foreach ($result->renderedPaths as $path) {
            if (is_string($path) && ReportStorage::belongsToExecution($saved->id, $path)
                && preg_match('~^rerender-[a-f0-9]{24}/~', substr($path, strlen($prefix)))
                && ! in_array($path, $published, true)) {
                Storage::disk('reports')->delete($path);
            }
        }
    }

    private function lockUnchangedExecution(SkillExecution $execution, string $fingerprint): SkillExecution
    {
        $current = SkillExecution::whereKey($execution->id)->lockForUpdate()->firstOrFail();
        $this->assertRecoverable($current);
        abort_unless($current->status === $execution->status && $current->article_id === $execution->article_id
            && $current->quality_report === $execution->quality_report
            && hash_equals($fingerprint, hash('sha256', json_encode($current->output_data['structured'], JSON_THROW_ON_ERROR))),
            409, '原内容已更新，请刷新后重新排版。');

        return $current;
    }

    private function assertRecoverable(SkillExecution $execution): void
    {
        if (! self::eligible($execution)) {
            throw ValidationException::withMessages(['execution' => '这次执行暂不支持重新排版，请先核对原结果状态。']);
        }
    }
}
