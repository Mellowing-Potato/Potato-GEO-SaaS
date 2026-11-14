<?php

namespace Tests\Feature;

use App\Support\Logging\RedactSensitiveContext;
use App\Support\Logging\RedactSensitiveContextTap;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 日志脱敏（计划 §9.7）。
 *
 * 这组用例要证明的不是"某个函数能替换字符串"（`OperationalTextRedactor` 早就有测试口径），
 * 而是**默认路径上不会再写出凭据**：
 *   1. 生产通道（`single` / `daily`）真的挂上了脱敏处理器 —— 掐掉配置就红；
 *   2. 真往磁盘写一条，文件里搜不到那个密钥 —— 走完整链路（config → tap → Monolog → 文件），
 *      不是只调一下处理器；
 *   3. 规则本身：键名命中、值里的特征命中、账本 ID 换指纹、以及**不许误伤**
 *      （`token_usage` / `max_tokens` 这类统计键必须原样保留，否则脱敏上线第一天就会被关掉）。
 */
class LogRedactionTest extends TestCase
{
    public function test_production_file_channels_are_wired_for_redaction(): void
    {
        foreach (['single', 'daily'] as $channel) {
            $this->assertContains(
                RedactSensitiveContextTap::class,
                config("logging.channels.{$channel}.tap") ?? [],
                "{$channel} 通道没挂脱敏处理器 —— 日志里的凭据会被原样写进文件。"
            );
        }
    }

    public function test_a_real_write_to_disk_never_contains_the_secret(): void
    {
        $path = storage_path('logs/redaction-probe-'.uniqid().'.log');

        config([
            'logging.channels.redaction_probe' => [
                'driver' => 'single',
                'path' => $path,
                'level' => 'debug',
                // 与生产通道同一套挂法
                'tap' => [RedactSensitiveContextTap::class],
            ],
        ]);

        try {
            Log::channel('redaction_probe')->error('上游拒绝了这个请求', [
                'provider' => 'gateway',
                'api_key' => 'sk-test-718f53a8f6f2dd5a7a44b',
                'authorization' => 'Bearer test-fake-f39dac',
                'transaction_id' => 'qh_txn_20260921_abcdef',
                'phone' => '13800000006',
                'email' => 'customer@example.com',
                'token_usage' => 1234,
                'max_tokens' => 8192,
                'body' => '{"error":"invalid key sk-test-718f53a8f6f2dd5a7a44b for customer@example.com"}',
            ]);

            $written = (string) file_get_contents($path);

            foreach (['sk-test-718f53a8f6f2dd5a7a44b', 'test-fake-f39dac', 'qh_txn_20260921_abcdef', '13800000006', 'customer@example.com'] as $secret) {
                $this->assertStringNotContainsString($secret, $written, "日志文件里出现了明文：{$secret}");
            }

            // 该留的还在：排障要看的统计数字不能被误伤
            $this->assertStringContainsString('token_usage', $written);
            $this->assertStringContainsString('1234', $written);
            $this->assertStringContainsString('max_tokens', $written);
            $this->assertStringContainsString('8192', $written);
            $this->assertStringContainsString('[redacted_secret]', $written);
            $this->assertStringContainsString('sha256:', $written);
        } finally {
            @unlink($path);
        }
    }

    public function test_sensitive_keys_are_masked_by_exact_key_name(): void
    {
        $record = $this->process([
            'authorization' => 'Bearer test-fake-e',
            'X-Api-Key' => 'sk-test-3b41ad713ab78e',
            'user_password' => 'not-in-the-list',
            'password' => 'hunter2',
            'access_token' => 'def',
            'nested' => [
                'client_secret' => 'shh',
                'phone' => '13800000023',
                'email' => 'fixture-user-1@example.com',
            ],
        ]);

        $this->assertSame(RedactSensitiveContext::REDACTED, $record['authorization']);
        $this->assertSame('[redacted_secret]', $record['X-Api-Key'], '键名大小写与连字符都要归一');
        $this->assertSame('not-in-the-list', $record['user_password'], '只做精确匹配：不在清单里的键不动');
        $this->assertSame(RedactSensitiveContext::REDACTED, $record['password']);
        $this->assertSame('[redacted_token]', $record['access_token']);
        $this->assertSame(RedactSensitiveContext::REDACTED, $record['nested']['client_secret'], '嵌套数组要递归');
        $this->assertSame('[redacted_phone]', $record['nested']['phone']);
        $this->assertSame('[redacted_email]', $record['nested']['email']);
    }

    /** 账本 ID 换指纹：日志仍能按同一条记录对上，但拿不到原值 */
    public function test_ledger_ids_become_fingerprints_not_replacement_markers(): void
    {
        $record = $this->process([
            'transaction_id' => 'qh_txn_20260921_abcdef',
            'wallet_id' => 'wallet_8888',
            'other_transaction_id' => 'qh_txn_20260921_abcdef',
            'idempotency_key' => '',
            'wallet_internal_id' => 12345,
        ]);

        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{12}$/', $record['transaction_id']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{12}$/', $record['wallet_id']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{12}$/', $record['wallet_internal_id'] ?? '');
        $this->assertSame(RedactSensitiveContext::REDACTED, $record['idempotency_key'], '空值的敏感键给替换标记，不给指纹');

        // 键名只做精确匹配：'other_transaction_id' 不在清单里 → 它按**值特征**被扫，
        // 落成 [redacted_id]（与指纹不同）。钉住这个差别，免得有人以为"任何带 transaction_id 的键都管"。
        $this->assertSame('[redacted_id]', $record['other_transaction_id']);

        // 指纹是确定性的：同一个值给同一个指纹，运维仍能按指纹把一次调用串起来
        $again = $this->process(['transaction_id' => 'qh_txn_20260921_abcdef']);
        $this->assertSame($again['transaction_id'], $record['transaction_id']);
    }

    public function test_secrets_hidden_inside_free_text_are_redacted(): void
    {
        $record = $this->processRecord([
            'body' => 'upstream said: api_key=sk-test-32f4cf588c7 for 13800000007 (ops@example.com)',
            'error' => 'Bearer test-fixturefixturefixturefixturefix.test-fi.test-fixt',
        ], message: '调用网关失败 authorization: Bearer test-fixturefixturef.test.tes');

        $this->assertStringNotContainsString('sk-test-32f4cf588c7', $record['context']['body']);
        $this->assertStringNotContainsString('13800000007', $record['context']['body']);
        $this->assertStringNotContainsString('ops@example.com', $record['context']['body']);
        $this->assertStringContainsString('[redacted_phone]', $record['context']['body']);
        $this->assertStringContainsString('[redacted_email]', $record['context']['body']);

        $this->assertStringNotContainsString('test-fixturefixturefixturefixturefix', $record['context']['error']);
        $this->assertStringNotContainsString('test-fixturefixturef', $record['message']);
    }

    public function test统计类键与长文本不许被误伤(): void
    {
        $long = str_repeat('这是一段很长的报告正文。', 60); // 超过 redact() 默认的 240 字截断线

        $record = $this->process([
            'token_usage' => 4096,
            'max_tokens' => 12000,
            'input_tokens' => 100,
            'total_tokens' => 4196,
            'tokens' => ['input' => 1],
            'excerpt' => $long,
        ]);

        $this->assertSame(4096, $record['token_usage']);
        $this->assertSame(12000, $record['max_tokens']);
        $this->assertSame(100, $record['input_tokens']);
        $this->assertSame(4196, $record['total_tokens']);
        $this->assertSame(['input' => 1], $record['tokens']);
        $this->assertSame($long, $record['excerpt'], '日志正文不许被 240 字截断（那是运营字段的口径）');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function process(array $context, string $message = '测试消息'): array
    {
        return $this->processRecord($context, $message)['context'];
    }

    /** @return array<string, mixed> */
    private function processRecord(array $context, string $message = '测试消息'): array
    {
        return (new RedactSensitiveContext)([
            'message' => $message,
            'context' => $context,
            'level' => 400,
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable,
            'extra' => [],
        ]);
    }
}
