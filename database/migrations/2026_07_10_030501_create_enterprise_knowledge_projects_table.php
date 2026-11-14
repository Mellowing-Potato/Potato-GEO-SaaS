<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_knowledge_projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 191);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->longText('draft_content')->nullable();
            $table->json('validation_json')->nullable();
            $table->json('structured_json')->nullable();
            $table->unsignedBigInteger('published_knowledge_base_id')->nullable();
            $table->unsignedBigInteger('published_document_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id', 'ekp_tenant_id_fk')
                ->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('published_knowledge_base_id', 'ekp_kb_id_fk')
                ->references('id')->on('knowledge_bases')->nullOnDelete();
            $table->foreign('published_document_id', 'ekp_doc_id_fk')
                ->references('id')->on('knowledge_documents')->nullOnDelete();
            $table->foreign('created_by_user_id', 'ekp_user_id_fk')
                ->references('id')->on('users')->nullOnDelete();

            $table->index(['tenant_id', 'status'], 'ekp_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_knowledge_projects');
    }
};
