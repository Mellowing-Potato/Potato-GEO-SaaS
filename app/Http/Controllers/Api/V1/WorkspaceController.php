<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:80']);
        $name = trim($validated['name']);
        abort_if($name === '', 422, '请输入工作区名称。');
        $tenant = Tenant::findOrFail(TenantContext::id());
        $tenant->update(['name' => $name]);

        return response()->json(['tenant' => $tenant, 'message' => '工作区名称已保存。']);
    }
}
