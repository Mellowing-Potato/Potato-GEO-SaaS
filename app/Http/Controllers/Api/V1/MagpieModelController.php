<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ModelConnectionProfile;
use App\Services\AI\MagpieException;
use App\Services\AI\MagpieGateway;
use App\Services\AI\ModelConnectionTester;
use App\Services\AI\ModelSources;
use App\Support\Concerns\RecordsSensitiveAction;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MagpieModelController extends Controller
{
    use RecordsSensitiveAction;

    public function __construct(private readonly ModelSources $sources, private readonly MagpieGateway $gateway) {}

    public function index(): JsonResponse
    {
        return response()->json($this->sources->listing());
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
