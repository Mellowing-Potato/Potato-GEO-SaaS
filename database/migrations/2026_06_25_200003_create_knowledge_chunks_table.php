<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('knowledge_documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index'); // position in document
            $table->text('content'); // the chunk text
            $table->string('section_title', 500)->nullable(); // heading context
            $table->unsignedInteger('token_count')->default(0);
            $table->jsonb('metadata')->nullable(); // page numbers, paragraph indices, etc.
            $table->timestamps();

            $table->index(['tenant_id', 'document_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'pgsql') {
            Schema::table('knowledge_chunks', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });

            return;
        }

        // Postgres production uses pgvector for nearest-neighbor search.
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(1536)');
        DB::statement('CREATE INDEX knowledge_chunks_embedding_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
