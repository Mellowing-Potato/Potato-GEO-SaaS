<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_ai_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);           // openai, deepseek, anthropic, dashscope, bigmodel, siliconflow, potato ...
            $table->text('encrypted_key');            // Laravel 自带 encrypt/decrypt Cast
            $table->string('label', 100)->nullable(); // 备注标签，如 "生产 Key" / "测试 Key"
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();     // 额外配置：模型白名单、速率限制等
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ai_keys');
    }
};
