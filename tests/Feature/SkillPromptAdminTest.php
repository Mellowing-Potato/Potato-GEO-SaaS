<?php
namespace Tests\Feature;

use App\Models\{Project, Prompt, ProjectGenerationPreference, Tenant, User};
use App\Services\Content\GenerationPreferenceService;
use App\Services\SkillAgent\SkillAssets;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SkillPromptAdminTest extends TestCase
{
    use RefreshDatabase;
    private const SKILL = 'yao-geo-panorama-audit';
    private function identity(string $name): array
    {
        $tenant = Tenant::create(['name' => $name, 'subdomain' => $name, 'status' => 'active', 'plan' => 'basic']);
        TenantContext::set($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $projects = [];
        foreach (['a', 'b'] as $name) {
            $project = new Project();
            $project->forceFill(['tenant_id' => $tenant->id, 'owner_id' => $user->id, 'name' => $name, 'status' => 'active'])->save();
            $projects[] = $project;
        }
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Tenant', $tenant->subdomain)->withHeader('X-Project-Id', $projects[0]->id);
        return [$tenant, $user, ...$projects];
    }
    protected function tearDown(): void { TenantContext::forget(); parent::tearDown(); }
    private function preferences(): array { return ['tone' => 'plain', 'structure' => 'qa', 'length' => 'concise', 'requirements' => '解释术语']; }
    public function test_preferences_save_read_reset_and_never_cross_projects_or_accounts(): void
    {
        [$tenant, $user, $a, $b] = $this->identity('pref-a');
        $url = '/api/v1/generation-preferences/'.self::SKILL;
        $this->putJson($url, $this->preferences())->assertOk()->assertJsonPath('data.project_id', $a->id);
        $this->getJson($url)->assertOk()->assertJsonPath('data.preferences.tone', 'plain');
        $this->withHeader('X-Project-Id', $b->id)->getJson('/api/v1/generation-preferences')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson($url)->assertOk();
        $this->withHeader('X-Project-Id', $a->id)->getJson($url)->assertJsonPath('data.preferences.requirements', '解释术语');
        $this->identity('pref-b');
        $this->getJson('/api/v1/generation-preferences')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeader('X-Project-Id', $a->id)->putJson($url, $this->preferences())->assertNotFound();
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Tenant', $tenant->subdomain)->deleteJson($url)->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data', null);
    }
    public function test_legacy_editor_is_closed_and_project_and_valid_fields_are_required(): void
    {
        $this->identity('pref-valid');
        $this->putJson('/api/v1/skill-prompts/'.self::SKILL, ['prompt_text' => 'replace everything'])->assertStatus(410);
        $this->getJson('/api/v1/skill-prompts')->assertStatus(410);
        $this->putJson('/api/v1/generation-preferences/'.self::SKILL, ['tone' => 'bad'])->assertUnprocessable();
        $this->withHeader('X-Project-Id', '')->getJson('/api/v1/generation-preferences')->assertUnprocessable();
    }
    public function test_preferences_preserve_core_assets_and_ignore_legacy_overrides(): void
    {
        [$tenant, $user, $a, $b] = $this->identity('pref-core');
        Prompt::create(['tenant_id' => $tenant->id, 'skill_id' => self::SKILL, 'prompt_text' => 'OLD REPLACEMENT', 'is_active' => true]);
        $assets = new SkillAssets(self::SKILL, 'PLATFORM CORE', ['source' => 'CITATION RULE'], 'render.py', '{schema}', true);
        $service = app(GenerationPreferenceService::class);
        $this->assertSame($assets, $service->apply($assets, self::SKILL, $a->id));
        ProjectGenerationPreference::create(['tenant_id' => $tenant->id, 'project_id' => $a->id, 'skill_id' => self::SKILL, 'preferences' => $this->preferences()]);
        $result = $service->apply($assets, self::SKILL, $a->id);
        $this->assertStringStartsWith('PLATFORM CORE', $result->skillMd);
        $this->assertStringContainsString('解释术语', $result->skillMd);
        $this->assertStringNotContainsString('OLD REPLACEMENT', $result->skillMd);
        $this->assertSame($assets->references, $result->references);
        $this->assertSame($assets->renderScript, $result->renderScript);
        $this->assertSame($assets->exampleJsonSchema, $result->exampleJsonSchema);
        $this->assertSame($assets, $service->apply($assets, self::SKILL, $b->id));
        $this->assertSame($assets, $service->apply($assets, self::SKILL, null));
    }
}
