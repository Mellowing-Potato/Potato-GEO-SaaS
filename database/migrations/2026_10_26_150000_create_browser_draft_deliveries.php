<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_draft_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('publication_id')->unique()->constrained('manual_publications')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('manual_publication_accounts');
            $table->foreignId('created_by')->constrained('users');
            $table->string('platform', 24);
            $table->string('status', 24);
            $table->unsignedInteger('publication_revision');
            $table->string('content_fingerprint', 64);
            $table->unsignedInteger('article_version');
            $table->string('publication_title', 128);
            $table->string('account_identity', 64);
            $table->string('request_hash', 64);
            $table->longText('payload');
            $table->string('draft_id', 32)->nullable();
            $table->string('last_error', 100)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_draft_deliveries');
    }
};
