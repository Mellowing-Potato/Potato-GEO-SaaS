<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 手动发布 · 人设（Persona）。
 *
 * 设计口径（单 7 执行书）：
 *  - 四张表全部带 tenant_id（租户隔离，fail-open 作用域靠 BelongsToTenant）。
 *  - 外键上游指向 admins，本仓改为 users（§一-3）。
 *  - 不用 ->after()（PG 无效）。索引显式命名（PG 63 字节上限）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_publication_personas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->text('bio')->nullable();
            $table->string('tone')->nullable();
            $table->string('domain')->nullable();
            $table->text('disclosure_text')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_user_id');

            $table->timestamps();

            $table->index(['tenant_id', 'is_active'], 'mpp_tenant_active');
            $table->index(['tenant_id', 'created_by_user_id'], 'mpp_tenant_creator');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_publication_personas');
    }
};
