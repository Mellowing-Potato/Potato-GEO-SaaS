<?php

namespace Tests\Feature;

use App\Services\AI\AiProviderServiceImpl;
use App\Services\AI\MockAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B3：AiProvider 支持传图（$options['images']，不改 generate() 签名）。
 *
 * 网关侧行为（路由收窄到 VISION_CAPABLE_PROVIDERS）不在本仓测试范围 ——
 * 这里只验证 geo-saas 发出去的 payload 结构正确：
 *   - 带图时 user content 是 parts 数组（先 image_url 后 text）
 *   - 不带图时 content 仍是纯字符串（存量调用方零回归）
 */
class AiProviderImagesTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    use \Tests\Support\UsesWorkspaceModels;
    private function fakeGateway(): void
    {
        $this->useWorkspaceModels();

        Http::fake([
            'https://models.example.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => 'transcribed'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ]),
        ]);
    }

    public function test_images_build_multimodal_parts_payload(): void
    {
        $this->fakeGateway();
        $dataUrl = 'data:image/jpeg;base64,' . base64_encode('fake-jpeg-bytes');

        $result = app(AiProviderServiceImpl::class)->generate('转录这一页', '你是转录工具', [
            'provider' => 'gateway',
            'model' => 'vision',
            'images' => [$dataUrl],
            'temperature' => 0,
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('transcribed', $result->content);

        Http::assertSent(function ($request) use ($dataUrl) {
            $messages = $request['messages'];

            // system + user 两条；user content 必须是 parts 数组
            if (count($messages) !== 2 || $messages[0]['role'] !== 'system') {
                return false;
            }
            $user = $messages[1];
            if ($user['role'] !== 'user' || ! is_array($user['content'])) {
                return false;
            }

            // 顺序：先 image_url 后 text（执行书 B3 的构造顺序）
            return $user['content'] === [
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ['type' => 'text', 'text' => '转录这一页'],
            ];
        });
    }

    public function test_without_images_content_stays_plain_string(): void
    {
        $this->fakeGateway();

        $result = app(AiProviderServiceImpl::class)->generate('普通调用', null, [
            'provider' => 'gateway',
            'model' => 'text']);

        $this->assertTrue($result->success);

        // 存量调用方（技能执行/文章生成等全走这条路）不能被 B3 改坏。
        Http::assertSent(function ($request) {
            $messages = $request['messages'];

            return count($messages) === 1
                && $messages[0]['role'] === 'user'
                && $messages[0]['content'] === '普通调用';
        });
    }

    public function test_mock_provider_recognizes_images_option(): void
    {
        $result = (new MockAiProvider())->generate('转录这一页', null, [
            'model' => 'vision',
            'images' => ['data:image/jpeg;base64,AAAA'],
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('stop', $result->finishReason);
        // 固定转录文本里带着双向校验要用的素材（¥2,200 / 电话号码 / [图标]）。
        $this->assertStringContainsString('¥2,200', (string) $result->content);
        $this->assertStringContainsString('[图标]', (string) $result->content);
    }
}
