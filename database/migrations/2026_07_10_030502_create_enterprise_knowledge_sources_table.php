<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('enterprise_knowledge_project_id');
            $table->string('original_name', 191)->nullable();
            $table->string('file_path', 1000)->nullable();
            $table->string('file_type', 60)->nullable();
            $table->longText('content')->nullable();
            $table->integer('character_count')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id', 'eks_tenant_id_fk')
                ->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('enterprise_knowledge_project_id', 'eks_project_id_fk')
                ->references('id')->on('enterprise_knowledge_projects')->onDelete('cascade');

            $table->index(['tenant_id', 'enterprise_knowledge_project_id'], 'eks_tenant_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_knowledge_sources');
    }
};
