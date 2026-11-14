<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\PlatformSensitiveWordDryRun;
use App\Models\Tenant;
use App\Services\Publishing\ArticleRiskScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 加词前试算：跨租户扫一遍现有文章，算出「这批候选词会拦掉多少篇稿子」（执行书 T8）。
 *
 * ⚠️ 为什么必须走队列，不许在 HTTP 请求里同步扫：
 * 这是**跨租户全表**扫描。文章数随平台增长线性上涨，同步扫必然演化成
 * 「运营台点一下试算 → 请求超时 → 运营以为坏了 → 干脆跳过试算直接加词」。
 * 试算被跳过，安全阀就等于不存在。
 *
 * ⚠️ 单篇失败不许拖垮整次试算：
 * 正文超长（> MAX_CONTENT_CHARACTERS）会让扫描器抛 LengthException。
 * 全平台只要有一篇超长稿，整次试算就永远出不来结果 —— 于是运营永远看不到误伤面。
 * 所以逐篇 catch，把这类稿子计进 articles_skipped 并如实回给运营看
 * （「有 N 篇没扫到」是必须让人知道的事实，不能静默吞掉）。
 */
class DryRunPlatformSensitiveWordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 跨租户全表扫要给足时间，但**必须 < 队列 retry_after（600s）**。
     *
     * 仓库里 QueueJobTimeoutInvariantTest 会守这条：timeout ≥ retry_after 时，
     * 任务跑到边界会被第二个 worker 重新领取 —— 两个 worker 同时扫同一批词、
     * 抢着写同一行试算结果，运营看到的数字会在两次刷新之间跳变。
     * 所以取 540s。真扫不完的规模（几十万篇）应当分批试算，
     * 而不是把 timeout 顶上去把不变量顶破。
     */
    public int $timeout = 540;

    /** 不重试：重扫一遍只会把同一批词算两遍，没有幂等收益。 */
    public int $tries = 1;

    /** 分片大小：一次取太多篇正文会把 worker 内存打满。 */
    private const CHUNK = 100;

    public function __construct(public readonly int $dryRunId)
    {
        $this->onQueue('default');
    }

    public function handle(ArticleRiskScanner $scanner): void
    {
        $dryRun = PlatformSensitiveWordDryRun::query()->find($this->dryRunId);

        if (! $dryRun instanceof PlatformSensitiveWordDryRun) {
            Log::warning('DryRunPlatformSensitiveWordsJob: dry-run record not found', [
                'dry_run_id' => $this->dryRunId,
            ]);

            return;
        }

        $dryRun->forceFill([
            'status' => PlatformSensitiveWordDryRun::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        try {
            $this->scan($scanner, $dryRun);
        } catch (Throwable $e) {
            // 失败必须落成 failed 并带上原因：留在 running 上，运营台会一直转圈，
            // 最后他会当成「没命中」放行 —— 这正是要避免的误读。
            $dryRun->forceFill([
                'status' => PlatformSensitiveWordDryRun::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ])->save();

            Log::error('DryRunPlatformSensitiveWordsJob failed', [
                'dry_run_id' => $dryRun->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 队列层面失败（超时被杀 / MaxAttemptsExceeded）时把记录落成 failed。
     *
     * ⚠️ 这个方法不是锦上添花：handle() 里的 try/catch 只兜得住 PHP 异常，
     * 兜不住「超过 timeout 被 worker 直接杀掉」或「被二次领取后 attempts 超限」。
     * 那两种情况下记录会**永远停在 running**，运营台一直转圈，
     * 最后他会把「转不出来」当成「没有命中」，然后放心把词加进基线库 ——
     * 恰好是试算这道安全阀最不该出现的失效方式。
     */
    public function failed(?Throwable $e): void
    {
        $dryRun = PlatformSensitiveWordDryRun::query()->find($this->dryRunId);

        if (! $dryRun instanceof PlatformSensitiveWordDryRun || $dryRun->isFinished()) {
            return;
        }

        $dryRun->forceFill([
            'status' => PlatformSensitiveWordDryRun::STATUS_FAILED,
            'error' => $e === null
                ? '试算任务被队列中止（可能超时）。请缩小候选词批量后重试。'
                : mb_substr($e->getMessage(), 0, 2000),
            'finished_at' => now(),
        ])->save();
    }

    private function scan(ArticleRiskScanner $scanner, PlatformSensitiveWordDryRun $dryRun): void
    {
        $candidates = array_values(array_filter(
            array_map(static fn ($w): string => trim((string) $w), (array) $dryRun->candidates),
            static fn (string $w): bool => $w !== '',
        ));

        $scanned = 0;
        $skipped = 0;
        $hitArticles = 0;

        /** @var array<int, int> $byTenant tenant_id => 命中篇数 */
        $byTenant = [];
        /** @var array<string, array{hit_articles: int, tenants: array<int, true>, samples: array<int, array{tenant_id: int, article_id: int, field: string, snippet: string}>}> $byWord */
        $byWord = [];

        Article::query()
            ->withoutGlobalScopes()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($articles) use (
                $scanner, $candidates, &$scanned, &$skipped, &$hitArticles, &$byTenant, &$byWord
            ): void {
                foreach ($articles as $article) {
                    try {
                        $matches = $scanner->matchCandidateWords(
                            $scanner->articleContent($article),
                            $candidates,
                        );
                    } catch (Throwable $e) {
                        // 超长正文等单篇问题：跳过并计数，不中断整次试算。
                        $skipped++;

                        continue;
                    }

                    $scanned++;

                    if ($matches === []) {
                        continue;
                    }

                    $tenantId = (int) $article->tenant_id;
                    $hitArticles++;
                    $byTenant[$tenantId] = ($byTenant[$tenantId] ?? 0) + 1;

                    // 同一篇里同一个词可能命中多个字段，按词去重后再计「命中篇数」，
                    // 否则「命中篇数」会大于文章总数，运营完全没法判断误伤规模。
                    $wordsInThisArticle = [];

                    foreach ($matches as $match) {
                        $word = (string) $match['word'];

                        if (! isset($byWord[$word])) {
                            $byWord[$word] = ['hit_articles' => 0, 'tenants' => [], 'samples' => []];
                        }

                        if (! isset($wordsInThisArticle[$word])) {
                            $wordsInThisArticle[$word] = true;
                            $byWord[$word]['hit_articles']++;
                            $byWord[$word]['tenants'][$tenantId] = true;
                        }

                        if (count($byWord[$word]['samples']) < PlatformSensitiveWordDryRun::MAX_SAMPLES_PER_WORD) {
                            $byWord[$word]['samples'][] = [
                                'tenant_id' => $tenantId,
                                'article_id' => (int) $article->getKey(),
                                'field' => (string) $match['field'],
                                'snippet' => (string) $match['snippet'],
                            ];
                        }
                    }
                }
            });

        $tenantNames = Tenant::query()->pluck('name', 'id');

        $byTenantPayload = [];
        foreach ($byTenant as $tenantId => $count) {
            $byTenantPayload[] = [
                'tenant_id' => $tenantId,
                'tenant_name' => (string) ($tenantNames[$tenantId] ?? ('租户 #'.$tenantId)),
                'hit_articles' => $count,
            ];
        }
        usort($byTenantPayload, static fn (array $a, array $b): int => $b['hit_articles'] <=> $a['hit_articles']);

        $byWordPayload = [];
        foreach ($candidates as $word) {
            $row = $byWord[$word] ?? null;
            $byWordPayload[] = [
                'word' => $word,
                'hit_articles' => $row['hit_articles'] ?? 0,
                'hit_tenants' => $row === null ? 0 : count($row['tenants']),
                'samples' => $row['samples'] ?? [],
            ];
        }
        // 命中最多的词排最前：加错的常用词（「发展」这种）会自己浮到最上面。
        usort($byWordPayload, static fn (array $a, array $b): int => $b['hit_articles'] <=> $a['hit_articles']);

        $dryRun->forceFill([
            'status' => PlatformSensitiveWordDryRun::STATUS_COMPLETED,
            'articles_scanned' => $scanned,
            'articles_skipped' => $skipped,
            'hit_articles' => $hitArticles,
            'by_tenant' => $byTenantPayload,
            'by_word' => $byWordPayload,
            'error' => null,
            'finished_at' => now(),
        ])->save();
    }
}
