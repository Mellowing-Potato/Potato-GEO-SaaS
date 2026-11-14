<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\User;
use App\Services\Publishing\PublicationPlatform;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * 手动发布 · 人设 / 账号维护（单 7）。
 *
 * 路由组已挂 auth:sanctum + tenant + can:manual_publish:manage（见 routes/api.php）。
 * 其中「写」（store/update）再收一道 can:manual_publish:settings —— 仅 admin。人设 / 账号是
 * 全租户共用的主数据，一个人改错影响所有单；「读」（list）留在 manage，否则 editor 建不了单
 * （新建发布单的人设 / 账号下拉框就是拉这两个列表，2026-08-28 拍板）。
 * created_by_user_id 固定写当前操作人（自建 RBAC，无 admins 表，见执行书 §一-3）。
 */
class ManualPublicationSettingsController extends Controller
{
    public function listPersonas(Request $request): JsonResponse
    {
        // 评审项 8：含停用行（前端已渲染启用/停用徽标）——destroy 只置 is_active=false，
        // 若列表只回 active，停用的人设/账号在界面上永远消失、无法恢复（updatePersona 可置回 true）。
        $personas = ManualPublicationPersona::query()
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $personas]);
    }

    public function storePersona(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'bio' => ['nullable', 'string'],
            'tone' => ['nullable', 'string', 'max:120'],
            'domain' => ['nullable', 'string', 'max:120'],
            'disclosure_text' => ['nullable', 'string'],
        ]);

        $persona = ManualPublicationPersona::query()->create([
            ...$validated,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json(['data' => $persona], SymfonyResponse::HTTP_CREATED);
    }

    public function updatePersona(Request $request, int $id): JsonResponse
    {
        $persona = ManualPublicationPersona::query()->whereKey($id)->firstOrFail();
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'bio' => ['nullable', 'string'],
            'tone' => ['nullable', 'string', 'max:120'],
            'domain' => ['nullable', 'string', 'max:120'],
            'disclosure_text' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $persona->update($validated);

        return response()->json(['data' => $persona]);
    }

    public function listAccounts(Request $request): JsonResponse
    {
        // 评审项 8：同 listPersonas，含停用行
        $accounts = ManualPublicationAccount::query()
            ->with('persona:id,name')
            ->orderBy('account_name')
            ->get();

        return response()->json(['data' => $accounts, 'platforms' => ManualPublicationAccount::PLATFORMS]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'persona_id' => ['required', 'integer'],
            'platform' => ['required', 'string', 'in:' . implode(',', ManualPublicationAccount::PLATFORMS)],
            'custom_platform' => ['nullable', 'string', 'max:64'],
            'account_name' => ['required', 'string', 'max:120'],
            'profile_url' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->validateAccount($validated);
        $account = ManualPublicationAccount::query()->create([
            ...$validated,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json(['data' => $account], SymfonyResponse::HTTP_CREATED);
    }

    public function updateAccount(Request $request, int $id): JsonResponse
    {
        $account = ManualPublicationAccount::query()->whereKey($id)->firstOrFail();
        $validated = $request->validate([
            'persona_id' => ['sometimes', 'required', 'integer'],
            'platform' => ['sometimes', 'required', 'string', 'in:' . implode(',', ManualPublicationAccount::PLATFORMS)],
            'custom_platform' => ['nullable', 'string', 'max:64'],
            'account_name' => ['sometimes', 'required', 'string', 'max:120'],
            'profile_url' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->validateAccount(array_merge($account->only(['persona_id', 'platform', 'custom_platform', 'profile_url']), $validated));
        $account->update($validated);

        return response()->json(['data' => $account]);
    }

    private function validateAccount(array $data): void
    {
        if (! ManualPublicationPersona::query()->whereKey($data['persona_id'])->exists()) {
            throw ValidationException::withMessages(['persona_id' => '请选择当前工作区的人设。']);
        }
        if ($data['platform'] === 'custom' && trim((string) ($data['custom_platform'] ?? '')) === '') {
            throw ValidationException::withMessages(['custom_platform' => '请填写平台名称。']);
        }
        $url = trim((string) ($data['profile_url'] ?? ''));
        if ($url !== '' && ! PublicationPlatform::acceptsUrl($data['platform'], $url)) {
            throw ValidationException::withMessages(['profile_url' => '请填写所选平台的有效公开主页链接。']);
        }
    }
}
