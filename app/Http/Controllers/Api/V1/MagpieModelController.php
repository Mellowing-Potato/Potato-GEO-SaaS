<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ModelConnectionProfile;
use App\Models\User;
use App\Services\AI\MagpieException;
use App\Services\AI\MagpieGateway;
use App\Services\AI\ModelConnectionTester;
use App\Services\AI\ModelSources;
use App\Services\Outbound\LocalMagpieDiscovery;
use App\Services\Auth\LocalAccounts;
use App\Support\Concerns\RecordsSensitiveAction;
use App\Support\InstanceKey;
use App\Support\LocalMagpieAuthorization;
use App\Support\PortableDesktopAccess;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class MagpieModelController extends Controller
{
    use RecordsSensitiveAction;

    public function __construct(private readonly ModelSources $sources, private readonly MagpieGateway $gateway) {}

    public function index(): JsonResponse
    {
        return $this->respond(fn () => response()->json($this->sources->listing()
            + ['local_connect_available' => PortableDesktopAccess::enabled()]));
    }

    public function localConnect(Request $request, LocalMagpieDiscovery $discovery, LocalMagpieAuthorization $authorization): JsonResponse
    {
        PortableDesktopAccess::assertLocalRequest($request);
        abort_unless($request->json('consent') === true && array_diff(array_keys($request->json()->all()), ['consent']) === [],
            422, '请明确同意连接本机 Magpie。无需填写其他地址或账号信息。');
        $userId = (int) $request->user()->id;
        $tenantId = (int) $request->user()->tenant_id;
        abort_unless($tenantId > 0 && $tenantId === TenantContext::id(), 403);

        return $this->respond(function () use ($request, $discovery, $authorization, $userId, $tenantId): JsonResponse {
            try {
                $path = LocalMagpieAuthorization::desktopEnvironmentPath();
                $binding = InstanceKey::assertReady($path, true);
                // Consent permits only the fixed, credential-free identity request. No model request runs here.
                $service = $discovery->probe(true);
                $alias = DB::transaction(function () use ($path, $binding, $authorization, $userId, $tenantId): string {
                    DB::table('instance_settings')->where('id', 1)->lockForUpdate()->first();
                    $user = User::withoutGlobalScopes()->whereKey($userId)->lockForUpdate()->first();
                    abort_unless($user && (int) $user->tenant_id === $tenantId && TenantContext::id() === $tenantId, 403);
                    LocalAccounts::assertActive($user);
                    PortableDesktopAccess::assertEnabled();

                    return $authorization->grant($path, $tenantId, $binding);
                });
                $listing = $this->sources->listing();
                $target = collect($listing['targets'])->firstWhere('alias', $alias);
                if ($target === null) {
                    throw new \RuntimeException('本机模型授权未生效。');
                }

                return response()->json($listing + ['success' => true, 'local_connect_available' => true,
                    'local_target_alias' => $alias, 'local_target' => $target, 'service' => $service,
                    'message' => '已连接本机 Magpie。请选择并保存需要的模型；当前来源保持不变。'])
                    ->header('Cache-Control', 'no-store, private');
            } catch (MagpieException $error) {
                throw $error;
            } catch (HttpExceptionInterface $error) {
                throw $error;
            } catch (Throwable) {
                return response()->json(['success' => false, 'code' => 'local_authorization_failed',
                    'message' => '无法安全连接本机 Magpie，未切换当前模型。请检查本机实例与服务状态。'], 422);
            }
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request, null);
    }

    public function update(Request $request, int $profile): JsonResponse
    {
        return $this->save($request, $profile);
    }

    public function directory(int $profile): JsonResponse
    {
        return $this->respond(function () use ($profile): JsonResponse {
            $saved = $this->sources->requireProfile($profile);
            $result = $this->gateway->directory($saved, refresh: true);

            return response()->json($result + ['revision' => $saved->revision]);
        });
    }

    public function test(Request $request, ModelConnectionTester $tester, int $profile): JsonResponse
    {
        return $this->respond(function () use ($request, $tester, $profile): JsonResponse {
            $saved = $this->sources->requireProfile($profile);
            $result = $tester->testProfile($saved, (int) $request->user()->id);

            return response()->json($result, $result['success'] ? 200 : 422);
        });
    }

    public function bind(Request $request, string $slot): JsonResponse
    {
        return $this->respond(function () use ($request, $slot): JsonResponse {
            $binding = $this->sources->bind($slot, $request->all());
            $this->auditSensitive('model_source.selected', $binding, [
                'slot' => $slot,
                'source_type' => $binding->source_type,
                'profile_id' => $binding->profile_id,
            ]);

            return response()->json($this->sources->publicBinding($binding));
        });
    }

    public function destroy(int $profile): JsonResponse
    {
        $deleted = $this->sources->delete($profile);
        $this->auditProfile('deleted', $deleted);

        return response()->json(['message' => 'Magpie 配置已删除。']);
    }

    private function save(Request $request, ?int $profile): JsonResponse
    {
        return $this->respond(function () use ($request, $profile): JsonResponse {
            $saved = $this->sources->save($request->all(), $profile);
            $this->auditProfile('saved', $saved);

            return response()->json($this->sources->publicProfile($saved), $profile === null ? 201 : 200);
        });
    }

    private function auditProfile(string $action, ModelConnectionProfile $profile): void
    {
        $this->auditSensitive('magpie_profile.'.$action, $profile, [
            'slot' => $profile->slot,
            'enabled' => $profile->enabled,
        ]);
    }

    private function respond(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (MagpieException $e) {
            return response()->json(['success' => false, 'code' => $e->reason, 'message' => $e->getMessage()],
                $e->reason === 'target_unauthorized' ? 403 : 422);
        }
    }
}
