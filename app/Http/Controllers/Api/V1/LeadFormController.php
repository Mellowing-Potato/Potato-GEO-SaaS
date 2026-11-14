<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Http/Controllers/Admin/LeadFormController.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Support\Lead\LeadFormFields;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * 线索表单管理（单 8，租户侧 API）。
 *
 * 路由在 routes/api.php 的 v1 租户隔离组里，挂 can:lead:manage（admin + editor）。
 * 全部查询走 TenantScope（tenant 中间件已设上下文），建表单时 BelongsToTenant 自动填 tenant_id。
 * 返回 JSON（管理台是 JS 工作台，不渲染 Blade，见执行书 §五-4 / §五-5）。
 */
class LeadFormController extends Controller
{
    public function index(): JsonResponse
    {
        $forms = LeadForm::query()
            ->withCount('submissions')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'data' => $forms->map(fn (LeadForm $form): array => $this->toArray($form))->values()->all(),
            'meta' => [
                'current_page' => $forms->currentPage(),
                'last_page' => $forms->lastPage(),
                'total' => $forms->total(),
            ],
            'stats' => [
                'total' => LeadForm::query()->count(),
                'active' => LeadForm::query()->where('status', LeadForm::STATUS_ACTIVE)->count(),
                'submissions' => LeadSubmission::query()->count(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $form = LeadForm::query()->whereKey($id)->firstOrFail();

        return response()->json(['data' => $this->toArray($form, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        $fields = LeadFormFields::normalizePosted($request->input('fields', []));
        if ($fields === []) {
            return response()->json(['message' => '至少需要一个表单字段。'], 422);
        }

        $slug = $this->uniqueSlug((string) ($payload['slug'] ?? ''), (string) $payload['name']);

        $form = LeadForm::query()->create([
            'name' => trim((string) $payload['name']),
            'slug' => $slug,
            'status' => (string) $payload['status'],
            'description' => trim((string) ($payload['description'] ?? '')),
            'submit_button_label' => trim((string) ($payload['submit_button_label'] ?? '提交')),
            'success_message' => trim((string) ($payload['success_message'] ?? '')),
            'fields' => $fields,
            'notify_webhook_url' => $this->resolvedWebhookUrl($request, null),
            'notify_webhook_format' => (string) ($payload['notify_webhook_format'] ?? LeadForm::NOTIFY_FORMAT_ROBOT),
        ]);

        return response()->json(['data' => $this->toArray($form, true)], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $form = LeadForm::query()->whereKey($id)->firstOrFail();
        $payload = $this->validatePayload($request, $form);
        $fields = LeadFormFields::normalizePosted($request->input('fields', []));
        if ($fields === []) {
            return response()->json(['message' => '至少需要一个表单字段。'], 422);
        }

        $form->update([
            'name' => trim((string) $payload['name']),
            'slug' => $this->uniqueSlug((string) ($payload['slug'] ?? ''), (string) $payload['name'], $form),
            'status' => (string) $payload['status'],
            'description' => trim((string) ($payload['description'] ?? '')),
            'submit_button_label' => trim((string) ($payload['submit_button_label'] ?? '提交')),
            'success_message' => trim((string) ($payload['success_message'] ?? '')),
            'fields' => $fields,
            'notify_webhook_url' => $this->resolvedWebhookUrl($request, $form),
            'notify_webhook_format' => (string) ($payload['notify_webhook_format'] ?? $form->notify_webhook_format),
        ]);

        return response()->json(['data' => $this->toArray($form, true)]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $form = LeadForm::query()->whereKey($id)->firstOrFail();
        $form->update([
            'status' => $form->isActive() ? LeadForm::STATUS_INACTIVE : LeadForm::STATUS_ACTIVE,
        ]);

        return response()->json(['data' => $this->toArray($form)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $form = LeadForm::query()->withCount('submissions')->whereKey($id)->firstOrFail();
        if ((int) $form->submissions_count > 0) {
            // 评审修复（中低项）：这条应用层规则与迁移里的 nullOnDelete 是两层，不是互相矛盾——
            // 应用层不给「一键删掉客户线索」这个入口（要停用就停用，见下方文案）；
            // DB 层的 nullOnDelete 是兜底：万一走别的路径（租户注销级联、人工 SQL）删了表单，
            // 提交记录仍保留、只把 lead_form_id 置空，不跟着陪葬。
            // 改任何一层前先读另一层，别把其中一个「改成一致」。
            return response()->json([
                'message' => '该表单已有提交记录，不能删除；如需停止收集请把表单停用（停用后公开页返回 404，历史线索保留）。',
            ], 422);
        }

        $form->delete();

        return response()->json(['message' => '已删除。']);
    }

    /**
     * @return array{name:string,slug?:string,status:string,description?:string,submit_button_label?:string,success_message?:string}
     */
    private function validatePayload(Request $request, ?LeadForm $leadForm = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9][a-z0-9_-]*[a-z0-9]$/',
                // §二-1：slug 是租户内唯一 —— 必须按当前租户作用域，不能照抄上游的全局 unique。
                Rule::unique('lead_forms', 'slug')
                    ->where('tenant_id', TenantContext::id())
                    ->ignore($leadForm?->id),
            ],
            'status' => ['required', Rule::in([LeadForm::STATUS_ACTIVE, LeadForm::STATUS_INACTIVE])],
            'description' => ['nullable', 'string', 'max:2000'],
            'submit_button_label' => ['nullable', 'string', 'max:80'],
            'success_message' => ['nullable', 'string', 'max:2000'],
            // 只收 https：线索里是客户姓名和电话，明文过网等于把 PII 摊开。
            // 真正的 SSRF 判定不在这儿 —— 出站时由 SafeOutboundHttpClient 重新解析并裁决
            // （存的时候域名指向公网、发的时候指向内网，是标准的 DNS 重绑定手法），
            // 这里只做「一眼就知道填错了」的早退，给租户即时反馈。
            'notify_webhook_url' => ['nullable', 'string', 'max:500', 'url', 'starts_with:https://'],
            'notify_webhook_format' => ['nullable', Rule::in(LeadForm::NOTIFY_FORMATS)],
        ], [
            'name.required' => '请填写表单名称。',
            'name.max' => '表单名称不能超过 120 个字符。',
            'slug.max' => '链接标识不能超过 120 个字符。',
            'slug.regex' => '链接标识只能使用小写字母、数字、中划线和下划线，并以字母或数字开头和结尾。',
            'slug.unique' => '这个链接标识已经被使用，请更换一个或留空自动生成。',
            'status.required' => '请选择表单状态。',
            'status.in' => '表单状态不正确，请重新选择。',
            'description.max' => '说明文字不能超过 2000 个字符。',
            'submit_button_label.max' => '提交按钮文案不能超过 80 个字符。',
            'success_message.max' => '提交成功提示不能超过 2000 个字符。',
            'notify_webhook_url.url' => '新线索通知必须填写完整的 HTTPS 机器人地址。',
            'notify_webhook_url.starts_with' => '新线索通知必须使用 HTTPS 地址。',
            'notify_webhook_url.max' => '新线索通知地址不能超过 500 个字符。',
            'notify_webhook_format.in' => '通知报文格式不正确，请重新选择。',
        ]);
    }

    private function uniqueSlug(string $slug, string $name, ?LeadForm $leadForm = null): string
    {
        $base = trim($slug) !== '' ? trim($slug) : Str::slug($name);
        $base = Str::lower($base !== '' ? $base : Str::random(8));
        $candidate = $base;
        $index = 2;

        // TenantScope 已按当前租户作用域，这里只在本租户内查重（不跨租户）。
        while (LeadForm::query()
            ->where('slug', $candidate)
            ->when($leadForm, fn ($query) => $query->whereKeyNot($leadForm->id))
            ->exists()) {
            $candidate = $base.'-'.$index;
            $index++;
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * 通知地址的写入语义。因为读回来的是掩码，前端不可能把原值回传，所以：
     *   - 请求里没有这个键        → 保持原样（编辑表单文案时不该顺手清掉通知配置）
     *   - 传了空串                → 清空（这是「关掉通知」的唯一方式）
     *   - 传了值                  → 覆盖
     */
    private function resolvedWebhookUrl(Request $request, ?LeadForm $form): ?string
    {
        if (! $request->exists('notify_webhook_url')) {
            return $form?->notify_webhook_url;
        }

        $url = trim((string) $request->input('notify_webhook_url', ''));

        return $url === '' ? null : $url;
    }

    private function toArray(LeadForm $form, bool $withFields = false): array
    {
        return [
            'id' => $form->id,
            'name' => $form->name,
            'slug' => $form->slug,
            'status' => $form->status,
            'description' => $form->description,
            'submit_button_label' => $form->submit_button_label,
            'success_message' => $form->success_message,
            'submissions_count' => $form->submissions_count ?? null,
            // 地址本身是凭据（机器人 URL 里带 key），只回掩码 + 一个布尔位。
            'notify_webhook_configured' => $form->notifiesOnSubmission(),
            'notify_webhook_masked' => $form->maskedNotifyWebhookUrl(),
            'notify_webhook_format' => $form->notify_webhook_format,
            'created_at' => optional($form->created_at)->toIso8601String(),
            ...($withFields ? ['fields' => $form->normalizedFields()] : []),
        ];
    }
}
