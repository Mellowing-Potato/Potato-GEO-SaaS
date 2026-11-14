<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ManualPublicationConflictException;
use App\Http\Controllers\Controller;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\WeChatDraftDelivery;
use App\Models\WeChatOfficialAccountConnection;
use App\Services\Publishing\ManualPublicationService;
use App\Services\Publishing\WeChatDraftDeliveryService;
use App\Services\Publishing\WeChatOfficialAccountClient;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WeChatDraftController extends Controller
{
    public function connection(int $id): JsonResponse
    {
        $account = $this->account($id);

        return response()->json(['data' => WeChatOfficialAccountConnection::query()->where('account_id', $account->id)->first()?->publicData()]);
    }

    public function connect(Request $request, int $id, WeChatOfficialAccountClient $client): JsonResponse
    {
        $account = $this->account($id);
        $data = $request->validate([
            'app_id' => ['required', 'string', 'regex:/\Awx[a-f0-9]{16}\z/i', Rule::unique('wechat_official_account_connections')->where('tenant_id', $account->tenant_id)->ignore($account->id, 'account_id')],
            'app_secret' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/i'],
            'author' => ['nullable', 'string', 'max:16', 'not_regex:/[\x00-\x1f\x7f]/u'],
            'account_checked' => ['required', 'accepted'],
        ]);
        try {
            $client->validate($data['app_id'], $data['app_secret']);
            $connection = DB::transaction(function () use ($account, $data) {
                ManualPublicationAccount::query()->lockForUpdate()->findOrFail($account->id);
                $connection = WeChatOfficialAccountConnection::query()->firstOrNew(['account_id' => $account->id]);
                // Changing AppID would detach existing immutable receipts from their real account.
                if ($connection->exists && $connection->app_id !== $data['app_id']) {
                    throw new DomainException('此账号已连接其他 AppID；请新建公众号账号，避免混淆已有草稿回执。');
                }
                $connection->fill([
                    'tenant_id' => $account->tenant_id, 'app_id' => $data['app_id'], 'app_secret' => $data['app_secret'],
                    'author' => trim($data['author'] ?? ''), 'verified_at' => now(), 'revision' => $connection->exists ? $connection->revision + 1 : 1,
                ])->save();

                return $connection;
            });
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => $connection->publicData(), 'message' => '公众号接口凭据已验证；账号名称由管理员核对。']);
    }

    public function status(Request $request, int $id, ManualPublicationService $publications): JsonResponse
    {
        $publication = ManualPublication::query()->findOrFail($id);
        $publications->assertCan($request->user(), $publication, 'view');
        abort_unless($publication->platform === 'wechat', 404);

        return response()->json([
            'data' => WeChatDraftDelivery::query()->where('publication_id', $publication->id)->first()?->publicData(),
            'connection' => WeChatOfficialAccountConnection::query()->where('account_id', $publication->account_id)->first()?->publicData(),
        ]);
    }

    public function send(Request $request, int $id, WeChatDraftDeliveryService $service): JsonResponse
    {
        $publication = ManualPublication::query()->findOrFail($id);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'], 'publication_title' => ['required', 'string', 'max:64'],
            'cover' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'account_checked' => ['required', 'accepted'], 'create_new_draft' => ['required', 'accepted'],
        ]);
        try {
            $delivery = $service->enqueue($publication, $data['revision'], $data['publication_title'], $request->file('cover'), $request->user());
        } catch (ManualPublicationConflictException $error) {
            return response()->json(['message' => $error->getMessage()], 409);
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => $delivery->publicData()], 202);
    }

    public function retry(Request $request, int $id, WeChatDraftDeliveryService $service): JsonResponse
    {
        $publication = ManualPublication::query()->findOrFail($id);
        try {
            $delivery = $service->retry($publication, $request->user());
        } catch (ManualPublicationConflictException $error) {
            return response()->json(['message' => $error->getMessage()], 409);
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => $delivery->publicData()], 202);
    }

    public function reconcile(Request $request, int $id, WeChatDraftDeliveryService $service): JsonResponse
    {
        $publication = ManualPublication::query()->findOrFail($id);
        try {
            $delivery = $service->reconcile($publication, $request->user());
        } catch (DomainException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => $delivery->publicData()]);
    }

    private function account(int $id): ManualPublicationAccount
    {
        $account = ManualPublicationAccount::query()->with('persona')->findOrFail($id);
        abort_unless($account->platform === 'wechat' && $account->is_active && $account->persona?->is_active, 422, '请选择启用中的微信公众号账号。');

        return $account;
    }
}
