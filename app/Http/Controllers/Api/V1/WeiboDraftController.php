<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ManualPublicationConflictException;
use App\Http\Controllers\Controller;
use App\Models\ManualPublication;
use App\Services\Publishing\WeiboBrowserDraftService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeiboDraftController extends Controller
{
    public function show(Request $request, int $id, WeiboBrowserDraftService $drafts): JsonResponse
    {
        return response()->json(['data' => $drafts->show(ManualPublication::query()->findOrFail($id), $request->user())]);
    }

    public function start(Request $request, int $id, WeiboBrowserDraftService $drafts): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'],
            'publication_title' => ['required', 'string', 'max:128']]);

        return $this->run(fn () => $drafts->start(ManualPublication::query()->findOrFail($id),
            $data['revision'], $data['publication_title'], $request->user()));
    }

    public function progress(Request $request, int $id, WeiboBrowserDraftService $drafts): JsonResponse
    {
        $data = $request->validate([
            'delivery_id' => ['required', 'integer', 'min:1'], 'token' => ['required', 'uuid'],
            'stage' => ['required', 'in:creating,created,saving,verifying,saved,error'],
            'uid' => ['required_unless:stage,error', 'string', 'regex:/^\d{4,24}$/D'],
            'draft_id' => ['required_if:stage,created,saving,verifying,saved', 'nullable', 'string', 'regex:/^\d{1,24}$/D'],
            'code' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z_]+$/D'],
            'title' => ['required_if:stage,saved', 'string', 'max:128'],
            'text_hash' => ['required_if:stage,saved', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'links' => ['present_if:stage,saved', 'array', 'max:200'],
            'links.*' => ['string', 'max:2048', 'url:http,https'],
            'image_count' => ['required_if:stage,saved', 'integer', 'min:0', 'max:20'],
            'helper_version' => ['required_if:stage,saved', 'string', 'max:24'],
            'images' => ['present_if:stage,verifying', 'array', 'max:20'],
            'images.*' => ['string', 'max:2048', 'url:https'],
        ]);

        return $this->run(fn () => $drafts->progress(ManualPublication::query()->findOrFail($id), $data, $request->user()));
    }

    private function run(callable $action): JsonResponse
    {
        try {
            return response()->json(['data' => $action()]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (ManualPublicationConflictException) {
            return response()->json(['message' => '发布单、审核版本或账号已变化，请停止写入并刷新核对。'], 409);
        }
    }
}
