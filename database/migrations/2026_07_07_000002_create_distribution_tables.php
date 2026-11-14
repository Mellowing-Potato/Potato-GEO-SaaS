<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add distribution-specific columns to existing publishing_channels table
        Schema::table('publishing_channels', function (Blueprint $table) {
            $table->string('domain', 255)->nullable()->after('endpoint_url');
            $table->string('front_mode', 30)->nullable()->after('settings');
            $table->string('template_key', 120)->nullable()->after('front_mode');
            $table->json('site_settings')->nullable()->after('template_key');
            $table->json('channel_config')->nullable()->after('site_settings');
            $table->string('last_health_status', 30)->nullable()->after('last_verified_at');
            $table->timestamp('last_health_checked_at')->nullable()->after('last_health_status');
            $table->text('last_error_message')->nullable()->after('last_health_checked_at');
        });

        // 2. Create distribution_channel_secrets table
        Schema::create('distribution_channel_secrets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('publishing_channel_id')->constrained('publishing_channels')->cascadeOnDelete();
            $table->string('key_id', 80)->unique();
            $table->text('secret_ciphertext');
            $table->string('status', 30)->default('active');
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['publishing_channel_id', 'status']);
            $table->index(['tenant_id']);
        });

        // 3. Create distribution_logs table
        Schema::create('distribution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('publishing_channel_id')->nullable()->constrained('publishing_channels')->nullOnDelete();
            $table->foreignId('article_publication_id')->nullable()->constrained('article_publications')->nullOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->string('level', 20)->default('info');
            $table->string('event', 120)->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'publishing_channel_id']);
            $table->index(['tenant_id', 'article_id']);
            $table->index(['level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distribution_logs');
        Schema::dropIfExists('distribution_channel_secrets');

        Schema::table('publishing_channels', function (Blueprint $table) {
            $table->dropColumn([
                'domain',
                'front_mode',
                'template_key',
                'site_settings',
                'channel_config',
                'last_health_status',
                'last_health_checked_at',
                'last_error_message',
            ]);
        });
    }
};
