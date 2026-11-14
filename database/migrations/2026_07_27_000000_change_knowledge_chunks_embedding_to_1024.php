<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-07-27 · embed 维度对齐（1536 → 1024）
 *
 * 背景：生产 geo-saas 此前全程 MockAiProvider（无真 key），knowledge_chunks 唯一那行
 *       是 dim=1536 的假向量；而网关组合商品 geo-embed 上游绑 siliconflow BAAI/bge-m3
 *       ＝ 1024 维。保留已接好的 bge-m3（路 B），把库列迁成 vector(1024)，不引入 OpenAI。
 *
 * 前置硬依赖：第 1 步数据闸门须已清空 knowledge_chunks（否则存量 1536 值会让
 *             ALTER COLUMN TYPE vector(1024) 报错 —— 即 fail-closed，不会写脏）。
 * 仅 pgsql 生效；sqlite 无 vector 列，直接跳过。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_embedding_idx');
        DB::statement('ALTER TABLE knowledge_chunks ALTER COLUMN embedding TYPE vector(1024)');
        DB::statement('CREATE INDEX knowledge_chunks_embedding_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS knowledge_chunks_embedding_idx');
        DB::statement('ALTER TABLE knowledge_chunks ALTER COLUMN embedding TYPE vector(1536)');
        DB::statement('CREATE INDEX knowledge_chunks_embedding_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops) WITH (m = 16, ef_construction = 64)');
    }
};
