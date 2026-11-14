<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 账户注销申请（计划 §9.5；用户口径 2026-09-21）：
 * 「账户可以注销，但是要提交申请注销，15 天以内再次登陆就等于恢复账户」。
 *
 * 三列的语义：
 *  - deletion_requested_at：申请时刻。非空 = 该账户正在注销冷静期内。
 *  - deletion_purge_at：期满清理时刻（申请时刻 + 冷静期）。非空才可被 geo:account-purge 命中，
 *    这样"申请过又登录撤销"的账户即使字段被写脏也不会被清掉。
 *  - deletion_reason：客户填的注销原因（可空，最长 200 字）。
 *  - deletion_purged_at：真正执行清理的时刻（审计证据，也是一条"别再重复清"的护栏）。
 *
 * 索引只加在 deletion_purge_at 上：清理命令按它扫到期账户，是全表唯一需要索引的场景。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('phone_verified_at');
            $table->timestamp('deletion_purge_at')->nullable()->index()->after('deletion_requested_at');
            $table->string('deletion_reason', 200)->nullable()->after('deletion_purge_at');
            $table->timestamp('deletion_purged_at')->nullable()->after('deletion_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deletion_purge_at']);
            $table->dropColumn(['deletion_requested_at', 'deletion_purge_at', 'deletion_reason', 'deletion_purged_at']);
        });
    }
};
