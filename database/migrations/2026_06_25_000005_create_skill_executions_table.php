<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('skill_id'); // e.g. 'yao-geo-explainer-builder'
            $table->string('skill_name'); // human-readable name
            $table->string('category', 30); // SkillCategory enum value
            $table->unsignedTinyInteger('stage_index')->nullable(); // workflow stage 0-5
            $table->string('status', 20)->default('pending'); // pending, running, completed, failed, cancelled
            $table->json('input_data')->nullable(); // SkillBrief fields
            $table->json('output_data')->nullable(); // SkillExecutionResult outputs
            $table->json('quality_report')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('token_usage')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['project_id', 'skill_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_executions');
    }
};
