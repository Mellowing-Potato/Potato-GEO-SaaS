<?php

namespace Tests\Feature;

use App\Services\Exceptions\SkillRegistryException;
use App\Services\SkillAgent\SkillRegistryService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SkillCatalogContractTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = tempnam(sys_get_temp_dir(), 'catalog-');
    }

    protected function tearDown(): void
    {
        unlink($this->fixture);
        parent::tearDown();
    }

    public function test_release_catalog_contains_approved_upstream_skills_and_local_business_entry(): void
    {
        $ids = array_map(fn ($skill) => $skill->id, (new SkillRegistryService)->all());
        $this->assertCount(16, $ids);
        $this->assertSame(require config_path('skill-allowlist.php'), $ids);
        $lock = json_decode(file_get_contents(resource_path('skills/upstream-lock.json')), true);
        $this->assertSame(array_values(array_diff($ids, SkillRegistryService::LOCAL_ACTION_IDS)), $lock['approved_skill_ids']);
        $this->assertSame('136eb92c90946ea56ec63f912d5025bcbc884f39', $lock['upstream_commit']);
        foreach (['yao-deepseek-crawler', 'yao-doubao-crawler', 'yao-chatgpt-crawler', 'yao-geo-article-friendly', 'yao-geoflow-template', 'yao-geoflow-design'] as $id) {
            $this->assertNotContains($id, $ids);
        }
    }

    public function test_external_active_rows_cannot_expand_catalog_or_replace_local_entry_gates(): void
    {
        config(['geo.visibility_panel_enabled' => false]);
        $before = (new SkillRegistryService)->all();
        $rows = array_map(fn ($skill) => ['id' => $skill->id, 'status' => 'active',
            'action_path' => '/unreviewed', 'unavailable_reason' => null, 'name' => 'Upstream name'], $before);
        foreach (['yao-deepseek-crawler', 'yao-doubao-crawler', 'yao-chatgpt-crawler', 'yao-geo-article-friendly'] as $id) {
            $rows[] = ['id' => $id, 'status' => 'active'];
        }
        file_put_contents($this->fixture, json_encode(['skills' => array_reverse($rows)]));
        config(['geo.skill_registry_path' => $this->fixture]);
        $after = (new SkillRegistryService)->all();
        $this->assertEquals($before, $after, 'All local definitions and their order must survive switching a valid source registry.');
        $monitor = (new SkillRegistryService)->find('yao-geo-effect-monitor');
        $this->assertSame('/visibility', $monitor->actionPath);
        $this->assertNotNull($monitor->actionLabel);
        $this->assertFalse($monitor->isAvailable());
        config(['geo.visibility_panel_enabled' => true]);
        $this->assertTrue((new SkillRegistryService)->find('yao-geo-effect-monitor')->isAvailable());
    }

    #[DataProvider('invalidSources')]
    public function test_bad_config_never_silently_restores_the_catalog(?string $source): void
    {
        if ($source === null) {
            config(['geo.skill_registry_path' => $this->fixture.'-missing']);
        } else {
            file_put_contents($this->fixture, $source);
            config(['geo.skill_registry_path' => $this->fixture]);
        }
        config(['geo.skill_allowed_ids' => ['yao-geo-panorama-audit']]);
        $this->expectException(SkillRegistryException::class);
        (new SkillRegistryService)->all();
    }

    public static function invalidSources(): array
    {
        return [
            'missing file' => [null],
            'malformed JSON' => ['{bad'],
            'wrong shape' => ['{"skills":42}'],
            'object instead of list' => ['{"skills":{"one":{"id":"yao-geo-panorama-audit","status":"active"}}}'],
            'empty' => ['{"skills":[]}'],
            'omitted approved skill' => ['{"skills":[{"id":"unapproved","status":"active"}]}'],
            'paused approved skill' => ['{"skills":[{"id":"yao-geo-panorama-audit","status":"paused"}]}'],
            'duplicate ID' => ['{"skills":[{"id":"yao-geo-panorama-audit","status":"active"},{"id":"yao-geo-panorama-audit","status":"paused"}]}'],
            'traversal ID' => ['{"skills":[{"id":"../outside","status":"active"}]}'],
        ];
    }
}
