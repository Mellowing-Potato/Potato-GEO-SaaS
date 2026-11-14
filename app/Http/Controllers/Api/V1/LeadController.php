<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Http/Controllers/Admin/LeadController.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 线索提交记录管理（单 8，租户侧 API）。
 *
 * 列表 / 详情 / 改状态备注 / 导出 CSV。全部走 TenantScope（租户隔离由 BelongsToTenant
 * 全局作用域保证，控制器内不再显式写 where tenant_id）。
 * 路由挂 can:lead:manage（admin + editor）。
 */
class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->filteredQuery($request);
        $submissions = (clone $query)
            ->with(['form:id,name,slug', 'handler:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => $submissions->map(fn (LeadSubmission $row): array => $this->toArray($row))->values()->all(),
            'meta' => [
                'current_page' => $submissions->currentPage(),
                'last_page' => $submissions->lastPage(),
                'total' => $submissions->total(),
            ],
            'stats' => [
                'total' => LeadSubmission::query()->count(),
                'new' => LeadSubmission::query()->where('status', LeadSubmission::STATUS_NEW)->count(),
                'pending' => LeadSubmission::query()->whereIn('status', [LeadSubmission::STATUS_NEW, LeadSubmission::STATUS_CONTACTED])->count(),
                'converted' => LeadSubmission::query()->where('status', LeadSubmission::STATUS_CONVERTED)->count(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $submission = LeadSubmission::query()
            ->with(['form:id,name,slug,fields', 'handler:id,name'])
            ->whereKey($id)
            ->firstOrFail();

        return response()->json(['data' => $this->toArray($submission, true)]);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $submission = LeadSubmission::query()->whereKey($id)->firstOrFail();
        $payload = $request->validate([
            'status' => ['required', Rule::in(LeadSubmission::STATUSES)],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $submission->update([
            'status' => (string) $payload['status'],
            // 评审修复 A1：handled_by 外键指向 users(id)，落「当前操作人」而非租户 id。
            // 旧写法 TenantContext::id() ?: user()->id 在 v1 租户中间件下左边永远非空，
            // 永远取不到 user id —— 租户 id 与 user id 不同号时直接 500（FK 违约）。
            // 部分更新语义（评审中低项）：note 不在 payload 里时保留原值，传了（含空串）才覆盖。
            'note' => array_key_exists('note', $payload)
                ? trim((string) $payload['note'])
                : (string) $submission->note,
            // handled_by 可空且外键指向 users(id)：拿不到当前用户就写 null，
            // 绝不用 0 兜底 —— users 表没有 id=0，那样落库直接 FK 违约 500。
            'handled_by' => $request->user()?->id,
            'handled_at' => now(),
        ]);

        return response()->json(['data' => $this->toArray($submission->fresh(['form:id,name,slug', 'handler:id,name']), true)]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filename = 'leads-'.now()->format('Ymd-His').'.csv';
        $query = $this->filteredQuery($request);

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', '表单', '状态', '字段内容', '来源页面', '备注', '提交时间']);

            $query->chunkById(200, function ($rows) use ($handle): void {
                $rows->load('form:id,name');

                foreach ($rows as $row) {
                    if (! $row instanceof LeadSubmission) {
                        continue;
                    }

                    fputcsv($handle, [
                        $row->id,
                        $this->csvCell($row->form?->name ?? ''),
                        $this->csvCell($row->status),
                        $this->csvCell(json_encode($row->payload ?? [], JSON_UNESCAPED_UNICODE)),
                        $this->csvCell($row->source_url ?? ''),
                        $this->csvCell($row->note ?? ''),
                        $this->csvCell($row->created_at?->format('Y-m-d H:i:s') ?? ''),
                    ]);
                }
            });
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return Builder<LeadSubmission>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = LeadSubmission::query();

        $status = trim((string) $request->query('status', ''));
        if (in_array($status, LeadSubmission::STATUSES, true)) {
            $query->where('status', $status);
        }

        $formId = (int) $request->query('form_id', 0);
        if ($formId > 0) {
            $query->where('lead_form_id', $formId);
        }

        $dateFrom = $this->dateFilter($request->query('date_from'));
        if ($dateFrom !== null) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        $dateTo = $this->dateFilter($request->query('date_to'));
        if ($dateTo !== null) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $payloadExpression = $this->payloadSearchExpression();
            $query->where(function (Builder $inner) use ($like, $payloadExpression): void {
                $inner->whereRaw('LOWER(COALESCE(source_url, ?)) LIKE ?', ['', $like])
                    ->orWhereRaw($payloadExpression.' LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(note, ?)) LIKE ?', ['', $like]);
            });
        }

        return $query;
    }

    private function csvCell(mixed $value): string
    {
        $cell = is_string($value) ? $value : (string) $value;

        // 防 CSV 公式注入：以 = + - @ 开头的单元格前加单引号。
        if ($cell !== '' && preg_match('/^[=+\-@\t\r]/', $cell) === 1) {
            return "'".$cell;
        }

        return $cell;
    }

    private function dateFilter(mixed $value): ?string
    {
        $date = trim((string) $value);
        if ($date === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function payloadSearchExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => 'LOWER(CAST(payload AS CHAR))',
            'sqlsrv' => 'LOWER(CAST(payload AS NVARCHAR(MAX)))',
            default => 'LOWER(CAST(payload AS TEXT))',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(LeadSubmission $row, bool $withPayload = false): array
    {
        return [
            'id' => $row->id,
            'lead_form_id' => $row->lead_form_id,
            'status' => $row->status,
            'source_url' => $row->source_url,
            'ip_address' => $row->ip_address,
            'note' => $row->note,
            'handled_by' => $row->handled_by,
            'handler_name' => $row->handler?->name,
            'handled_at' => optional($row->handled_at)->toIso8601String(),
            'created_at' => optional($row->created_at)->toIso8601String(),
            // 通知结果：失败必须能被看见。界面不显示的话，租户会以为「有新线索会有人告诉我」，
            // 实际通知早就发不出去了。NULL = 通知功能上线前的旧线索。
            'notify_status' => $row->notify_status,
            'notify_error' => $row->notify_error,
            'notified_at' => optional($row->notified_at)->toIso8601String(),
            'form' => $row->form ? ['id' => $row->form->id, 'name' => $row->form->name, 'slug' => $row->form->slug] : null,
            ...($withPayload ? ['payload' => $row->payload ?? []] : []),
        ];
    }
}
