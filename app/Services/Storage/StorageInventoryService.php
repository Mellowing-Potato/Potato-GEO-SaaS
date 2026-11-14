<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** Read-only, deliberately incomplete first pass. Unknown ownership is never charged to a tenant. */
final class StorageInventoryService
{
    /** Persisted customer content only. Metadata, indexes and pgvector are reported as not yet metered. */
    private const CONTENT_FIELDS = [
        'articles' => ['body_markdown', 'summary', 'cited_chunks'],
        'article_versions' => ['body_markdown', 'summary', 'seo_description', 'og_description'],
        'skill_executions' => ['input_data', 'output_data', 'quality_report', 'gateway_requests'],
        'knowledge_chunks' => ['content', 'section_title', 'metadata'],
        'enterprise_knowledge_projects' => ['draft_content'],
        'enterprise_knowledge_sources' => ['content'],
        'enterprise_knowledge_revisions' => ['content'],
        'manual_publications' => ['content'],
        'ai_visibility_sources' => ['content_excerpt'],
        'site_theme_replications' => ['analysis_json', 'generated_files_json', 'preview_snapshot_json', 'compliance_report_json'],
        'site_theme_replication_versions' => ['feedback', 'blueprint_json', 'files_json', 'compliance_report_json'],
    ];

    private array $exactOwners = [];

    private array $executionOwners = [];

    private array $themeOwners = [];

    public function inspect(): array
    {
        $started = now()->toIso8601String();
        $this->exactOwners = [];
        $this->executionOwners = [];
        $this->themeOwners = [];
        $this->loadOwners();
        $report = [
            'schema' => 1,
            'started_at' => $started,
            'finished_at' => null,
            'scope' => 'read_only_file_inventory',
            'consistent_snapshot' => false,
            'tenant_files' => [],
            'tenant_content' => [],
            'unowned' => ['files' => 0, 'bytes' => 0, 'by_area' => []],
            'unowned_content_bytes' => 0,
            'unmetered_content' => [],
            'unclassified_tenant_tables' => [],
            'unsafe_entries' => 0,
            'missing_referenced_files' => [],
            'limitations' => [
                'Live filesystem scan is not a transaction; reconcile changes before using for quotas.',
                'Only whitelisted database content is counted; vectors, other content fields, temporary files, backups and physical database overhead remain outside this first pass.',
                'Unowned files require investigation; their paths and contents are not emitted.',
            ],
        ];
        $seen = [];
        foreach (['private' => storage_path('app/private'), 'public' => storage_path('app/public')] as $disk => $root) {
            if (is_link($root)) {
                $report['unsafe_entries']++;
                continue;
            }
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    $report['unsafe_entries']++;
                    continue;
                }
                if (! $file->isFile()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $key = $disk.'/'.$relative;
                $seen[$key] = true;
                $bytes = $file->getSize();
                $owner = $this->ownerFor($key);
                if ($owner === null) {
                    $area = $disk.'/'.explode('/', $relative, 2)[0];
                    $report['unowned']['files']++;
                    $report['unowned']['bytes'] += $bytes;
                    $bucket = $report['unowned']['by_area'][$area] ?? ['files' => 0, 'bytes' => 0];
                    $bucket['files']++;
                    $bucket['bytes'] += $bytes;
                    $report['unowned']['by_area'][$area] = $bucket;
                    continue;
                }
                $tenant = (string) $owner['tenant_id'];
                $kind = $owner['kind'];
                $bucket = $report['tenant_files'][$tenant][$kind] ?? ['files' => 0, 'bytes' => 0];
                $bucket['files']++;
                $bucket['bytes'] += $bytes;
                $report['tenant_files'][$tenant][$kind] = $bucket;
            }
        }
        foreach ($this->exactOwners as $key => $owner) {
            if ($owner !== null && ! isset($seen[$key])) {
                $kind = $owner['kind'];
                $report['missing_referenced_files'][$kind] = ($report['missing_referenced_files'][$kind] ?? 0) + 1;
            }
        }
        $this->measureContent($report);
        foreach (DB::getSchemaBuilder()->getTableListing() as $listedTable) {
            $table = substr(strrchr($listedTable, '.') ?: '.'.$listedTable, 1);
            if (! array_key_exists($table, self::CONTENT_FIELDS) && Schema::hasColumn($table, 'tenant_id')) {
                $report['unclassified_tenant_tables'][] = $table;
            }
        }
        sort($report['unclassified_tenant_tables']);
        ksort($report['tenant_files']);
        ksort($report['tenant_content']);
        ksort($report['unowned']['by_area']);
        $report['finished_at'] = now()->toIso8601String();

        return $report;
    }

    private function measureContent(array &$report): void
    {
        foreach (self::CONTENT_FIELDS as $table => $fields) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id') || ! Schema::hasColumn($table, 'id')) {
                $report['unmetered_content'][] = $table;
                continue;
            }
            $available = [];
            foreach ($fields as $field) {
                if (Schema::hasColumn($table, $field)) {
                    $available[] = $field;
                } else {
                    $report['unmetered_content'][] = $table.'.'.$field;
                }
            }
            if (! $available) {
                continue;
            }
            foreach (DB::table($table)->select(array_merge(['id', 'tenant_id'], $available))->lazyById(200) as $row) {
                $bytes = 0;
                foreach ($available as $field) {
                    if ($row->$field !== null) {
                        $bytes += strlen((string) $row->$field);
                    }
                }
                $tenant = (int) $row->tenant_id;
                if ($tenant <= 0) {
                    $report['unowned_content_bytes'] += $bytes;
                } else {
                    $report['tenant_content'][(string) $tenant][$table] =
                        ($report['tenant_content'][(string) $tenant][$table] ?? 0) + $bytes;
                }
            }
        }
        $report['unmetered_content'][] = 'knowledge_chunks.embedding';
    }

    public function ownerFor(string $key): ?array
    {
        if (array_key_exists($key, $this->exactOwners)) {
            return $this->exactOwners[$key];
        }
        if (preg_match('~^(private/reports|public)/executions/([1-9][0-9]*)(?:/|$)~', $key, $match)) {
            $tenant = $this->executionOwners[(int) $match[2]] ?? null;

            return $tenant === null ? null : ['tenant_id' => $tenant, 'kind' => 'reports'];
        }
        if (preg_match('~^private/theme-packages/([1-9][0-9]*)/([1-9][0-9]*)(?:/|$)~', $key, $match)) {
            $tenant = $this->themeOwners[(int) $match[2]] ?? null;

            return $tenant !== null && $tenant === (int) $match[1]
                ? ['tenant_id' => $tenant, 'kind' => 'themes'] : null;
        }

        return null;
    }

    private function loadOwners(): void
    {
        if (Schema::hasTable('knowledge_documents')) {
            foreach (DB::table('knowledge_documents')->whereNotNull('file_path')->cursor() as $row) {
                $this->registerExact('private', $row->file_path, (int) $row->tenant_id, 'knowledge');
            }
        }
        if (Schema::hasTable('enterprise_knowledge_sources')) {
            foreach (DB::table('enterprise_knowledge_sources')->whereNotNull('file_path')->cursor() as $row) {
                $this->registerExact('private', $row->file_path, (int) $row->tenant_id, 'enterprise_sources');
            }
        }
        if (Schema::hasTable('skill_executions')) {
            foreach (DB::table('skill_executions')->select('id', 'tenant_id')->cursor() as $row) {
                $this->executionOwners[(int) $row->id] = (int) $row->tenant_id;
            }
        }
        if (Schema::hasTable('site_theme_replications')) {
            foreach (DB::table('site_theme_replications')->select('id', 'tenant_id')->cursor() as $row) {
                $this->themeOwners[(int) $row->id] = (int) $row->tenant_id;
            }
        }
    }

    private function registerExact(string $disk, string $path, int $tenantId, string $kind): void
    {
        $path = str_replace('\\', '/', $path);
        if ($tenantId <= 0 || $path === '' || str_starts_with($path, '/')
            || preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $path)) {
            return;
        }
        $key = $disk.'/'.$path;
        $owner = ['tenant_id' => $tenantId, 'kind' => $kind];
        if (! array_key_exists($key, $this->exactOwners)) {
            $this->exactOwners[$key] = $owner;
        } elseif ($this->exactOwners[$key] !== $owner) {
            $this->exactOwners[$key] = null;
        }
    }
}
