<?php

namespace App\Models;

use App\Services\Content\SensitiveWordChecker;
use App\Services\Distribution\DistributionOrchestrator;
use App\Support\Concerns\BelongsToTenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Article extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * 自动分发总开关（阶段 1.4 任务编排用）。
     *
     * 默认开启：文章状态流转到 published 时由观察者自动触发分发。
     * 任务编排在「自动发布但关闭自动分发」场景下临时置 false，避免发布即外发。
     * 注意：这是进程级静态开关，调用方必须用 try/finally 还原。
     */
    public static bool $autoDistributionEnabled = true;

    /**
     * saving 钩子扫出来的命中，等 saved 拿到主键后再落 sensitive_word_hits（执行书 T5/T7）。
     *
     * 为什么要中转一手：saving 阶段新文章还没有 id，命中日志的 article_id 会是 null，
     * 而 T7/T8 的硬要求是「给定一个文章 ID，运营台能直接答出被哪条词拦的」——
     * 日志里没 article_id 就答不出来。
     * 而 flagged_sensitive 又必须在 saving 阶段算完（saved 之后再改就得多写一次库）。
     * 所以拆成两段：saving 算标记 + 暂存命中，saved 落日志。
     *
     * 这是进程内临时状态，不入库、不进 $fillable。
     *
     * @var array{word: string, severity: string, origin: string, surface: string, context: string, trigger: string}|null
     */
    public ?array $pendingSensitiveWordHit = null;

    protected static function booted(): void
    {
        // T5：内容合规检查的唯一收口点。
        //
        // 全仓 5 个文章写入点（ArticleController:152、UrlImportProcessingService:46、
        // SkillAgentRunnerService:1488、ReportAssetService:48 和 :94）最后都落到
        // $article->save()，所以只在这里挂一次，5 处全覆盖，以后新增写入点也自动覆盖。
        // 不要回到各个写入点里各写一遍——那才是真正会漏的写法。
        static::saving(function (self $article) {
            // 只在正文类字段变了的时候重扫，避免无关更新（改状态、改 SEO 字段、
            // 递增 version）白跑一次全词表扫描。
            if (! $article->isDirty(['title', 'summary', 'body_markdown'])) {
                return;
            }

            self::scanSensitiveWords($article);
        });

        static::saved(function (self $article) {
            self::flushPendingSensitiveWordHit($article);
        });

        // D3: 直接以 published 创建的文章也触发自动分发
        static::created(function (self $article) {
            if ($article->status === 'published') {
                self::triggerAutoDistribution($article);
            }
        });

        static::updated(function (self $article) {
            if ($article->wasChanged('status') && $article->status === 'published') {
                self::triggerAutoDistribution($article);
            }
        });
    }

    /**
     * 标记 flagged_sensitive 并暂存命中信息（执行书 T5）。
     *
     * 三条硬要求都在这里：
     *
     * 1. **只标记，不阻断。** 命中平台 blocked 词的文章照常落库。
     *    AI 生成中途抛异常会把整个技能执行搞挂，用户付了钱拿不到东西；
     *    而这时候内容还没离开平台，没有任何合规风险。真正的拦截在
     *    DistributionOrchestrator::enqueueForArticle（T6，内容出站那一刻）。
     * 2. **必须 try/catch。** 扫描失败不能让文章存不进去。
     *    失败时保持 flagged_sensitive 原值 + Log::warning。
     *    这里跟 T1/T6 的 fail-close 不同：T5 只是个角标，标记失败不等于闸门打开。
     * 3. **扣费口径不变。** 命中不影响已经发生的模型调用扣费——
     *    算力已经消耗了，内容也确实生成了，只是不让它出站。不要以为该退费。
     */
    private static function scanSensitiveWords(self $article): void
    {
        try {
            $match = app(SensitiveWordChecker::class)->firstMatchAmong([
                'title' => (string) $article->title,
                'summary' => (string) $article->summary,
                'content' => (string) $article->body_markdown,
            ]);

            $article->flagged_sensitive = $match !== null;

            // 正文改过了，上一次出站被拦的结论就作废了：清掉「未分发原因」，
            // 让 T6 的闸门在下一次出站时重新判定。不清的话作者改好了稿子，
            // 详情页上那句「文中有违规用词」还挂着，他会以为改了没用。
            $article->distribution_blocked_reason = null;

            $article->pendingSensitiveWordHit = $match === null ? null : [
                ...$match,
                // saving 阶段 exists 还是 false 的就是新建
                'trigger' => $article->exists
                    ? SensitiveWordHit::TRIGGER_UPDATE
                    : SensitiveWordHit::TRIGGER_CREATE,
            ];
        } catch (\Throwable $e) {
            $article->pendingSensitiveWordHit = null;
            Log::warning('Sensitive word scan failed on save; keeping previous flag', [
                'article_id' => $article->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 把 saving 阶段暂存的命中写进日志（此时主键已经有了）。
     */
    private static function flushPendingSensitiveWordHit(self $article): void
    {
        $pending = $article->pendingSensitiveWordHit;
        if ($pending === null) {
            return;
        }

        // 先清空再写：写失败也不要在下一次 save 时重复落同一条。
        $article->pendingSensitiveWordHit = null;

        // record() 内部自带 try/catch，写日志失败只告警不影响主流程。
        SensitiveWordHit::record([
            'tenant_id' => $article->tenant_id ?: TenantContext::id(),
            'article_id' => $article->getKey(),
            'word' => $pending['word'],
            'severity' => $pending['severity'],
            'origin' => $pending['origin'],
            'surface' => $pending['surface'],
            'context' => $pending['context'],
            'trigger' => $pending['trigger'],
        ]);
    }

    /**
     * 非阻塞触发自动分发。enqueueForArticle 幂等，create→后续 update 双触发不重复分发。
     */
    private static function triggerAutoDistribution(self $article): void
    {
        if (! self::$autoDistributionEnabled) {
            return;
        }

        try {
            $result = app(DistributionOrchestrator::class)
                ->enqueueForArticle($article);

            // T6：合规拦截必须单独记一条**可辨识**的日志。
            //
            // 下面那个 catch 会把任何异常都吞成一行 'Auto-distribution enqueue failed'，
            // 所以 enqueueForArticle 用返回值表达「被拦住了」而不是抛异常——
            // 否则日志上「政治词被拦」和「WordPress 连不上」长得一模一样，
            // 运营根本分不清是合规拦截还是网络故障，也就无从判断误伤。
            if (($result['blocked'] ?? false) === true) {
                Log::warning(
                    'Auto-distribution blocked by compliance gate',
                    [
                        'article_id' => $article->id,
                        'tenant_id' => $article->tenant_id,
                        // 只记原因码（sensitive_word / scan_failed），不记命中的词——
                        // 日志会进运维通道，平台红线词不往那里扩散。
                        'reason' => $result['reason'] ?? 'unknown',
                    ],
                );
            }
        } catch (\Throwable $e) {
            // 分发失败绝不阻塞本地发布流程
            Log::warning(
                'Auto-distribution enqueue failed',
                ['article_id' => $article->id, 'error' => $e->getMessage()],
            );
        }
    }

    protected $fillable = [
        'project_id',
        'author_id',
        'byline_author_id',
        'title',
        'slug',
        'body_markdown',
        'summary',
        'content_type',
        'category',
        'category_id',
        'status',
        'source_type',
        'source_skill',
        'skill_execution_id',
        'seo_title',
        'seo_description',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image',
        'cited_chunks',
        'quality_score',
        'version',
        'word_count',
        'metadata',
        'reviewed_by',
        'submitted_for_review_at',
        'reviewed_at',
        'review_notes',
        'published_at',
        'flagged_sensitive',
        // T10：出站被合规闸门拦下时给作者看的固定文案。只存文案，绝不存命中的平台词。
        'distribution_blocked_reason',
    ];

    protected $casts = [
        'cited_chunks' => 'array',
        'metadata' => 'array',
        'quality_score' => 'integer',
        'version' => 'integer',
        'word_count' => 'integer',
        'category_id' => 'integer',
        'byline_author_id' => 'integer',
        'submitted_for_review_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'flagged_sensitive' => 'boolean',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_EDITING = 'editing';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RECYCLED = 'recycled';

    // Relationships
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function skillExecution(): BelongsTo
    {
        return $this->belongsTo(SkillExecution::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ArticlePublication::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function bylineAuthor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'byline_author_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ArticleReview::class, 'article_id')->orderByDesc('created_at');
    }

    public function riskScans(): HasMany
    {
        return $this->hasMany(ArticleRiskScan::class, 'article_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArticleVersion::class, 'article_id')->orderByDesc('version');
    }

    public function articleImages(): HasMany
    {
        return $this->hasMany(ArticleImage::class, 'article_id')->orderBy('position');
    }

    /**
     * 写一条审核流水（对标 GEOFlow ArticleReview）。
     */
    public function recordReview(?int $reviewerId, string $status, ?string $note = null): ArticleReview
    {
        return $this->reviews()->create([
            'tenant_id' => $this->tenant_id,
            'reviewer_id' => $reviewerId,
            'review_status' => $status,
            'review_note' => $note ?: null,
        ]);
    }

    /**
     * 快照当前可编辑字段为一条版本记录（对标 GEOFlow 轻量版本）。
     * 返回新建的 ArticleVersion。
     */
    public function snapshotVersion(?int $userId): ArticleVersion
    {
        return $this->versions()->create([
            'tenant_id' => $this->tenant_id,
            'version' => $this->version ?? 1,
            'title' => $this->title,
            'body_markdown' => $this->body_markdown,
            'summary' => $this->summary,
            'content_type' => $this->content_type,
            'category' => $this->category,
            'category_id' => $this->category_id,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'canonical_url' => $this->canonical_url,
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'og_image' => $this->og_image,
            'created_by_user_id' => $userId,
        ]);
    }

    // Scopes
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('content_type', $type);
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // Computed
    public function getTypeLabelAttribute(): string
    {
        return match ($this->content_type) {
            'explainer' => '科普文章',
            'comparison' => '对比文章',
            'ranking' => '榜单文章',
            default => '通用文章',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'draft' => '草稿',
            'editing' => '编辑中',
            'pending' => '待审核',
            'approved' => '审核通过',
            'published' => '已发布',
            'recycled' => '已废弃',
            default => $this->status,
        };
    }
}
