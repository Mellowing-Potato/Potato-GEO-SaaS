<?php

namespace Tests\Feature;

use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\SkillAssetLoader;
use App\Services\SkillAgent\SkillAssets;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PortableRenderConfigurationTest extends TestCase
{
    public function test_renderer_loader_and_health_check_use_the_resolved_configuration(): void
    {
        $directory = storage_path('framework/testing/portable render '.uniqid());
        $python = $directory.'/python-without-runtime';
        config(['geo.python_bin' => $python, 'geo.skills_repo_path' => $directory]);

        $loader = new SkillAssetLoader;
        $this->assertSame($directory, $loader->getSkillsRoot());
        $assets = new SkillAssets('renderer-test', '', [], '/fixture/render.py', null, true);
        $command = new \ReflectionMethod(RenderBridgeService::class, 'commandFor');
        $argv = $command->invoke(new RenderBridgeService, $assets, [], $directory, 'input.json', 'output');
        $this->assertSame($python, $argv[0]);

        Artisan::call('geo:health-check', ['--json' => true]);
        $health = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $checks = array_column($health['checks'], null, 'key');
        $this->assertFalse($checks['python_docx_available']['ok']);
        $this->assertSame($directory, $health['skills_root']);
    }
}
