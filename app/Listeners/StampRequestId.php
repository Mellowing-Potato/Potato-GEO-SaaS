<?php

namespace App\Listeners;

use App\Http\Middleware\AttachRequestId;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Log;

/**
 * 把请求编号贴到响应头，并给失败响应留一条可 grep 的日志。
 *
 * 挂 RequestHandled 而不是写在中间件里，是因为未捕获异常渲染出来的 500 是在
 * 中间件管道之外生成的——挂中间件会漏掉最需要编号的那一类响应。这个事件在
 * Kernel::handle() 的 try/catch 之后、响应返回之前触发，所以能覆盖全部响应。
 */
class StampRequestId
{
    public function handle(RequestHandled $event): void
    {
        $request = $event->request;
        $response = $event->response;

        if (! $request->is('api/*')) {
            return;
        }

        $id = AttachRequestId::of($request);
        if ($id === '') {
            $id = AttachRequestId::generate();
        }

        $response->headers->set(AttachRequestId::HEADER, $id);

        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }

        // 这条是「索引行」：用户报编号 → grep 到这里 → 知道是哪个接口、哪个人、
        // 什么状态码。500 的堆栈由框架自己记，同样带 request_id，两条对得上。
        // 取用户可能触发一次 token 查询，rescue 兜住，别让记日志把原始错误盖掉。
        Log::warning('api 请求失败', [
            'request_id' => $id,
            'status' => $status,
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'user_id' => rescue(fn () => $request->user()?->id, null, false),
            'tenant' => $request->headers->get('X-Tenant'),
        ]);
    }
}
