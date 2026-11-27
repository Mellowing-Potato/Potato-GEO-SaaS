<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AI\MockAiProvider;
use App\Services\Contracts\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 上传体积上限与它的错误提示。
 *
 * 2026-08-07：老板传一份 7.8MB 的课程手册被拒，界面只给出一句英文
 * 「The file failed to upload.」—— 看不出是体积问题，也看不出上限是多少。
 * 根因是文件超过当时 php.ini 的 upload_max_filesize=5M，PHP 在接收阶段就拒了，
 * Laravel 拿到的是残缺请求，只能退化成 uploaded 规则那句通用文案。
 *
 * 所以这里守的重点不是「拦没拦住」，而是**拦住之后有没有说人话**：
 * 提示必须是中文、必须写明上限、最好还写明用户这份多大。
 */
class KnowledgeUploadSizeLimitTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase, \Tests\Support\KnowledgeProjectFixtures;

    /** 与 KnowledgeBaseController::MAX_UPLOAD_KB 对齐，改一处这里会红。 */
    private const LIMIT_KB = 10240;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.tenant_domain' => 'geo.example.test']);
        $this->app->instance(AiProvider::class, new MockAiProvider());
    }

    private function signIn(string $subdomain): KnowledgeBase
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);

        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,

            'phone' => \Tests\Support\FixtureIdentity::phone($subdomain),
            // project:create 只给 admin / editor（app/Support/Permissions.php:45）
            'role' => 'admin',
        ]);
        Sanctum::actingAs($user, ['knowledge:read', 'knowledge:write']);

        $this->withServerVariables(['HTTP_HOST' => $subdomain . '.geo.example.test'])
            ->withHeader('X-Tenant', $subdomain);

        return KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'project_id' => self::knowledgeProjectId($tenant->id),
            'name' => 'Upload Limit Base',
            'status' => 'active',
        ]);
    }

    /** B7/§7：给 kb 的租户造一个归属项目（project_id 必填校验的测试前置）。 */
    private function attributionProjectId(KnowledgeBase $kb): int
    {
        if ($kb->project_id) return $kb->project_id;
        $owner = User::factory()->create(['tenant_id' => $kb->tenant_id]);
        $project = new \App\Models\Project();
        $project->forceFill([
            'tenant_id' => $kb->tenant_id,
            'owner_id' => $owner->id,
            'name' => '归属项目',
            'status' => 'active',
        ])->save();

        return $project->id;
    }

    private function upload(KnowledgeBase $kb, UploadedFile $file, array $server = [])
    {
        return $this->withServerVariables($server + [
            'HTTP_HOST' => $kb->tenant->subdomain . '.geo.example.test',
        ])->postJson("/api/v1/knowledge-bases/{$kb->id}/documents", [
            'file' => $file,
            'project_id' => $this->attributionProjectId($kb),
        ], ['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]);
    }

    public function test_oversized_file_is_rejected_in_chinese_naming_the_limit(): void
    {
        $kb = $this->signIn('upl-oversize');

        $response = $this->upload($kb, UploadedFile::fake()->create('big.pdf', self::LIMIT_KB + 1, 'application/pdf'));

        $response->assertStatus(422);
        // 英文原文案不许再出现在客户面前。
        $response->assertDontSee('failed to upload', false);
        $this->assertMatchesRegularExpression(
            '/超过 10 MB 上限/u',
            $response->json('message') . json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_php_level_rejection_still_tells_the_user_the_size(): void
    {
        // 这条复刻的正是 08-07 那次：PHP 在接收阶段就拒了（UPLOAD_ERR_INI_SIZE），
        // 文件对象还在但带着错误码、size 被清零，Laravel 只能报那句通用英文。
        $kb = $this->signIn('upl-inisize');

        $rejected = new UploadedFile(
            UploadedFile::fake()->create('brochure.pdf', 1)->getPathname(),
            'brochure.pdf',
            'application/pdf',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $response = $this->upload($kb, $rejected, ['CONTENT_LENGTH' => 7_758_810]);

        $response->assertStatus(422);
        $body = $response->json('message');
        $this->assertStringContainsString('超过 10 MB 上限', $body);
        // 还要告诉用户「你这份多大」，否则他不知道该压缩到什么程度。
        $this->assertStringContainsString('7.4 MB', $body);
    }

    public function test_the_file_that_used_to_be_rejected_now_passes_the_size_gate(): void
    {
        // 7.8MB（7,758,810 字节 ≈ 7,577 KB）在旧的 5MB 上限下必被拒，现在应当过闸。
        //
        // 这里只能断言「不是被体积拦的」，不能断言 201：fake() 造的是空壳 PDF，
        // 解析不出文字，必然倒在后面的 extractText。两种失败要分清楚，
        // 否则这条测试在闸门回退到 5MB 时依然是绿的 —— 那就白守了。
        $kb = $this->signIn('upl-realcase');

        $response = $this->upload($kb, UploadedFile::fake()->create('brochure.pdf', 7577, 'application/pdf'));

        $body = $response->json('message') . json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('上限', $body, '7.8MB 不该再被体积闸门拦下');
        $this->assertStringNotContainsString('failed to upload', $body);
    }

    public function test_normal_file_still_uploads(): void
    {
        $kb = $this->signIn('upl-normal');

        $response = $this->upload($kb, UploadedFile::fake()->createWithContent(
            'note.txt',
            str_repeat('这是一段用于验证正常上传的中文正文。', 50),
        ));

        $response->assertStatus(202);
        // 异步化后，响应里返回的是「刚登记」的快照（pending），不是解析结果；
        // 真正的 ready 由内联/worker 跑完 job 后落到库里（sync 连接下 dispatch 内联执行）。
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertDatabaseHas('knowledge_documents', [
            'id' => $response->json('data.id'),
            'status' => 'ready',
        ]);
    }
}
