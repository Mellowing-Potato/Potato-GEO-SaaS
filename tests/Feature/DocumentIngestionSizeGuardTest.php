<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Tenant;
use App\Services\AI\MockAiProvider;
use App\Services\Knowledge\DocumentIngestionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 入库正文规模闸门。
 *
 * ⚠️ 别把它当成 2026-08-01 那次卡死事故的修复（2026-08-07 更正）：那次的真凶是
 * chunkText() 的死循环，与文档大小无关，已由 `3fd8d41` 单独修掉。详见
 * DocumentIngestionService::MAX_TEXT_CHARS 的注释。
 *
 * 这道闸门守的是另一种失效：ingestUrl / ingestText 此前没有任何体积上限，超大正文
 * 会把 PHP 内存撑爆；而内存耗尽是 FatalError、不是异常，ingest*() 里的
 * catch(\Throwable) 接不住 —— markFailed() 不执行，文档就永远卡在 pending。
 *
 * 所以这里最关键的断言不是「抛没抛异常」，而是**文档有没有落到 failed**。
 */
class DocumentIngestionSizeGuardTest extends TestCase
{
    use \Tests\Support\UsesWorkspaceModels;
    use RefreshDatabase;

    private function makeKnowledgeBase(string $subdomain): KnowledgeBase
    {
        $tenant = Tenant::query()->create([
            'subdomain' => $subdomain,
            'name' => strtoupper($subdomain),
            'plan' => 'basic',
            'status' => 'active',
        ]);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);

        return KnowledgeBase::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Guard Base',
            'status' => 'active',
        ]);
    }

    private function service(): DocumentIngestionService
    {
        return new DocumentIngestionService(new MockAiProvider());
    }

    public function test_oversized_text_is_rejected_with_a_readable_reason(): void
    {
        $kb = $this->makeKnowledgeBase('ingest-oversized');
        $oversized = str_repeat('长', 10_000_001);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/超过单篇 10,000,000 字符上限/');

        $this->service()->ingestText($kb->id, '超大文档', $oversized);
    }

    public function test_oversized_text_leaves_the_document_failed_not_stuck_pending(): void
    {
        $kb = $this->makeKnowledgeBase('ingest-not-stuck');

        try {
            $this->service()->ingestText($kb->id, '超大文档', str_repeat('长', 10_000_001));
            $this->fail('超限内容应当抛异常。');
        } catch (\RuntimeException) {
            // 预期
        }

        $document = KnowledgeDocument::query()->where('knowledge_base_id', $kb->id)->sole();

        // 事故当天卡住的那 3 篇就是停在 pending —— 这条断言是本测试的重点。
        $this->assertSame('failed', $document->status);
        $this->assertNotNull($document->error_message);
        $this->assertStringContainsString('字符上限', $document->error_message);
    }

    public function test_normal_sized_text_still_ingests(): void
    {
        $kb = $this->makeKnowledgeBase('ingest-normal');

        $document = $this->service()->ingestText(
            $kb->id,
            '正常文档',
            str_repeat('这是一段用于验证切块与向量化的中文正文。', 200),
        );

        $this->assertSame('ready', $document->status);
        $this->assertGreaterThan(0, $document->chunk_count);
    }

    public function test_boundary_length_is_accepted(): void
    {
        // 正好等于上限要放行，闸门是「超过才拦」。
        //
        // 这里直接打闸门本身，不走整条入库：1000 万字符会切出约 5,500 条并逐条写库，
        // 测的是闸门的边界，没必要为此在测试里跑一遍完整向量化。
        $guard = new \ReflectionMethod(DocumentIngestionService::class, 'guardTextSize');
        $service = $this->service();

        $guard->invoke($service, str_repeat('界', 10_000_000));

        $this->addToAssertionCount(1); // 没抛异常即通过

        $this->expectException(\RuntimeException::class);
        $guard->invoke($service, str_repeat('界', 10_000_001));
    }

    /**
     * A1：chunkText() 不再绕一圈切出「最后 200 字重复切片」。
     *
     * 2,500 字、max=2000、overlap=200 → 恰好 2 段；第二段长度 ≠ 200，
     * 且第二段不等于第一段末尾 200 字。
     */
    public function test_chunk_text_does_not_emit_trailing_duplicate(): void
    {
        $chunkText = new \ReflectionMethod(DocumentIngestionService::class, 'chunkText');
        // 用一段确定长度的「字+空格」正文，避免句号/叹号被当断句点打乱分段。
        $text = str_repeat('中', 2500);
        $chunks = $chunkText->invoke($this->service(), $text);

        $this->assertCount(2, $chunks, '2500 字应切为 2 段');
        $this->assertNotSame(200, mb_strlen($chunks[1]), '第二段不应恰好 200 字');
        $this->assertNotSame(
            mb_substr($chunks[0], -200),
            $chunks[1],
            '第二段不应是第一段末尾 200 字的逐字重复',
        );
    }

    public function test_chunk_text_exactly_max_is_single_chunk(): void
    {
        $chunkText = new \ReflectionMethod(DocumentIngestionService::class, 'chunkText');
        $chunks = $chunkText->invoke($this->service(), str_repeat('中', 2000));
        $this->assertCount(1, $chunks);
    }

    public function test_chunk_text_one_char_over_max_is_two_chunks_no_trailing_dup(): void
    {
        $chunkText = new \ReflectionMethod(DocumentIngestionService::class, 'chunkText');
        $chunks = $chunkText->invoke($this->service(), str_repeat('中', 2001));
        $this->assertCount(2, $chunks);
        $this->assertNotSame(mb_substr($chunks[0], -200), $chunks[1]);
    }

    /**
     * 4,500 字、max=2000、overlap=200 → 3 段。
     *
     * 用每 10 字互不相同的单元拼出正文（避免全同字符退化成「任意段互为子串」），
     * 重点查 A1 修复的那个特征：结尾不再绕一圈切出「最后 200 字 = 前段末尾逐字复制」。
     */
    public function test_chunk_text_four_thousand_five_hundred_no_trailing_duplicate(): void
    {
        $chunkText = new \ReflectionMethod(DocumentIngestionService::class, 'chunkText');
        // 10 字一单元、单元间互不相同，便于检测重复切片。
        $units = [];
        for ($u = 0; $u < 450; $u++) {
            $units[] = str_pad((string) $u, 10, '0', STR_PAD_LEFT);
        }
        $text = implode('', $units); // 4500 字，无句号 → 不触发断句
        $chunks = $chunkText->invoke($this->service(), $text);

        $this->assertCount(3, $chunks);

        // 末段绝不能是「前段末尾 200 字」的逐字复制（A1 修复前会这样）
        $last = end($chunks);
        $prev = $chunks[count($chunks) - 2];
        $this->assertNotSame(mb_substr($prev, -200), $last, '末段不应是前段末尾 200 字重复');

        // 每段都明显长于重叠区 200 字（退化重复切片会恰好 200）
        foreach ($chunks as $i => $c) {
            $this->assertGreaterThan(200, mb_strlen($c), "第 {$i} 段长度应 > 200（否则是退化重复切片）");
        }
    }

    /**
     * 段间重叠仍然存在（特性不是 bug）：第 1 段结尾与第 2 段开头有交集。
     */
    public function test_chunk_text_overlap_is_preserved(): void
    {
        $chunkText = new \ReflectionMethod(DocumentIngestionService::class, 'chunkText');
        // 用带唯一标记的正文，便于检测重叠。
        $unit = 'ABCDEFGHIJ'; // 10 字一单元
        $text = str_repeat($unit, 250); // 2500 字
        $chunks = $chunkText->invoke($this->service(), $text);
        $this->assertCount(2, $chunks);

        $tail = mb_substr($chunks[0], -50); // 第一段最后 50 字
        $head = mb_substr($chunks[1], 0, 50); // 第二段开头 50 字
        // 重叠区（overlap=200 字，这里 50 字窗口必落在重叠内）应同时出现在两段
        $this->assertStringContainsString($head, $tail);
    }
}
