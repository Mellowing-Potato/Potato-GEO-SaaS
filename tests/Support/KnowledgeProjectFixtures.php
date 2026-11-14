<?php

namespace Tests\Support;

use App\Models\{Project, User};

trait KnowledgeProjectFixtures
{
    private static function knowledgeProjectId(int $tenantId): int
    {
        $existing = Project::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('id');
        if ($existing) return (int) $existing;
        $owner = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->first()
            ?? User::factory()->create(['tenant_id' => $tenantId]);
        $project = new Project();
        $project->forceFill(['tenant_id' => $tenantId, 'owner_id' => $owner->id, 'name' => '资料所属项目', 'status' => 'active'])->save();
        return $project->id;
    }
}
