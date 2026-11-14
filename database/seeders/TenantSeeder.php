<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('testing')) throw new \RuntimeException('演示样本仅供测试；安装请运行 yanji:install。');
        // 创建测试租户
        $tenant = Tenant::create([
            'subdomain' => 'demo',
            'name' => '演示租户',
            'plan' => 'pro',
            'status' => 'active',
            'settings' => [
                'brand_color' => '#D92121',
                'logo_url' => null,
            ],
        ]);

        // 创建管理员
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => '张三',
            'email' => 'fixture-user-3@example.com',
            'phone' => '13800000012',
            'phone_verified_at' => now(),
            'password' => 'test-demo-password',
            'role' => 'admin',
        ]);

        // 创建编辑
        $editor = User::create([
            'tenant_id' => Tenant::create(['subdomain' => 'demo-editor', 'name' => '演示工作区', 'status' => 'active'])->id,
            'name' => '王五',
            'email' => 'fixture-user-12@example.com',
            'phone' => '13800000013',
            'phone_verified_at' => now(),
            'password' => 'test-demo-password',
            'role' => 'admin',
        ]);

        // 创建普通成员
        $member = User::create([
            'tenant_id' => Tenant::create(['subdomain' => 'demo-member', 'name' => '演示工作区', 'status' => 'active'])->id,
            'name' => '李四',
            'email' => 'fixture-user-17@example.com',
            'phone' => '13800000014',
            'phone_verified_at' => now(),
            'password' => 'test-demo-password',
            'role' => 'admin',
        ]);

        // 创建测试项目
        $project = Project::create([
            'tenant_id' => $tenant->id,
            'owner_id' => $admin->id,
            'name' => 'XX品牌 GEO 优化',
            'brand_name' => 'XX科技',
            'brand_url' => 'https://www.example.com',
            'current_stage' => 2,
            'progress' => 33,
        ]);

        // 初始化六阶段
        foreach (Project::STAGES as $stage) {
            $status = match (true) {
                $stage['index'] < 2 => 'completed',
                $stage['index'] === 2 => 'active',
                default => 'pending',
            };

            ProjectStage::create([
                'project_id' => $project->id,
                'stage_index' => $stage['index'],
                'stage_key' => $stage['key'],
                'status' => $status,
                'progress' => $status === 'completed' ? 100 : ($status === 'active' ? 40 : 0),
                'started_at' => $status !== 'pending' ? now() : null,
                'completed_at' => $status === 'completed' ? now() : null,
            ]);
        }
    }
}
