<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted theme generation, packaging and storage for independent workspaces.
 * Upstream: app/Services/Admin/SiteThemeReplication/ThemeReplicationPipelineService.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\ThemePackaging;

use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationVersion;
use App\Services\ThemePackaging\SiteThemeReplicationService;
use Throwable;

class ThemeReplicationPipelineService
{
    public function __construct(
        private readonly SiteThemeReplicationService $replicationService,
        private readonly ThemeReferenceFetcher $fetcher,
        private readonly ThemeReferenceAnalyzer $analyzer,
        private readonly ThemeReplicationAgent $agent,
        private readonly ThemeScaffoldWriter $writer,
        private readonly ThemeComplianceGuard $guard,
    ) {}

    public function run(int $replicationId): void
    {
        $replication = SiteThemeReplication::query()->find($replicationId);
        if (! $replication) {
            return;
        }

        if ((string) $replication->status !== SiteThemeReplication::STATUS_QUEUED) {
            return;
        }

        try {
            $this->buildReadyVersion($replication, null, true);
        } catch (Throwable $exception) {
            $this->fail($replication, $exception->getMessage(), [
                'exception' => $exception::class,
            ]);
        }
    }

    public function iterate(int $replicationId, string $feedback): void
    {
        $replication = SiteThemeReplication::query()->find($replicationId);
        if (! $replication) {
            return;
        }

        if ((string) $replication->status !== SiteThemeReplication::STATUS_ITERATING) {
            return;
        }

        try {
            $this->replicationService->log($replication, 'info', 'iterating', __('theme_package.log.iterating'));
            $this->buildReadyVersion($replication, trim($feedback), false);
        } catch (Throwable $exception) {
            $this->fail($replication, $exception->getMessage(), [
                'exception' => $exception::class,
            ]);
        }
    }

    private function buildReadyVersion(SiteThemeReplication $replication, ?string $feedback, bool $fetchReferences): void
    {
        $references = [];
        $analysis = (array) $replication->analysis_json;

        if ($fetchReferences || $analysis === []) {
            $this->transition($replication, SiteThemeReplication::STATUS_FETCHING, 'fetching', __('theme_package.log.fetching'));
            $references = $this->fetcher->fetch($replication);

            $this->transition($replication, SiteThemeReplication::STATUS_EXTRACTING, 'extracting', __('theme_package.log.extracting'));
            $analysis = $this->analyzer->analyze($replication, $references);
        }

        if ($feedback !== null && $feedback !== '') {
            $analysis['iteration'] = [
                'feedback' => $feedback,
                'previous_version' => (int) $replication->current_version,
                'requested_at' => now()->toIso8601String(),
            ];
        }

        $this->transition($replication, SiteThemeReplication::STATUS_ANALYZING, 'analyzing', __('theme_package.log.analyzing'));
        $blueprint = $this->agent->generateBlueprint($replication, $analysis, $feedback);

        $this->transition($replication, SiteThemeReplication::STATUS_GENERATING, 'generating', __('theme_package.log.generating'));
        $versionNumber = max(1, (int) $replication->current_version + 1);
        $files = $this->writer->write($replication, $versionNumber, $blueprint);

        $this->transition($replication, SiteThemeReplication::STATUS_SCANNING, 'scanning', __('theme_package.log.scanning'));
        $complianceReport = $this->guard->scan($files);
        if (empty($complianceReport['passed'])) {
            $this->fail($replication, __('theme_package.error.compliance_failed'), $complianceReport);

            return;
        }

        // ⚠️ 顺序：先写文件（上面 writer->write）→ 再收版本 → 最后插版本行。
        //    enforceVersionLimit 只删「最老那一版」的目录，绝不会碰刚写完的这一版。
        $this->replicationService->enforceVersionLimit($replication);

        SiteThemeReplicationVersion::query()->create([
            'replication_id' => (int) $replication->id,
            'version' => $versionNumber,
            'prompt_hash' => hash('sha256', json_encode([
                'analysis' => $analysis,
                'feedback' => $feedback,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            'feedback' => $feedback,
            'blueprint_json' => $blueprint,
            'files_json' => $files,
            'compliance_report_json' => $complianceReport,
            'draft_views_path' => (string) ($files['views_path'] ?? ''),
            'draft_assets_path' => (string) ($files['assets_path'] ?? ''),
            // 不写这个字段的话 versions.bytes 永远是 0，清理任务算不出「回收了多少」，
            // 配额侧也只剩「遍历磁盘」这一条路（模型注释里承诺过要记）。
            'bytes' => $this->manifestBytes($files),
        ]);

        $previewSnapshot = [
            'version' => $versionNumber,
            'pages' => ['home', 'category', 'article'],
            'ready_at' => now()->toIso8601String(),
        ];

        $sourceFingerprints = (array) $replication->source_fingerprints;
        if ($references !== []) {
            $sourceFingerprints = array_merge($sourceFingerprints, [
                'fetched' => $this->referenceFingerprint($references),
            ]);
        }

        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_READY,
            'source_fingerprints' => $sourceFingerprints,
            'analysis_json' => $analysis,
            'generated_files_json' => $files,
            'preview_snapshot_json' => $previewSnapshot,
            'current_version' => $versionNumber,
            'compliance_status' => 'passed',
            'compliance_report_json' => $complianceReport,
            'iteration_count' => $feedback !== null && $feedback !== '' ? (int) $replication->iteration_count + 1 : (int) $replication->iteration_count,
            'error_message' => null,
        ])->save();

        $this->replicationService->log($replication, 'info', 'ready', __('theme_package.log.ready'), [
            'version' => $versionNumber,
            'files' => count((array) ($files['files'] ?? [])),
            'iterated' => $feedback !== null && $feedback !== '',
        ]);
    }

    private function transition(SiteThemeReplication $replication, string $status, string $step, string $message): void
    {
        $replication->forceFill([
            'status' => $status,
            'error_message' => null,
        ])->save();

        $this->replicationService->log($replication, 'info', $step, $message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function fail(SiteThemeReplication $replication, string $message, array $context = []): void
    {
        $hasComplianceViolations = isset($context['violations']) && is_array($context['violations']);

        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 2000),
            'compliance_status' => $hasComplianceViolations ? 'failed' : (string) ($replication->compliance_status ?: 'pending'),
            'compliance_report_json' => $hasComplianceViolations ? $context : $replication->compliance_report_json,
        ])->save();

        $this->replicationService->log($replication, 'error', 'failed', $message, $context);
    }

    /**
     * 这一版写盘文件的总字节数（按 writer 返回的清单算，不再遍历一次磁盘）。
     *
     * @param  array<string, mixed>  $files
     */
    private function manifestBytes(array $files): int
    {
        $total = 0;
        foreach ((array) ($files['files'] ?? []) as $file) {
            $record = (array) $file;
            $total += (int) ($record['bytes'] ?? 0);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $references
     * @return array<string, mixed>
     */
    private function referenceFingerprint(array $references): array
    {
        $pages = [];
        foreach ((array) ($references['pages'] ?? []) as $type => $page) {
            $pages[$type] = [
                'url' => (string) ($page['url'] ?? ''),
                'title' => (string) ($page['title'] ?? ''),
                'html_size' => (int) ($page['html_size'] ?? 0),
                'css_count' => count((array) ($page['css'] ?? [])),
            ];
        }

        return [
            'pages' => $pages,
            'fetched_at' => (string) ($references['fetched_at'] ?? now()->toIso8601String()),
        ];
    }
}
