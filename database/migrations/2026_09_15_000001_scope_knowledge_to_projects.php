<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Historical records remain unassigned until the owner explicitly assigns
        // them. Never infer brand ownership from the most recent login/project.
        foreach (['knowledge_bases', 'enterprise_knowledge_projects'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
                $table->index(['tenant_id', 'project_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['knowledge_bases', 'enterprise_knowledge_projects'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['tenant_id', 'project_id']);
                $table->dropConstrainedForeignId('project_id');
            });
        }
    }
};
