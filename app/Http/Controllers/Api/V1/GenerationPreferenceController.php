<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProjectGenerationPreference;
use App\Services\Content\GenerationPreferenceService;
use App\Support\ProjectKnowledge;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GenerationPreferenceController extends Controller
{
    private function query(Request $request)
    {
        return ProjectGenerationPreference::where('tenant_id', TenantContext::id())->where('project_id', ProjectKnowledge::requestedProject($request));
    }
    public function index(Request $request) { return response()->json(['data' => $this->query($request)->get()]); }
    public function show(Request $request, string $skill) { return response()->json(['data' => $this->query($request)->where('skill_id', $skill)->first()]); }
    public function update(Request $request, string $skill)
    {
        $projectId = ProjectKnowledge::requestedProject($request);
        abort_unless(strlen($skill) <= 150 && preg_match('/^[a-zA-Z0-9_-]+$/', $skill), 422, '技能标识无效。');
        $data = $request->validate([
            'tone' => ['required', Rule::in(array_keys(GenerationPreferenceService::TONES))],
            'structure' => ['required', Rule::in(array_keys(GenerationPreferenceService::STRUCTURES))],
            'length' => ['required', Rule::in(array_keys(GenerationPreferenceService::LENGTHS))],
            'requirements' => 'nullable|string|max:1000',
        ]);
        $record = ProjectGenerationPreference::updateOrCreate(['tenant_id' => TenantContext::id(), 'project_id' => $projectId, 'skill_id' => $skill], ['preferences' => $data]);
        return response()->json(['data' => $record]);
    }
    public function reset(Request $request, string $skill)
    {
        $this->query($request)->where('skill_id', $skill)->delete();
        return response()->json(['message' => '当前项目的该项偏好已恢复默认。']);
    }
}
