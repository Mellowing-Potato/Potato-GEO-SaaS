<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePublication;
use App\Models\Project;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * geo:distribution-smoke 的覆盖（计划 §7 第 4 条的前置条件）。
 *
 * 这条命令是"用真 WordPress 探发布链路"的工具，所以它的**真实**验收在计划 §8
 * （外站发布真实 E2E，需要真站点，本会话没有）。能在这里证明的是它的**代码路径**：
 * 参数校验、渠道/文章/publication 的建立、经分发引擎同步发布、remote_url 断言、
 * 以及默认清理临时记录（--keep 才保留）。
 *
 * 与计划 §8 的关系写在文档里：这不是"外站发布 E2E 已完成"，只是把这条命令的行为
 * 钉住，免得它作为最后一个没测试的闭包一直留在 routes/console.php。
 */
class DistributionSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // 命令会把 Article::$autoDistributionEnabled 置 false 且**不还原**（它是一次性工具）。
        // 静态标记会跨用例泄漏，所以测试结束必须自己复位，否则后续用例的自动扇出会静默失效。
        Article::$autoDistributionEnabled = true;
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_smoke_requires_endpoint_username_secret_and_tenant(): void
    {
        $exit = Artisan::call('geo:distribution-smoke', ['--json' => true]);

        $this->assertSame(1, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('缺少必填参数', $output);
        $this->assertStringContainsString('--endpoint', $output);
        $this->assertStringContainsString('--tenant', $output);
    }

    public function test_smoke_publishes_through_the_engine_and_cleans_up(): void
    {
        [$tenant, $user] = $this->makeTenantUser('smoke-tenant');
        $this->makeProject($tenant, $user);

        Http::fake([
            'https://wp.example.test/wp-json/wp/v2/posts' => Http::response([
                'id' => 4242,
                'link' => 'https://wp.example.test/?p=4242',
            ], 201),
        ]);

        [$exit, $payload] = $this->runSmoke($tenant, []);
        $this->assertSame(0, $exit, '发布成功时命令必须 SUCCESS');
        $this->assertTrue($payload['pass']);
        $this->assertSame('published', $payload['status']);
        $this->assertSame('https://wp.example.test/?p=4242', $payload['remote_url']);
        // remote_id 的形态由发布器决定（WP 回包里的 id 可能被当字符串存），只断言值本身。
        $this->assertSame('4242', (string) $payload['remote_id']);
        $this->assertSame(201, (int) $payload['http_status']);

        // 默认清理：临时 channel / article / publication 都不留下
        $this->assertDatabaseMissing('publishing_channels', ['id' => $payload['channel_id']]);
        $this->assertDatabaseMissing('articles', ['id' => $payload['article_id']]);
        $this->assertDatabaseMissing('article_publications', ['id' => $payload['publication_id']]);

        // 渠道确实是用命令参数建的，且指向被 fake 的 WP 站点
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://wp.example.test/wp-json/wp/v2/posts'
                && $request->method() === 'POST';
        });
    }

    public function test_smoke_keep_flag_leaves_temporary_records_for_inspection(): void
    {
        [$tenant, $user] = $this->makeTenantUser('smoke-keep');
        $this->makeProject($tenant, $user);

        Http::fake([
            'https://wp.example.test/wp-json/wp/v2/posts' => Http::response([
                'id' => 7,
                'link' => 'https://wp.example.test/?p=7',
            ], 201),
        ]);

        [$exit, $payload] = $this->runSmoke($tenant, ['--keep' => true]);
        $this->assertSame(0, $exit);
        $this->assertDatabaseHas('publishing_channels', ['id' => $payload['channel_id']]);
        $this->assertDatabaseHas('articles', ['id' => $payload['article_id']]);
        $this->assertDatabaseHas('article_publications', ['id' => $payload['publication_id']]);

        // 收尾：把留下的记录删掉，避免影响 RefreshDatabase 之外的断言习惯
        PublishingChannel::withoutGlobalScopes()->whereKey($payload['channel_id'])->delete();
        ArticlePublication::withoutGlobalScopes()->whereKey($payload['publication_id'])->delete();
        Article::withoutGlobalScopes()->whereKey($payload['article_id'])->delete();
    }

    public function test_smoke_reports_failure_when_wordpress_rejects_the_post(): void
    {
        [$tenant, $user] = $this->makeTenantUser('smoke-fail');
        $this->makeProject($tenant, $user);

        Http::fake([
            'https://wp.example.test/wp-json/wp/v2/posts' => Http::response(['message' => 'Forbidden'], 403),
        ]);

        [$exit, $payload] = $this->runSmoke($tenant, []);
        $this->assertSame(1, $exit, '外站拒绝时命令必须 FAILURE，不能报 pass');
        $this->assertFalse($payload['pass']);
        $this->assertNotEmpty($payload['last_error']);
    }

    // ── Helpers ──

    /**
     * 跑一次 smoke 命令并取回 [退出码, JSON 载荷]。
     *
     * ⚠️ 必须传显式 BufferedOutput：这条命令在测试内核里跑完后，
     * `Artisan::output()` 拿到的是空串（实测：命令确实执行到底、DB 副作用也对，
     * 但框架侧的 lastOutput 不是这一跑的那份）。用显式缓冲区就与框架内部行为解耦，
     * 也顺手写清了"这段输出来自这一次调用"。
     *
     * @return array{0:int,1:array}
     */
    private function runSmoke(Tenant $tenant, array $extraOptions): array
    {
        $buffer = new BufferedOutput;

        $exit = Artisan::call('geo:distribution-smoke', array_merge([
            '--endpoint' => 'https://wp.example.test',
            '--username' => 'smoke-user',
            '--secret' => str_repeat('x', 24),
            '--tenant' => (string) $tenant->id,
            '--json' => true,
        ], $extraOptions), $buffer);

        $output = $buffer->fetch();
        $json = $this->firstJsonObject($output);
        $this->assertNotNull($json, '命令输出里没有可解析的 JSON：'.$output);

        return [$exit, json_decode($json, true) ?? []];
    }

    /**
     * 抠出输出里的**第一个完整 JSON 对象**。
     *
     * 不能简单"从第一个 { 截到结尾"：这条命令先打 JSON，再打一行清理说明
     * （「已清理临时 channel/article/publication」），尾部文本会让 json_decode 直接返回 null。
     * 所以按花括号配对（跳过字符串内的括号）取到对象闭合为止。
     */
    private function firstJsonObject(string $output): ?string
    {
        $start = strpos($output, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        for ($i = $start, $len = strlen($output); $i < $len; $i++) {
            $ch = $output[$i];
            if ($inString) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === '"') { $inString = false; }
                continue;
            }
            if ($ch === '"') { $inString = true; continue; }
            if ($ch === '{') $depth++;
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($output, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    private function makeTenantUser(string $subdomain): array
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'admin',

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
        ]);

        return [$tenant, $user];
    }

    private function makeProject(Tenant $tenant, User $owner): Project
    {
        TenantContext::set($tenant);
        $project = Project::create([
            'name' => 'Smoke 测试项目',
            'owner_id' => $owner->id,
            'status' => 'active',
        ]);
        TenantContext::forget();

        return $project;
    }
}
