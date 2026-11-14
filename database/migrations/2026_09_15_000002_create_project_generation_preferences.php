<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('project_generation_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('skill_id', 150);
            $table->json('preferences');
            $table->timestamps();
            $table->unique(['tenant_id', 'project_id', 'skill_id'], 'project_generation_preferences_owner_unique');
        });
        // Old account-wide overrides remain archived in skill_prompts. They are
        // deliberately not copied to projects or used by the execution path.
    }

    public function down(): void { Schema::dropIfExists('project_generation_preferences'); }
};
