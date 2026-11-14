<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_theme_replications')) {
            Schema::create('site_theme_replications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 120);
                // 上游是全局 unique()；多租户下必须改成租户内唯一，否则租户 A 占了某个 theme_id，
                // 租户 B 就再也建不了同名的。
                $table->string('theme_id', 80);
                $table->string('base_theme_id', 80)->nullable();
                $table->string('status', 30)->default('queued')->index();
                $table->string('home_url', 500);
                $table->string('category_url', 500);
                $table->string('article_url', 500);
                $table->string('style_preference', 40)->default('content_site');
                $table->json('source_fingerprints')->nullable();
                $table->json('analysis_json')->nullable();
                $table->json('generated_files_json')->nullable();
                $table->json('preview_snapshot_json')->nullable();
                $table->unsignedInteger('current_version')->default(0);
                $table->string('published_theme_path', 500)->nullable();
                $table->string('published_asset_path', 500)->nullable();
                $table->string('compliance_status', 30)->default('pending')->index();
                $table->json('compliance_report_json')->nullable();
                $table->unsignedInteger('iteration_count')->default(0);
                $table->text('error_message')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'theme_id'], 'site_theme_repl_tenant_theme_unique');
                $table->index(['tenant_id', 'status'], 'site_theme_repl_tenant_status_index');
            });
        }

        if (! Schema::hasTable('site_theme_replication_logs')) {
            Schema::create('site_theme_replication_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('replication_id')->constrained('site_theme_replications')->cascadeOnDelete();
                $table->string('level', 20)->default('info')->index();
                $table->string('step', 60)->nullable()->index();
                $table->text('message');
                $table->json('context_json')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'replication_id'], 'site_theme_repl_log_tenant_repl_index');
            });
        }

        if (! Schema::hasTable('site_theme_replication_versions')) {
            Schema::create('site_theme_replication_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('replication_id')->constrained('site_theme_replications')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->string('prompt_hash', 80)->nullable();
                $table->text('feedback')->nullable();
                $table->json('blueprint_json')->nullable();
                $table->json('files_json')->nullable();
                $table->json('compliance_report_json')->nullable();
                $table->string('draft_views_path', 500)->nullable();
                $table->string('draft_assets_path', 500)->nullable();
                $table->unsignedBigInteger('bytes')->default(0);
                $table->string('package_path', 500)->nullable();
                $table->timestamps();

                $table->unique(['replication_id', 'version']);
                $table->index(['tenant_id', 'replication_id'], 'site_theme_repl_ver_tenant_repl_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_theme_replication_versions');
        Schema::dropIfExists('site_theme_replication_logs');
        Schema::dropIfExists('site_theme_replications');
    }
};
