<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 删项目不再连调用记录一起删（老板 2026-09-04 拍板）。
 *
 * 原话：「不能因为客户删项目就连调用记录都查不到，这个是跟钱有关系的，必须有据可查。」
 *
 * ── 真凶是谁（2026-09-04 实测订正）──────────────────────────
 * 流水丢失的**唯一**原因是 ProjectController@destroy 里那句
 * `ModelCallLog::where('project_id', ...)->delete()`，那是一次硬删。
 *
 * 建表时写的 `constrained()->cascadeOnDelete()` **从来没有生效过** ——
 * `Project` 用的是 SoftDeletes，`$project->delete()` 只写 deleted_at、
 * 不发 SQL DELETE，所以数据库级联根本不会触发。
 * （我一开始判断成「级联删的」，实测后订正。）
 *
 * 那这条迁移还有必要吗？有，作为第二道闸：
 * 只要那个级联外键还挂着，将来任何一次 forceDelete()、或者清理软删数据的
 * 例行任务，都会把跟钱有关的流水连坐删掉，而且不留痕迹。
 * 拆掉之后 project_id 保留原值（指向一个已软删的项目 id），
 * 这正是「查得出这笔钱花在哪个项目」所需要的。
 * 同一形状在本仓已有先例：`ai_visibility_runs.project_id` /
 * `analytics_visit_logs.project_id` 都是裸 unsignedBigInteger、不带外键。
 *
 * 项目名和技能名另由 ProjectController@destroy 在删除前快照进 metadata，
 * 否则记录留下来了、界面上却只剩一个空的项目名（用量明细读的是
 * `$log->project?->name`）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_call_logs', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('model_call_logs', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }
};
