<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('body_markdown')->nullable();
            $table->text('summary')->nullable();
            $table->string('content_type', 30)->default('explainer'); // explainer, comparison, ranking, general
            $table->string('status', 20)->default('draft'); // draft, editing, pending, approved, published
            $table->string('source_skill')->nullable(); // which skill generated this
            $table->json('cited_chunks')->nullable(); // RAG source chunks
            $table->unsignedSmallInteger('quality_score')->default(0); // 0-100
            $table->unsignedSmallInteger('version')->default(1);
            $table->unsignedInteger('word_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index('content_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
