<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\KnowledgeBase;
use App\Models\Project;
use App\Support\ProjectKnowledge;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyKnowledgeController extends Controller
{
    public function index()
    {
        return response()->json(['bases' => KnowledgeBase::whereNull('project_id')->withCount('documents')->get(),
            'archives' => EnterpriseKnowledgeProject::whereNull('project_id')->whereDoesntHave('publishedKnowledgeBase')->get()]);
    }

    public function assign(Request $request)
    {
        $projectId = ProjectKnowledge::requestedProject($request);
        $data = $request->validate(['kind' => 'required|in:base,archive', 'id' => 'required|integer']);
        DB::transaction(function () use ($data, $projectId) {
            $model = $data['kind'] === 'base' ? KnowledgeBase::class : EnterpriseKnowledgeProject::class;
            $record = $model::whereKey($data['id'])->lockForUpdate()->firstOrFail();
            abort_unless($record->project_id === null, 409, '资料已经分配，请刷新页面。');
            if ($data['kind'] === 'archive') {
                abort_if($record->publishedKnowledgeBase()->exists(), 409, '该档案已关联知识库，请从历史知识库确认项目归属。');
            }
            if ($data['kind'] === 'base') {
                $documents = $record->documents()->lockForUpdate()->get();
                // Preserve existing document/billing attribution. A historical
                // mixed base is split; only previously unassigned documents are
                // assigned to the project explicitly chosen by the user.
                foreach ($documents->groupBy(fn ($doc) => $doc->project_id ?: $projectId) as $ownerId => $group) {
                    abort_unless(Project::where('tenant_id', TenantContext::id())->whereKey($ownerId)->exists(), 409, '部分资料原属项目已删除，请先联系支持确认归属。');
                    $base = (int) $ownerId === $projectId ? $record : KnowledgeBase::create([
                        'tenant_id' => $record->tenant_id, 'project_id' => $ownerId,
                        'name' => $record->name, 'description' => $record->description,
                        'status' => $record->status, 'settings' => $record->settings,
                    ]);
                    foreach ($group as $doc) {
                        $doc->update(['knowledge_base_id' => $base->id, 'project_id' => $ownerId]);
                        EnterpriseKnowledgeProject::where('published_document_id', $doc->id)
                            ->update(['project_id' => $ownerId, 'published_knowledge_base_id' => $base->id]);
                    }
                }
                EnterpriseKnowledgeProject::where('published_knowledge_base_id', $record->id)->whereNull('project_id')->update(['project_id' => $projectId]);
            }
            $record->update(['project_id' => $projectId]);
        });
        return response()->json(['message' => '资料项目归属已确认。']);
    }
}
