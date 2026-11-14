<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 手动发布 · 账号（Account）。
 *
 * 外键上游指向 admins → 本仓改为 users（§一-3）。
 * PLATFORMS 常量照上游 10 个平台，一个不删不加（§一-4）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_publication_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('persona_id');
            $table->string('platform', 32);
            $table->string('custom_platform')->nullable();
            $table->string('account_name');
            $table->text('profile_url')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_user_id');

            $table->timestamps();

            $table->index(['tenant_id', 'persona_id'], 'mpa_tenant_persona');
            $table->index(['tenant_id', 'platform'], 'mpa_tenant_platform');
            $table->index(['tenant_id', 'is_active'], 'mpa_tenant_active');
            $table->index(['tenant_id', 'created_by_user_id'], 'mpa_tenant_creator');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_publication_accounts');
    }
};
