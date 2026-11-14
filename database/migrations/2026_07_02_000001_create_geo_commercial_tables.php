<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_commercial_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('qinghe_order_id', 120)->nullable();
            $table->string('product_code', 80);
            $table->string('plan_key', 40);
            $table->unsignedInteger('amount_fen')->default(0);
            $table->string('currency', 12)->default('CNY');
            $table->string('status', 30)->default('pending');
            $table->timestamp('checkout_url_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('qinghe_order_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'plan_key']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('geo_tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('plan_key', 40);
            $table->string('status', 30)->default('pending');
            $table->string('source', 40)->default('qinghe_commercial');
            $table->string('qinghe_subscription_id', 120)->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->string('renew_policy', 40)->nullable();
            $table->unsignedInteger('entitlement_version')->default(1);
            $table->timestamp('synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('qinghe_subscription_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'plan_key']);
            $table->index(['tenant_id', 'current_period_end']);
        });

        Schema::create('geo_invoice_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('commercial_order_id')->constrained('geo_commercial_orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('qinghe_invoice_id', 120)->nullable();
            $table->string('invoice_type', 40)->default('general');
            $table->string('title_masked')->nullable();
            $table->string('tax_id_fingerprint', 80)->nullable();
            $table->string('status', 30)->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('qinghe_invoice_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('geo_commercial_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('event_type', 80);
            $table->string('external_event_id', 160)->nullable();
            $table->string('fingerprint', 80)->unique();
            $table->string('status', 30)->default('pending');
            $table->json('payload_redacted')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_commercial_events');
        Schema::dropIfExists('geo_invoice_requests');
        Schema::dropIfExists('geo_tenant_subscriptions');
        Schema::dropIfExists('geo_commercial_orders');
    }
};
