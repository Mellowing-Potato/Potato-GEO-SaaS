<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 阶段 1.2 提示词管理台：每技能租户级提示词覆盖（对标 GEOFlow Prompt + AiPrompt/AiSpecialPrompt，按 D-3 不复发多 provider 管理台）。
     *
     * 一个租户对每个技能最多一条覆盖（tenant_id + skill_id 唯一）。
     * 技能执行时优先用覆盖提示词，无覆盖则回退 yao-geo-skills 资产（SKILL.md）。
     */
    public function up(): void
    {
        Schema::create('skill_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('skill_id', 191);
            $table->longText('prompt_text');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['tenant_id', 'skill_id']);
            $table->index(['tenant_id', 'skill_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_prompts');
    }
};
