<?php

namespace Tests\Feature;

use App\Services\Enums\SkillCategory;
use App\Services\SkillAgent\SkillRegistryService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SkillRegistryServiceTest extends TestCase
{
    public function test_it_loads_active_source_skills_and_preserves_curated_business_fields(): void
    {
        $path = storage_path('framework/testing/skill-registry.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'skills' => [
                [
                    'id' => 'yao-geo-panorama-audit',
                    'family' => 'geo-strategy',
                    'maturity' => 'stable',
                    'requires_web' => false,
                    'primary_outputs' => ['source-report'],
                    'status' => 'active',
                    'path' => 'skills/yao-geo-panorama-audit',
                    'summary' => 'Source registry summary should not replace curated form fields.',
                ],
                [
                    'id' => 'yao-geo-execution-roadmap',
                    'family' => 'geo-strategy',
                    'maturity' => 'stable',
                    'requires_web' => false,
                    'primary_outputs' => ['source-roadmap'],
                    'status' => 'active',
                    'path' => 'skills/yao-geo-execution-roadmap',
                    'summary' => 'Source registry roadmap summary should not replace curated form fields.',
                ],
                [
                    'id' => 'yao-geo-article-friendly',
                    'family' => 'geo-content-production',
                    'maturity' => 'scaffold',
                    'requires_web' => false,
                    'primary_outputs' => ['geo-optimized-article'],
                    'status' => 'active',
                    'path' => 'skills/yao-geo-article-friendly',
                    'summary' => 'Rewrite an existing article for GEO readiness.',
                ],
                [
                    'id' => 'yao-paused-skill',
                    'family' => 'geo-research',
                    'maturity' => 'beta',
                    'requires_web' => false,
                    'primary_outputs' => [],
                    'status' => 'paused',
                    'path' => 'skills/yao-paused-skill',
                    'summary' => 'Paused skills should not be runtime-visible.',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // This fixture explicitly approves its own IDs; production remains capped at 17.
        config(['geo.skill_registry_path' => $path, 'geo.skill_allowed_ids' => array_column(array_filter(json_decode(file_get_contents($path), true)['skills'], fn ($row) => ($row['status'] ?? null) === 'active'), 'id')]);

        $registry = new SkillRegistryService;
        $skills = $registry->all();

        $this->assertCount(3, $skills);
        $this->assertNull($registry->findOrNull('yao-paused-skill'));

        $panorama = $registry->find('yao-geo-panorama-audit');
        $this->assertSame(SkillCategory::GeoStrategy, $panorama->category);
        $this->assertSame('beta', $panorama->maturity);
        $this->assertTrue($panorama->requiresWeb);
        $this->assertSame(1, $panorama->stageIndex);
        $this->assertContains('brand_name', array_column($panorama->briefFields, 'key'));

        $panoramaBrandUrl = collect($panorama->briefFields)->firstWhere('key', 'brand_url');
        $this->assertSame('品牌官网（可选，没有官网可留空）', $panoramaBrandUrl['label']);
        $this->assertFalse($panoramaBrandUrl['required']);

        $roadmap = $registry->find('yao-geo-execution-roadmap');
        $roadmapBrandUrl = collect($roadmap->briefFields)->firstWhere('key', 'brand_url');
        $roadmapTimeline = collect($roadmap->briefFields)->firstWhere('key', 'timeline_days');
        $this->assertSame('品牌官网（可选，没有官网可留空）', $roadmapBrandUrl['label']);
        $this->assertFalse($roadmapBrandUrl['required']);
        $this->assertSame('计划执行周期（天，可选）', $roadmapTimeline['label']);
        $this->assertSame(90, $roadmapTimeline['default']);
        $this->assertSame(1, $roadmapTimeline['min']);
        $this->assertSame(365, $roadmapTimeline['max']);

        $articleFriendly = $registry->find('yao-geo-article-friendly');
        $this->assertSame(SkillCategory::GeoContentProduction, $articleFriendly->category);
        $this->assertSame('scaffold', $articleFriendly->maturity);
        $this->assertSame(2, $articleFriendly->stageIndex);
        $this->assertContains('topic', array_column($articleFriendly->briefFields, 'key'));
    }

    public function test_runtime_skill_ids_match_the_complete_active_set_in_the_registry_fixture(): void
    {
        // Contract fixture only. Actual mounted upstream assets are checked by geo:audit-skill-assets.
        $path = base_path('tests/fixtures/skills/registry-contract.json');
        $this->assertFileExists($path);

        // This fixture explicitly approves its own IDs; production remains capped at 17.
        config(['geo.skill_registry_path' => $path, 'geo.skill_allowed_ids' => array_column(array_filter(json_decode(file_get_contents($path), true)['skills'], fn ($row) => ($row['status'] ?? null) === 'active'), 'id')]);

        $source = json_decode((string) file_get_contents($path), true);
        $sourceIds = collect($source['skills'] ?? [])
            ->filter(fn ($skill) => is_array($skill) && ($skill['status'] ?? null) === 'active')
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $runtimeIds = collect((new SkillRegistryService)->all())
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($sourceIds, $runtimeIds);
        $this->assertContains('yao-deepseek-crawler', $runtimeIds);
        $this->assertContains('yao-chatgpt-crawler', $runtimeIds);
        $this->assertContains('yao-geo-article-friendly', $runtimeIds);
        $this->assertNotContains('yao-paused-contract-fixture', $runtimeIds);
        $this->assertCount(3, $runtimeIds);
    }

    public function test_page_blueprint_page_type_is_a_real_select_with_options(): void
    {
        config([
            'geo.skill_registry_path' => base_path('resources/skills/registry.json'),
            'geo.skill_allowed_ids' => ['yao-geo-page-blueprint'],
        ]);

        $skill = (new SkillRegistryService)->find('yao-geo-page-blueprint');
        $field = collect($skill->briefFields)->firstWhere('key', 'page_type');

        $this->assertSame('select', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertContains('服务页', $field['options']);
        $this->assertContains('FAQ 页', $field['options']);
    }
}
