<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\KnowledgeBaseController;
use App\Models\KnowledgeBase;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Tests\TestCase;

/**
 * 超大上传必须回一个「明确失败」的响应。
 *
 * 2026-08-11 的故障：老板传 19MB 文件，界面显示「文档已上传。」，文件却没入库。
 * 三个缺陷叠在一起：
 *   1) 镜像里 display_errors=STDOUT，PHP 在 Laravel 启动前就把警告写进响应体，
 *      响应头随之以默认 200 发出，Laravel 之后再也改不动状态码；
 *   2) uploadSizeError() 里判断单文件超限的那个分支因为 instanceof 用错类型，
 *      从落地起就没生效过；
 *   3) 前端 api() 把解析不了的响应体静默当成 {}，于是 200 + 非 JSON = 成功。
 *
 * 第 1 条是 php.ini 层面的，PHPUnit 跑不到（测试不经过真实 SAPI），
 * 由 Dockerfile 保证 + 部署后实测；这里锁住第 2、3 条对应的服务端行为。
 */
class KnowledgeUploadOversizeResponseTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase, \Tests\Support\KnowledgeProjectFixtures;

    private const LIMIT_MB = 10;

    /** @return array{0: Tenant, 1: User, 2: KnowledgeBase} */
    private function signIn(): array
    {
        $tenant = Tenant::create([
            'name' => '测试租户',
            'subdomain' => 'oversize-'.uniqid(),
            'status' => 'active',
        ]);

        $this->configureWorkspaceModelSlots($tenant);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => '管理员',
            'email' => uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role' => 'admin',
        ]);

        $kb = KnowledgeBase::create([
            'tenant_id' => $tenant->id,
            'project_id' => self::knowledgeProjectId($tenant->id),
            'name' => '知识库',
            'owner_id' => $user->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        return [$tenant, $user, $kb];
    }

    private function upload(Tenant $tenant, KnowledgeBase $kb, UploadedFile $file)
    {
        // 必须用 post() 走 multipart，不能用 postJson() —— 后者把 UploadedFile
        // 当成普通值 JSON 序列化掉，$request->file() 永远拿不到东西，
        // 于是不管控制器怎么写用例都会「通过」到错误的分支上。
        // B7/§7：project_id 必填，归属项目建在 kb 同租户名下
        //（owner 用该租户已有的用户 —— kb 行上的 owner_id 不可靠）。
        $owner = \App\Models\User::query()->where('tenant_id', $kb->tenant_id)->first();
        $project = new \App\Models\Project();
        $project->forceFill([
            'tenant_id' => $kb->tenant_id,
            'owner_id' => $owner?->id ?? \App\Models\User::create([
                'tenant_id' => $kb->tenant_id,
                'name' => '归属人',
                'email' => uniqid() . '@example.com',
                'password' => bcrypt('secret'),
                'role' => 'admin',
            ])->id,
            'name' => '归属项目',
            'status' => 'active',
        ])->save();

        return $this->withHeader('X-Tenant', $tenant->subdomain)
            ->post(
                "/api/v1/knowledge-bases/{$kb->id}/documents",
                ['file' => $file, 'project_id' => $project->id],
                ['Accept' => 'application/json', 'X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()],
            );
    }

    public function test_file_rejected_by_php_reports_its_size_in_chinese(): void
    {
        [$tenant, , $kb] = $this->signIn();

        // PHP 因 upload_max_filesize 拒收时，文件对象还在，但带着 UPLOAD_ERR_INI_SIZE
        // 且 size 被清零。第四个参数就是那个错误码。
        $file = new UploadedFile(
            $this->fakePdfPath(),
            'huge.pdf',
            'application/pdf',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        $response = $this->upload($tenant, $kb, $file);

        $response->assertStatus(422);

        $message = $response->json('message');

        // 这条断言是本用例的核心：必须是 uploadSizeError() 给的提示，而不是
        // validate() 的笼统兜底「文件上传失败，可能超过…或传输中断」。
        // instanceof 用错类型（Symfony 的 UploadedFile vs Illuminate 的）会让它退化。
        $this->assertStringContainsString('超过 '.self::LIMIT_MB.' MB 上限', $message);
        $this->assertStringNotContainsString('传输中断', $message);
        $this->assertStringNotContainsString('failed to upload', $message);
    }

    public function test_message_reports_the_actual_size_when_content_length_is_known(): void
    {
        [$tenant, , $kb] = $this->signIn();

        $file = new UploadedFile(
            $this->fakePdfPath(),
            'huge.pdf',
            'application/pdf',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        // 真实 HTTP 请求一定带 CONTENT_LENGTH；带上它就该报出具体多大。
        //
        // 这个数字必须低于**跑测试这台机器**的 post_max_size（开发机常见 8M），
        // 否则 ValidatePostSize 会先抛 413，根本走不到控制器 —— 验的就不是这条路了。
        // 生产的真实场景是 post_max_size=20M、文件 19MB，同样落在控制器这一支。
        $response = $this->withServerVariables(['CONTENT_LENGTH' => 6000000])
            ->upload($tenant, $kb, $file);

        $response->assertStatus(422);
        $this->assertStringContainsString('文件约 5.7 MB', $response->json('message'));
    }

    public function test_post_too_large_is_rendered_as_chinese_413(): void
    {
        // 整个表单超过 post_max_size 时 Laravel 抛这个异常，默认回英文。
        // 这是超过 20M 时的兜底路径。
        $this->app['router']->get('/api/v1/__oversize-probe', function (): void {
            throw new PostTooLargeException();
        });

        $response = $this->getJson('/api/v1/__oversize-probe');

        $response->assertStatus(413);
        $this->assertStringContainsString('过大', $response->json('message'));
        $this->assertStringNotContainsString('POST data', $response->json('message'));
    }

    public function test_normal_sized_file_is_not_blocked_by_the_guard(): void
    {
        // 反向验证：闸门不能把正常文件也拦下来。
        [$tenant, , $kb] = $this->signIn();

        $file = new UploadedFile($this->fakePdfPath(), 'ok.pdf', 'application/pdf', null, true);

        $response = $this->upload($tenant, $kb, $file);

        // 这份假 PDF 解析不出文字，会被入库环节拒；但绝不能是「超过上限」那条。
        $message = (string) $response->json('message');
        $this->assertStringNotContainsString('超过', $message);
        $this->assertStringNotContainsString('上限', $message);
    }

    /**
     * 这条是上面几条的补丁，别删。
     *
     * 上面的用例走 Laravel 测试客户端，而它往 files bag 里塞的本来就是
     * Illuminate\Http\UploadedFile —— 于是 `$request->files->get()` 和
     * `$request->file()` 在测试里表现一样，**改坏了也照样绿**（实测过）。
     * 真实 HTTP 请求里 bag 装的是 Symfony 的实例，两者才分道扬镳。
     *
     * 所以这里手工造一个「Symfony 实例在 bag 里」的请求，模拟真实 SAPI 的形状。
     */
    public function test_guard_survives_a_symfony_uploaded_file_in_the_bag(): void
    {
        $symfonyFile = new SymfonyUploadedFile(
            $this->fakePdfPath(),
            'huge.pdf',
            'application/pdf',
            UPLOAD_ERR_INI_SIZE,
            true,
        );

        // 前提自检：真实请求里就是这个形状 —— Symfony 的实例不是 Illuminate 的实例。
        // 正因如此，`$file instanceof Illuminate\Http\UploadedFile` 会恒为 false。
        $this->assertNotInstanceOf(UploadedFile::class, $symfonyFile);

        $request = Request::create(
            '/api/v1/knowledge-bases/1/documents',
            'POST',
            [],
            [],
            ['file' => $symfonyFile],
            ['CONTENT_LENGTH' => 19000202],
        );

        $method = new \ReflectionMethod(KnowledgeBaseController::class, 'uploadSizeError');
        $message = $method->invoke(app(KnowledgeBaseController::class), $request);

        $this->assertNotNull($message, '闸门没认出这个超限文件——多半是 instanceof 又比错了类型。');
        $this->assertStringContainsString('文件约 18.1 MB', $message);
    }

    private function fakePdfPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kbtest').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%oversize-test\n");

        return $path;
    }
}
