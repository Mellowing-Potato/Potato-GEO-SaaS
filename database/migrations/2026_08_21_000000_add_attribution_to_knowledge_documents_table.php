<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B7 + §7（执行书 2026-08-20）：知识库多模态扣费归属。
 *
 * 老板拍板方案乙：上传时选项目，归属挂在文档上（不挂知识库——文档将来
 * 可能换库，归属要跟着文档走）。user_id = 钱从谁的钱包扣（网关钱包按人开），
 * project_id = 这笔钱记在哪个项目名下 + charge() 的租户安全校验。
 *
 * 两列都可空：存量 5 篇文档从没走过多模态、没产生过费用，一条都不用回填。
 * 不加外键到 projects —— 项目被删不该连带删文档，用量明细的账落在
 * model_call_logs 上，不依赖这两列。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('knowledge_base_id');
            $table->unsignedBigInteger('uploaded_by')->nullable()->after('project_id');
            $table->index(['tenant_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'project_id']);
            $table->dropColumn(['project_id', 'uploaded_by']);
        });
    }
};
