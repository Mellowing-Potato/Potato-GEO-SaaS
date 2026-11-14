<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Http\Request;

final class ProjectKnowledge
{
    public static function requestedProject(Request $request): int
    {
        $id = $request->header('X-Project-Id') ?? $request->input('project_id');
        abort_unless(filter_var($id, FILTER_VALIDATE_INT) && (int) $id > 0, 422, '请先选择项目。');
        abort_unless(Project::where('tenant_id', TenantContext::id())->whereKey($id)->exists(), 404, '项目不存在。');
        return (int) $id;
    }

    public static function assertOwnership($record): void
    {
        abort_unless((int) $record->tenant_id === TenantContext::id(), 404);
        abort_unless($record->project_id, 409, '这份历史资料尚未分配项目，请先确认归属。');
        abort_unless(Project::where('tenant_id', TenantContext::id())->whereKey($record->project_id)->exists(), 404, '项目不存在。');
        if (request()->hasHeader('X-Project-Id')) {
            abort_unless(self::requestedProject(request()) === (int) $record->project_id, 404, '当前项目中没有这份资料。');
        }
    }
}
