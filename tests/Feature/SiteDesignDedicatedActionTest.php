<?php

namespace Tests\Feature;

use App\Services\SkillAgent\SkillRegistryService;
use Tests\TestCase;

class SiteDesignDedicatedActionTest extends TestCase
{
    public function test_site_design_uses_the_real_theme_package_entry(): void
    {
        config(['geo.theme_package_enabled' => true]);

        $skill = (new SkillRegistryService)->find('yao-geoflow-design');

        $this->assertNotNull($skill);
        $this->assertSame('参考站风格提取、主题生成与安全预览', $skill->description);
        $this->assertSame([], $skill->briefFields);
        $this->assertSame('/theme-package', $skill->actionPath);
        $this->assertSame('进入站点设计', $skill->actionLabel);
        $this->assertTrue($skill->isAvailable());
    }

    public function test_site_design_is_unavailable_when_theme_packages_are_disabled(): void
    {
        config(['geo.theme_package_enabled' => false]);

        $skill = (new SkillRegistryService)->find('yao-geoflow-design');

        $this->assertNotNull($skill);
        $this->assertFalse($skill->isAvailable());
        $this->assertSame('站点设计当前未开放。', $skill->unavailableReason);
    }
}
