<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 接力执行的中间状态表。
 *
 * 为什么要落库而不是留在内存：分段报告原本是「一个 PHP 进程从头跑到尾」，
 * 撞死在 set_time_limit(420) 上（2026-09-06 skill_executions id=44 实证）。
 * 改成一段一个队列任务接力之后，段与段之间进程会换人，累积结果必须有个落脚点。
 *
 * 一条执行一行（skill_execution_id 唯一）。收尾时删掉——chunk_meta 与 raw_output
 * 那时已经写进 skill_executions.output_data，这里留着只会白占地方（state 里带着
 * 每段的模型原文，一份报告十几万字符）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_execution_relays', function (Blueprint $table) {
            $table->id();
            // 唯一：一条执行同时只能有一条接力在跑。重跑会先删旧行再建（见 beginRelay）。
            $table->foreignId('skill_execution_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('total_steps');
            $table->unsignedSmallInteger('completed_steps')->default(0);
            $table->json('state');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_execution_relays');
    }
};
