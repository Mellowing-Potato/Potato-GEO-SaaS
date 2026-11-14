<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 实时进度事件（阶段 3.2，对标 GEOFlow TaskRealtimeBroadcastService）。
 *
 * 作为「事件总线」语义：本事件不强制 ShouldBroadcast，便于在测试/无
 * 广播驱动环境下安全降级；部署侧可挂监听器桥接到 Reverb/WebSocket，
 * 前端订阅对应 topic 即可实时刷新，断线则以轮询端点兜底。
 */
class JobProgressUpdated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $topic,
        public readonly array $payload = [],
    ) {}
}
