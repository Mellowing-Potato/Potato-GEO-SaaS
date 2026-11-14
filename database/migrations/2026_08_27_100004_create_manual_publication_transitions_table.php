<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 手动发布 · 状态流转轨迹（Transition）。
 *
 * 每次发布单状态变更都写一行：谁改的、从什么状态、到什么状态、什么时候、备注/链接。
 * 这是本模块的核心证据价值，不是可选项（执行书 §三-2）。
 *
 * 外键统一指向 users（§一-3）。模型设 UPDATED_AT = null，故只保留 created_at。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_publication_transitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('manual_publication_id');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->text('completion_url')->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'manual_publication_id'], 'mpt_tenant_publication');
            $table->index(['tenant_id', 'changed_by_user_id'], 'mpt_tenant_actor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_publication_transitions');
    }
};
