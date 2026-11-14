<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WeChatDraftDelivery;
use App\Services\Publishing\WeChatDraftDeliveryService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWeChatDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $deliveryId, public readonly int $tenantId)
    {
        $this->onQueue('publishing');
    }

    public function handle(WeChatDraftDeliveryService $service): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant || $tenant->status !== 'active') {
            return;
        }
        $previous = TenantContext::get();
        try {
            TenantContext::set($tenant);
            $delivery = WeChatDraftDelivery::query()->find($this->deliveryId);
            if ($delivery) {
                $service->execute($delivery);
            }
        } finally {
            $previous ? TenantContext::set($previous) : TenantContext::forget();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $previous = TenantContext::get();
        try {
            $tenant = Tenant::find($this->tenantId);
            if (! $tenant) {
                return;
            }
            TenantContext::set($tenant);
            $delivery = WeChatDraftDelivery::query()->find($this->deliveryId);
            if ($delivery && in_array($delivery->status, ['queued', 'preparing', 'sending'], true)) {
                $uncertain = $delivery->status === 'sending';
                $delivery->update(['status' => $uncertain ? 'unknown' : 'failed', 'last_error' => $uncertain
                    ? '发送任务中断，结果待确认，请核对结果，勿重复发送。' : '草稿准备任务中断，未创建草稿，可重试。']);
            }
        } finally {
            $previous ? TenantContext::set($previous) : TenantContext::forget();
        }
    }
}
