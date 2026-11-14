<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverLeadNotificationJob;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Services\Publishing\LeadFormDeliveryService;
use App\Support\Lead\LeadFormFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * 线索表单公开页（单 8）。
 *
 * ⚠️ 不是照抄上游 Site/LeadFormController，而是按执行书 §一 重写：
 *  - 公开页没有登录、也没有租户中间件，TenantContext 此刻为 null；
 *  - 定位走 LeadFormDeliveryService::resolve()，三件套（解租户→set→显式 where→finally 清理）
 *    一步不少，确保「A 租户的链接打不开 B 租户的表单」（L2）；
 *  - 整个 payload 有 64KB 上限，超了 422（不开免费存储）；
 *  - 提交内容按表单声明的字段校验，声明之外的字段直接丢弃（L6）；
 *  - 返回给访客的错误信息不含上游原始报文 / 内部地址 / 邮箱（§五-3 红线，踩过两次）；
 *  - 渲染走 Blade {{ }} 转义，表单文案里的 <script> 不会被执行（L8）。
 *
 * 路由在 routes/web.php，包裹在 config('geo.lead_form_enabled') 开关里（默认 false → 404，L10）。
 * 不挂 tenant 中间件（按 URL slug 判租户，与 host 判租户的 IdentifyTenant 是两套机制）；
 * 不挂 idempotency 中间件（访客不可能带幂等键，挂上表单就不能提交，见 §二-4）。
 */
class LeadFormPageController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 64 * 1024;

    private const HONEYPOT_FIELD = 'website';

    public function __construct(private readonly LeadFormDeliveryService $delivery) {}

    public function show(string $tenantSlug, string $slug): View
    {
        $resolved = $this->delivery->resolve($tenantSlug, $slug);
        if ($resolved === null) {
            abort(404);
        }

        ['form' => $form, 'tenant' => $tenant] = $resolved;

        // 交给 LeadFormVisitMiddleware（terminate）记访问：404 时该属性为 null。
        request()->attributes->set('lead_form_id', $form->id);
        request()->attributes->set('lead_form_tenant_id', $tenant->id);

        return view('lead-form', [
            'form' => $form,
            'fields' => $form->normalizedFields(),
            'siteName' => $tenant->name ?: $tenant->subdomain,
            'description' => (string) ($form->description ?? ''),
            'submitLabel' => (string) ($form->submit_button_label ?? '提交'),
            'csrfToken' => csrf_token(),
            // 二轮评审修复：页面一律不发绝对 URL，理由见视图内注释。
            // 表单 action 与 JS 的 submitUrl 都用相对路径，继承当前页的协议与域名。
            'formAction' => request()->getRequestUri(),
            // 静态资源版本号：与 PlatformOpsController 同一套（filemtime 破缓存）。
            'cssVersion' => file_exists(public_path('geo-lead-form.css')) ? filemtime(public_path('geo-lead-form.css')) : time(),
            'jsVersion' => file_exists(public_path('geo-lead-form.js')) ? filemtime(public_path('geo-lead-form.js')) : time(),
        ]);
    }

    public function submit(string $tenantSlug, string $slug, Request $request): Response
    {
        // ① 整个 payload 大小上限：超了 422，不回显内容（防开免费存储 + 防信息泄露）。
        // 评审修复（中低项）：不能只看 getContent()。JSON 提交读得到 php://input，但
        // multipart/form-data 会被 PHP 解进 $_POST、php://input 是空的 —— 只判它等于
        // 对 multipart 完全不设防。两个来源取大：body 实测长度 与 声明的 Content-Length。
        // （Content-Length 可被报小，但那条路 body 实测长度会兜住；真正的最外层上限是
        //  PHP 的 post_max_size，这里只是应用层的早退。）
        $declaredBytes = (int) ($request->server('CONTENT_LENGTH') ?: 0);
        $bodyBytes = mb_strlen($request->getContent(), '8bit');
        if (max($bodyBytes, $declaredBytes) > self::MAX_PAYLOAD_BYTES) {
            return $this->error($request, '提交内容过大，请精简后重试。', 422);
        }

        $resolved = $this->delivery->resolve($tenantSlug, $slug);
        if ($resolved === null) {
            abort(404);
        }

        ['form' => $form, 'tenant' => $tenant] = $resolved;

        // ② 蜜罐：bot 会自动填隐藏的 website 字段；真人看不到也不会填。
        // 命中即按成功返回，但不落库（不浪费存储、不污染数据）。
        if (trim((string) $request->input(self::HONEYPOT_FIELD, '')) !== '') {
            return $this->success($request, $this->successMessage($form));
        }

        // ③ 按表单声明的字段校验；声明之外的字段在 validateSubmission 里被丢弃（L6）。
        try {
            $payload = LeadFormFields::validateSubmission(
                $form,
                $request->except(['_token', self::HONEYPOT_FIELD, 'source_url']),
            );
        } catch (ValidationException $exception) {
            // 错误信息只含字段标签（租户自己定义的文案），不含内部地址/邮箱/上游报文。
            if ($request->expectsJson()) {
                return new JsonResponse([
                    'ok' => false,
                    'message' => '请检查表单填写是否完整。',
                    'errors' => $exception->errors(),
                ], 422);
            }

            return back()
                ->withErrors($exception->validator)
                ->withInput($request->except([self::HONEYPOT_FIELD]));
        }

        // ④ tenant_id 显式落库：resolve() 的 finally 已 forget 上下文，这里不依赖隐式作用域。
        $notifies = $form->notifiesOnSubmission();

        $submission = LeadSubmission::query()->create([
            'tenant_id' => $tenant->id,
            'lead_form_id' => $form->id,
            'status' => LeadSubmission::STATUS_NEW,
            'payload' => $payload,
            'source_url' => mb_substr((string) ($request->input('source_url') ?: $request->headers->get('referer') ?: url()->current()), 0, 500),
            'ip_address' => (string) ($request->ip() ?? ''),
            'user_agent' => $request->userAgent(),
            // 落库当场就写通知初态，新数据不留 NULL（NULL 专门留给通知功能上线前的旧行）。
            'notify_status' => $notifies ? LeadSubmission::NOTIFY_QUEUED : LeadSubmission::NOTIFY_SKIPPED,
            'notified_at' => $notifies ? null : now(),
        ]);

        // ⑤ 通知异步发。绝不能同步 —— 访客在等这个响应，对端机器人慢一秒访客就多等一秒，
        //    而且对端挂了会把「提交成功」变成报错，线索明明已经存下来了。
        if ($notifies) {
            DeliverLeadNotificationJob::dispatch($submission->id, $tenant->id);
        }

        return $this->success($request, $this->successMessage($form));
    }

    private function successMessage(LeadForm $form): string
    {
        return trim((string) ($form->success_message ?? '')) !== ''
            ? (string) $form->success_message
            : '提交成功，我们会尽快与您联系。';
    }

    private function success(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['ok' => true, 'message' => $message], 200);
        }

        return redirect()->back()->with('lead_form_message', $message);
    }

    private function error(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['ok' => false, 'message' => $message], $status);
        }

        if ($status === 422) {
            return back()->withErrors(['payload' => $message]);
        }

        abort($status, $message);
    }
}
