<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('point_unit_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedTinyInteger('version');
            $table->string('fingerprint',64)->nullable();
            $table->timestamp('migrated_at')->nullable();
        });
        Schema::create('point_unit_legacy_records', function (Blueprint $table): void {
            $table->string('source_table');
            $table->unsignedBigInteger('source_id');
            $table->json('original_record');
            $table->timestamp('created_at');
            $table->primary(['source_table','source_id']);
        });
        $existing = collect([
            'model_call_logs',
            'ai_visibility_runs',
            'knowledge_documents',
            'skill_executions',
            'point_debit_reconciliations',
        ])
            ->contains(fn($table) => DB::table($table)->exists());
        DB::table('point_unit_state')->insert(['id'=>1,'version'=>$existing ? 1 : 2]);
        Schema::table('point_debit_reconciliations', function (Blueprint $table): void {
            $table->decimal('points_recorded',20,6)->nullable()->change();
            $table->decimal('points_gateway',20,6)->nullable()->change();
        });
        Schema::table('ai_visibility_runs', function (Blueprint $table): void {
            $table->decimal('points_charged_fen',20,6)->nullable()->change();
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Point units require a coordinated database backup restore, not a code-only rollback.');
    }
};
