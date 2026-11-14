<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_knowledge_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('enterprise_knowledge_project_id');
            $table->longText('content');
            $table->string('summary', 255)->nullable();
            $table->string('source', 30)->default('manual');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('content_hash', 64);
            $table->timestamps();

            $table->foreign('tenant_id', 'ekr_tenant_id_fk')
                ->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('enterprise_knowledge_project_id', 'ekr_project_id_fk')
                ->references('id')->on('enterprise_knowledge_projects')->onDelete('cascade');
            $table->foreign('created_by_user_id', 'ekr_user_id_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'enterprise_knowledge_project_id'], 'ekr_tenant_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_knowledge_revisions');
    }
};
