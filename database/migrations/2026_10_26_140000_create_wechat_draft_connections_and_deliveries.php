<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wechat_official_account_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->unique()->constrained('manual_publication_accounts')->cascadeOnDelete();
            $table->string('app_id', 32);
            $table->text('app_secret');
            $table->string('author', 32)->default('');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'app_id']);
        });
        Schema::create('wechat_draft_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('publication_id')->unique()->constrained('manual_publications')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('manual_publication_accounts');
            $table->foreignId('connection_id')->constrained('wechat_official_account_connections');
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedInteger('publication_revision');
            $table->unsignedInteger('connection_revision');
            $table->string('content_fingerprint', 64);
            $table->unsignedInteger('article_version');
            $table->string('publication_title', 128);
            $table->string('app_id', 32);
            $table->longText('payload');
            $table->string('cover_path')->nullable();
            $table->string('status', 24)->default('queued');
            $table->string('media_id', 128)->nullable();
            $table->string('thumb_media_id', 128)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_draft_deliveries');
        Schema::dropIfExists('wechat_official_account_connections');
    }
};
