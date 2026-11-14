<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function disabled(): JsonResponse
    {
        return response()->json(['message' => '当前产品使用独立工作区，不支持团队成员操作。'], 410);
    }

    // The assignment selector still uses this endpoint; never expose other accounts.
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json(['data' => [[
            'id' => $user->id,
            'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
            'phone_verified' => $user->phone_verified_at !== null,
            'role' => $user->role, 'permissions' => $user->getPermissions(),
            'is_self' => true,
        ]], 'total' => 1]);
    }
}
