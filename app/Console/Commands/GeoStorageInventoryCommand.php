<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageInventoryService;
use Illuminate\Console\Command;

final class GeoStorageInventoryCommand extends Command
{
    protected $signature = 'geo:storage-inventory {--json : Emit aggregate JSON without names or content}';

    protected $description = 'Read-only preliminary inventory of GEO persistent files and unknown ownership';

    public function handle(StorageInventoryService $inventory): int
    {
        $report = $inventory->inspect();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $this->info('Preliminary read-only file inventory; not a quota balance.');
        $this->table(['Tenant', 'Category', 'Files', 'Bytes'], collect($report['tenant_files'])->flatMap(
            fn ($categories, $tenant) => collect($categories)->map(
                fn ($totals, $category) => [$tenant, $category, $totals['files'], $totals['bytes']],
            ),
        )->all());
        $this->line('Unowned: '.$report['unowned']['files'].' files, '.$report['unowned']['bytes'].' bytes');
        $this->line('Whitelisted database content: '.json_encode($report['tenant_content']));
        $this->line('Unmetered columns: '.implode(', ', $report['unmetered_content']));
        $this->line('Tenant tables pending classification: '.implode(', ', $report['unclassified_tenant_tables']));
        $this->line('Unsafe entries: '.$report['unsafe_entries']);
        $this->line('Missing referenced files: '.json_encode($report['missing_referenced_files']));

        return self::SUCCESS;
    }
}
