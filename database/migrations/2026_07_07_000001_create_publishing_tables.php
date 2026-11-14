<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishing_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 40);
            $table->text('endpoint_url');
            $table->string('auth_type', 40)->default('none');
            $table->string('username')->nullable();
            $table->text('encrypted_secret')->nullable();
            $table->json('headers')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('article_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignId('publishing_channel_id')->constrained('publishing_channels')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('remote_id')->nullable();
            $table->text('remote_url')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'publishing_channel_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['publishing_channel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_publications');
        Schema::dropIfExists('publishing_channels');
    }
};
