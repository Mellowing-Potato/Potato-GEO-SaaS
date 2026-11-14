<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PlatformSensitiveWord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use RuntimeException;
use Throwable;

/**
 * 平台级敏感词基线库 · 灌词 seeder（上线批）。
 *
 * 数据源唯一：`database/data/platform_sensitive_words_baseline.php`。
 * 与生成候选清单 markdown 的脚本读同一份文件，杜绝「清单上一套、库里另一套」。
 *
 * ── 三条不变量，改这个文件之前先读完 ──
 *
 * 1️⃣ **只能用 Eloquent 逐条写，不许 `insert()` 批量写。**
 *    `PlatformSensitiveWord::booted()` 只在 `saved` / `deleted` **模型事件**上
 *    进位规则缓存版本号；查询构造器的 `insert()` 不触发模型事件。
 *    用 insert 灌 54 条的结果是：库里词全在，版本号纹丝不动 →
 *    `ArticleRiskScanner::rules()` 的缓存 key（`....p{版本号}`，TTL 一小时）
 *    不变，正在跑的实例继续吃**空规则集**，一篇都拦不住。
 *    灌完看库以为成了，实际闸门是开着的。
 *
 * 2️⃣ **不许兜底显式 bump。**
 *    如果在这里补一句 `PlatformSensitiveWord::bumpRuleCacheVersion()`，
 *    那么「谁把这里改成 insert 都不会出事」——也意味着那条守着不变量的测试
 *    会变成恒绿断言（正是上一轮四条断言栽跟头的那个坑）。
 *    不兜底，是为了让 insert 这个改法**当场变红**。
 *
 * 3️⃣ **不许挂进 `DatabaseSeeder::call()`。**
 *    `DatabaseSeeder` use 了 `WithoutModelEvents`，子 seeder 在它的执行窗口里
 *    跑时模型事件分发器是 unset 的 → 不变量 1 会以另一种方式复发。
 *    所以 run() 开头主动查事件分发器，被摘掉就直接抛异常，不让它在静默中失效。
 *    独立执行：`php artisan db:seed --class=PlatformSensitiveWordBaselineSeeder`
 */
class PlatformSensitiveWordBaselineSeeder extends Seeder
{
    public const BASELINE_DATA_FILE = 'data/platform_sensitive_words_baseline.php';

    /** 词源拼进 note 的分隔符（幂等拼接的识别标记，改动要同步改 composeNote 的正则）。 */
    public const SOURCE_NOTE_GLUE = '｜词源：';

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    public function run(): void
    {
        $this->assertModelEventsAreLive();

        $rows = $this->baselineRows();

        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $word = $this->validatedWord($row);

            $model = PlatformSensitiveWord::query()->updateOrCreate(
                ['word' => $word],
                [
                    'severity' => $this->validatedSeverity($row),
                    'category' => $this->validatedCategory($row),
                    'is_enabled' => true,
                    'note' => $this->composeNote($row),
                ],
            );

            // updateOrCreate 命中已存在的行时也会走 finishSave() → 触发 saved → bump 版本号。
            // 这就是「复跑一遍也能让新词生效」的根据，别改成先查再跳过的写法。
            $model->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->command?->info(sprintf(
            '平台敏感词基线灌入完成：新增 %d 条，更新 %d 条，合计 %d 条；规则缓存版本号 = %d',
            $created,
            $updated,
            $created + $updated,
            PlatformSensitiveWord::ruleCacheVersion(),
        ));
    }

    /**
     * 模型事件分发器必须是活的，否则不变量 1 静默失效。
     */
    private function assertModelEventsAreLive(): void
    {
        if (Model::getEventDispatcher() === null) {
            throw new RuntimeException(
                '平台敏感词基线灌词中止：模型事件分发器已被摘除（多半是被 use WithoutModelEvents 的 seeder 包着跑）。'
                .'这样写入不会进位规则缓存版本号，库里有词但闸门不生效。'
                .'请独立执行：php artisan db:seed --class=PlatformSensitiveWordBaselineSeeder'
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function baselineRows(): array
    {
        if ($this->rows !== []) {
            return $this->rows;
        }

        $path = database_path(self::BASELINE_DATA_FILE);

        if (! is_file($path)) {
            throw new RuntimeException('基线词数据文件不存在：'.$path);
        }

        try {
            $rows = require $path;
        } catch (Throwable $e) {
            throw new RuntimeException('基线词数据文件无法解析：'.$path.'（'.$e->getMessage().'）', 0, $e);
        }

        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('基线词数据文件没有返回任何词条：'.$path);
        }

        return $this->rows = array_values($rows);
    }

    /**
     * 词本身的合法性。
     *
     * 这两道校验不是洁癖：词带空格/标点会让扫描器的紧凑匹配退化，
     * 空词更糟——`mb_strpos($text, '')` 恒真，等于把全平台所有稿子判成命中。
     * 宁可灌词失败并当场报错，也不要静默灌进一批无效词。
     *
     * @param  array<string, mixed>  $row
     */
    private function validatedWord(array $row): string
    {
        $word = trim((string) ($row['word'] ?? ''));

        if ($word === '') {
            throw new RuntimeException('基线词数据存在空词，已中止整批灌入（空词会让扫描器恒命中）。');
        }

        if (preg_match('/[\p{Z}\p{P}]/u', $word) === 1) {
            throw new RuntimeException(sprintf(
                '基线词「%s」含空格或标点，已中止整批灌入（会让紧凑匹配退化，绕过只需插一个空格）。',
                $word,
            ));
        }

        return $word;
    }

    /** @param array<string, mixed> $row */
    private function validatedSeverity(array $row): string
    {
        $severity = (string) ($row['severity'] ?? PlatformSensitiveWord::SEVERITY_BLOCKED);

        if (! in_array($severity, PlatformSensitiveWord::SEVERITIES, true)) {
            throw new RuntimeException(sprintf('基线词 severity 非法：%s', $severity));
        }

        return $severity;
    }

    /** @param array<string, mixed> $row */
    private function validatedCategory(array $row): string
    {
        $category = (string) ($row['category'] ?? PlatformSensitiveWord::CATEGORY_POLITICS);

        if (! in_array($category, PlatformSensitiveWord::CATEGORIES, true)) {
            throw new RuntimeException(sprintf('基线词 category 非法：%s', $category));
        }

        return $category;
    }

    /**
     * 把 `source` 并进 `note`。
     *
     * `source` 不是数据库列，也不在模型 $fillable 里——直接把整行丢进 create()
     * 会被 Laravel **静默丢弃**，不报错。而「这条词哪来的」在合规上必须答得出来
     * （老板第 3 条），所以在这里显式合流，让溯源信息跟着词一起落库。
     *
     * 幂等：先剥掉上一次灌入拼上去的词源后缀再拼，
     * 否则复跑会叠加成「…｜词源：A｜词源：A」。
     *
     * @param  array<string, mixed>  $row
     */
    private function composeNote(array $row): string
    {
        $note = trim((string) ($row['note'] ?? ''));
        $note = preg_replace('/\s*'.preg_quote(self::SOURCE_NOTE_GLUE, '/').'.*$/u', '', $note) ?? $note;
        $note = rtrim($note);

        $source = trim((string) ($row['source'] ?? ''));

        return $source === '' ? $note : $note.self::SOURCE_NOTE_GLUE.$source;
    }
}
