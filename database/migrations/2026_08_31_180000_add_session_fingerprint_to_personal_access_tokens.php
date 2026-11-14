<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 会话身份绑定（B）：personal_access_tokens 加一列 session_fingerprint，
     * 存签发时 gateway_session cookie 的 sha256。
     *
     * 存量 geo-saas-token 全部清掉：上线后这些旧 token 的指纹为 null，
     * 会被 EnforceAccountSessionBinding 中间件一律判 401，留着只是死数据。
     * 生产只有 3 个用户，全部重新登录一次的代价可忽略。
     * （这是有意为之，会在交付报告里写明，别当成事故。）
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('session_fingerprint', 64)->nullable()->index();
        });

        DB::table('personal_access_tokens')
            ->where('name', 'geo-saas-token')
            ->delete();
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['session_fingerprint']);
            $table->dropColumn('session_fingerprint');
        });
    }
};
