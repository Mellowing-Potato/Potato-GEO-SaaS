<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Publishing\ArticleRiskScanner;
use App\Models\PlatformSensitiveWord;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlatformSensitiveWordBaselineSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * 平台级敏感词基线库 · 灌词 seeder 测试（上线批）。
 *
 * 这个文件的核心任务是**守住三条不变量**，每条都对应一个「灌完看库以为成了、
 * 实际闸门是开的」的静默失效方式：
 *
 *   1. 必须进位规则缓存版本号（`insert()` 批量写不触发模型事件 → 闸门吃空词表）
 *   2. `source` 必须落进 `note`（`$fillable` 里没有 source → 整行静默丢弃）
 *   3. 必须幂等（复跑不产生重复行、note 不叠加）
 *
 * ⚠️ 第 1 条的变异验收已实测：把 seeder 改成 `insert()` 批量写，
 *    `test_*cache_version_bumped` 变红（Failed asserting that 1 is greater than 1），
 *    改回 `updateOrCreate()` 变绿。不做这一步，这条断言就是恒绿的摆设。
 */
class PlatformSensitiveWordBaselineSeederTest extends TestCase
{
    use RefreshDatabase;

    private const BASELINE_RELATIVE_PATH = 'database/data/platform_sensitive_words_baseline.php';

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    /** @return array<int, array<string, mixed>> */
    private function baselineRows(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = require base_path(self::BASELINE_RELATIVE_PATH);

        return $rows;
    }

    private function runSeeder(): void
    {
        $this->seed(PlatformSensitiveWordBaselineSeeder::class);
    }

    #[Test]
    public function seeder_writes_every_baseline_word_with_blocked_severity(): void
    {
        $this->runSeeder();

        $rows = $this->baselineRows();

        $this->assertSame(54, PlatformSensitiveWord::query()->count(), '灌词条数应等于基线数据文件的词条数');
        $this->assertSame(
            count($rows),
            PlatformSensitiveWord::query()->count(),
            '库内条数必须与数据文件行数一致——差一条就说明有一行被静默丢掉了',
        );

        $this->assertSame(0, PlatformSensitiveWord::query()->where('severity', '!=', 'blocked')->count());
        $this->assertSame(0, PlatformSensitiveWord::query()->where('is_enabled', false)->count());
        $this->assertSame(
            54,
            PlatformSensitiveWord::query()->where('severity', 'blocked')->where('is_enabled', true)->count(),
        );
    }

    #[Test]
    public function seeded_words_match_the_data_file_exactly(): void
    {
        $this->runSeeder();

        $expected = array_values(array_map(
            static fn (array $row): string => trim((string) $row['word']),
            $this->baselineRows(),
        ));
        sort($expected);

        $actual = PlatformSensitiveWord::query()->pluck('word')->map(static fn ($w): string => (string) $w)->all();
        sort($actual);

        $this->assertSame($expected, $actual, '库内词集合必须与数据文件逐词相同');
    }

    /**
     * 专治坑 1：不进位版本号 = 正在跑的实例继续吃空规则集 = 一篇都拦不住。
     */
    #[Test]
    public function seeder_bumps_the_rule_cache_version(): void
    {
        $before = PlatformSensitiveWord::ruleCacheVersion();

        $this->runSeeder();

        $after = PlatformSensitiveWord::ruleCacheVersion();

        $this->assertGreaterThan(
            $before,
            $after,
            '灌词后规则缓存版本号必须严格变大：不变则 ArticleRiskScanner 的缓存 key 不变，'
            .'最长一小时内闸门仍按旧（空）词表放行——库里有词但一篇都拦不住。',
        );
    }

    /**
     * 专治坑 2：`source` 不在 $fillable 里，整行 create() 会被静默丢弃。
     */
    #[Test]
    public function every_seeded_word_keeps_its_source_in_the_note(): void
    {
        $this->runSeeder();

        $rows = $this->baselineRows();

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $word = trim((string) $row['word']);
            $source = trim((string) $row['source']);

            $model = PlatformSensitiveWord::query()->where('word', $word)->first();

            $this->assertNotNull($model, "基线词「{$word}」未落库");
            $this->assertStringContainsString(
                $source,
                (string) $model->note,
                "「{$word}」的 note 里没有词源：source 不是数据库列，不显式并进 note 就会被 Laravel 静默丢弃，"
                .'合规上「这条词哪来的」必须答得出来。',
            );
        }
    }

    #[Test]
    public function note_also_keeps_the_original_reason_text(): void
    {
        $this->runSeeder();

        $first = $this->baselineRows()[0];
        $model = PlatformSensitiveWord::query()->where('word', trim((string) $first['word']))->first();

        $this->assertStringContainsString(trim((string) $first['note']), (string) $model->note);
    }

    #[Test]
    public function running_it_twice_stays_idempotent(): void
    {
        $this->runSeeder();
        $firstCount = PlatformSensitiveWord::query()->count();
        $firstNote = (string) PlatformSensitiveWord::query()->value('note');

        $this->runSeeder();
        $secondCount = PlatformSensitiveWord::query()->count();
        $secondNote = (string) PlatformSensitiveWord::query()->where('word', trim((string) $this->baselineRows()[0]['word']))->value('note');

        $this->assertSame(54, $firstCount, '第一次灌词后的行数');
        $this->assertSame(54, $secondCount, '第二次灌词后的行数必须与第一次相同（幂等）');
        $this->assertSame($firstCount, $secondCount);

        // 幂等拼接：复跑不能把词源后缀叠加成「…｜词源：A｜词源：A」
        $this->assertSame(
            1,
            mb_substr_count($secondNote, PlatformSensitiveWordBaselineSeeder::SOURCE_NOTE_GLUE),
            '复跑不得重复叠加词源后缀',
        );
    }

    /**
     * 不变量 3 的硬守卫：被 use WithoutModelEvents 的 seeder 包着跑时，
     * 写入不会触发 saved 事件，不变量 1 会在静默中失效——所以必须当场炸掉。
     */
    #[Test]
    public function it_refuses_to_run_with_model_events_disabled(): void
    {
        $dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();

        try {
            $this->runSeeder();

            $this->fail('模型事件被摘除时灌词必须抛异常，否则会静默产出「库里有词但闸门不生效」的状态。');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('模型事件分发器', $e->getMessage());
        } finally {
            Model::setEventDispatcher($dispatcher);
        }
    }

    #[Test]
    public function it_is_not_registered_in_the_database_seeder(): void
    {
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString(
            'PlatformSensitiveWordBaselineSeeder',
            $source,
            'DatabaseSeeder use 了 WithoutModelEvents，本 seeder 一旦被挂进它的 call() 列表，'
            .'模型事件会被整体禁用，规则缓存版本号不会进位。必须独立执行。',
        );
    }

    #[Test]
    public function it_does_not_use_query_builder_bulk_insert(): void
    {
        $source = file_get_contents(base_path('database/seeders/PlatformSensitiveWordBaselineSeeder.php'));

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression(
            '/->\s*insert\s*\(|::\s*insert\s*\(|upsert\s*\(/',
            $source,
            '查询构造器的 insert()/upsert() 不触发模型事件，会让规则缓存版本号不进位。'
            .'必须逐条走 Eloquent 的 updateOrCreate()。',
        );
    }

    /**
     * 端到端：灌完词之后闸门真的拦得住。
     *
     * 版本号进位只是手段，「含词稿子扫出 blocked」才是目的。
     * 这条同时覆盖生产验收第 1 条的机制部分（渠道侧零请求由集成测试兜）。
     */
    #[Test]
    public function the_gate_actually_blocks_a_baseline_word_after_seeding(): void
    {
        $this->runSeeder();

        $tenant = Tenant::query()->create([
            'subdomain' => 'seed-'.substr(md5((string) microtime(true)), 0, 8),
            'name' => 'SEED E2E',
            'plan' => 'basic',
            'status' => 'active',
        ]);

        TenantContext::set($tenant);

        $word = trim((string) $this->baselineRows()[0]['word']);

        $result = app(ArticleRiskScanner::class)->scan([
            'title' => '一篇正常的技术稿',
            'excerpt' => '',
            'content' => '本文讨论内容安全治理，其中涉及'.$word.'这一类违禁品的法律界定。',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('blocked', $result['status'], '灌词后扫描器必须判定 blocked');
        $this->assertSame($word, $result['matches'][0]['word'] ?? null);
        $this->assertSame('platform', $result['matches'][0]['origin'] ?? null);
    }

    #[Test]
    public function the_gate_stays_clean_for_content_without_baseline_words(): void
    {
        $this->runSeeder();

        $tenant = Tenant::query()->create([
            'subdomain' => 'seed-'.substr(md5((string) microtime(true)), 0, 8),
            'name' => 'SEED E2E CLEAN',
            'plan' => 'basic',
            'status' => 'active',
        ]);

        TenantContext::set($tenant);

        $result = app(ArticleRiskScanner::class)->scan([
            'title' => '一篇正常的技术稿',
            'excerpt' => '',
            'content' => '本文讨论内容安全治理的一般性流程与最佳实践，不涉及任何违禁品。',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame('clean', $result['status'], '正常稿子不得被基线词误伤');
    }

    /**
     * 数据源唯一性：候选清单 markdown 与本 seeder 读同一份文件。
     * 少走这一条，将来有人改了数据文件却忘了重灌，两份就会静默分叉。
     */
    #[Test]
    public function the_data_file_is_the_single_source_of_truth(): void
    {
        $path = database_path(PlatformSensitiveWordBaselineSeeder::BASELINE_DATA_FILE);

        $this->assertFileExists($path);
        $this->assertStringContainsString(
            PlatformSensitiveWordBaselineSeeder::BASELINE_DATA_FILE,
            file_get_contents(base_path('database/seeders/PlatformSensitiveWordBaselineSeeder.php')),
        );
    }

    #[Test]
    public function the_database_seeder_still_only_calls_the_tenant_seeder(): void
    {
        $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('TenantSeeder::class', $source);
    }

    /**
     * 词本身合法性：数据文件里不许有空词或带分隔符的词。
     * 空词会让 mb_strpos 恒真（全平台所有稿子判成命中），带分隔符的词会让紧凑匹配退化。
     */
    #[Test]
    public function baseline_data_file_has_no_empty_or_punctuated_words(): void
    {
        foreach ($this->baselineRows() as $row) {
            $word = trim((string) $row['word']);

            $this->assertNotSame('', $word);
            $this->assertSame(
                0,
                preg_match('/[\p{Z}\p{P}]/u', $word),
                "基线词「{$word}」含空格或标点，会让紧凑匹配退化",
            );
        }
    }
}
