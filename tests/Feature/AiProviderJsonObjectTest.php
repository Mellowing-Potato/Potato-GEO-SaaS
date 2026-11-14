<?php

namespace Tests\Feature;

use App\Services\AI\AiProviderServiceImpl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 强制 JSON 语法（response_format）发出去的 payload 长什么样。
 *
 * 为什么只用 json_object 不用 json_schema —— 2026-09-06 对生产网关实测三种取值：
 *   不带 response_format   → 200
 *   {"type":"json_object"} → 200
 *   {"type":"json_schema"} → 502 "This response_format type is unavailable now"
 * 所以 json_schema 那条路默认关（config geo.native_json_schema），否则每次结构化调用
 * 都要先白撞一个 502 再静默降级。
 */
class AiProviderJsonObjectTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    use \Tests\Support\UsesWorkspaceModels;
    private function fakeGateway(string $content = '{"ok":"yes"}'): void
    {
        $this->useWorkspaceModels(provider: 'openai');

        Http::fake([
            'https://models.example.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], 200)]);
    }

    public function test_json_object_option_sets_response_format(): void
    {
        $this->fakeGateway();

        $result = app(AiProviderServiceImpl::class)->generate('输出 JSON', null, [
            'provider' => 'gateway',
            'model' => 'text',
            'json_object' => true,
        ]);

        $this->assertTrue($result->success);
        Http::assertSent(fn ($request) => ($request['response_format'] ?? null) === ['type' => 'json_object']);
    }

    public function test_plain_calls_send_no_response_format(): void
    {
        $this->fakeGateway('普通正文');

        app(AiProviderServiceImpl::class)->generate('写一段话', null, [
            'provider' => 'gateway',
            'model' => 'text']);

        // 出 Markdown 正文的调用方一个都不能被带进 JSON 模式。
        Http::assertSent(fn ($request) => ! array_key_exists('response_format', $request->data()));
    }

    public function test_structured_output_skips_unsupported_json_schema_and_uses_json_object(): void
    {
        $this->fakeGateway();

        $result = app(AiProviderServiceImpl::class)->generateStructured(
            '给我一个对象',
            json_encode(['type' => 'object', 'properties' => ['ok' => ['type' => 'string']]]),
        );

        $this->assertTrue($result->success);
        $this->assertSame(['ok' => 'yes'], $result->structuredOutput);

        // 只发一次，而且不是 json_schema —— 没有「先撞 502 再降级」那一趟。
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ($request['response_format'] ?? null) === ['type' => 'json_object']);
    }

    public function test_native_json_schema_is_reachable_when_gateway_supports_it(): void
    {
        $this->fakeGateway();
        config(['geo.native_json_schema' => true]);

        app(AiProviderServiceImpl::class)->generateStructured(
            '给我一个对象',
            json_encode(['type' => 'object', 'properties' => ['ok' => ['type' => 'string']]]),
        );

        // 开关是给网关将来支持时用的，代码路径必须还活着。
        Http::assertSent(fn ($request) => ($request['response_format']['type'] ?? null) === 'json_schema');
    }
}
