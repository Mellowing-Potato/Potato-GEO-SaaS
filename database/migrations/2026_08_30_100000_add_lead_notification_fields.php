<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 单 8 收尾：线索提交通知出口。
 *
 * 背景：模块原本没有任何通知通道 —— 线索落库后没人被告知，租户只能自己回管理台刷新。
 * 生产实测邮件与短信两条路都是死的（geo-saas 的 MAIL_MAILER=log，从没发出过一封；
 * 四类通知短信老板已拍板搁置），所以通知走 webhook：租户把企业微信 / 钉钉群机器人的
 * 地址填进来即可，也兼容自建接收端。
 *
 * 字段落在表单上（不是租户上）：同一租户可能既有「售前咨询」也有「售后报修」，
 * 两类线索该进不同的群。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_forms', function (Blueprint $table) {
            // 500 与 lead_submissions.source_url 同宽；企业微信机器人地址约 120 字符，留足自建端余量。
            $table->string('notify_webhook_url', 500)->nullable()->comment('新线索通知地址，空=不通知');
            // robot=企业微信/钉钉群机器人报文；json=结构化报文给自建接收端。
            // 默认 robot：这是唯一一个填完地址就能看见消息、不用自己写接收端的形态。
            $table->string('notify_webhook_format', 16)->default('robot')->comment('robot|json');
        });

        Schema::table('lead_submissions', function (Blueprint $table) {
            // 通知结果记在线索行上，管理台每条线索都能看见「通知成功没有」。
            // 不建独立投递日志表：一条线索最多通知一次，一对一的东西没必要开表。
            $table->string('notify_status', 20)->nullable()->comment('skipped|queued|sent|failed，NULL=通知功能上线前的旧数据');
            $table->string('notify_error', 200)->nullable()->comment('失败原因（只存原因码与状态码，绝不存报文）');
            $table->timestamp('notified_at')->nullable()->comment('通知终态时间');
        });
    }

    public function down(): void
    {
        Schema::table('lead_forms', function (Blueprint $table) {
            $table->dropColumn(['notify_webhook_url', 'notify_webhook_format']);
        });

        Schema::table('lead_submissions', function (Blueprint $table) {
            $table->dropColumn(['notify_status', 'notify_error', 'notified_at']);
        });
    }
};
