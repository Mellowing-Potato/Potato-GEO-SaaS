<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Services/GeoFlow/ManualPublicationService.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Publishing;

use App\Exceptions\ManualPublicationConflictException;
use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\ManualPublicationTransition;
use App\Models\SensitiveWordHit;
use App\Models\User;
use App\Support\Permissions;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 手动发布核心服务：建单、改单、流转、重开。
 *
 * 适配说明（单 7 执行书 §一-2）：本仓没有 Policies/Gate，自建 RBAC 由 Permissions 矩阵驱动。
 * 上游基于 Gate 的「记录级」判断改为服务层显式检查（assertCan*），避免「看起来在保护、
 * 实际不保护」的死代码。admin 角色天然拥有全部租户内权限（Permissions::allows 快路径），
 * 因此「超管」在本仓对应 role === 'admin'。
 */
class ManualPublicationService
{
    /**
     * 待落库的敏感词命中（执行书 T7）。
     *
     * @var array<int, array<string, mixed>>
     */
    private array $pendingSensitiveWordHits = [];

    public function __construct(
        private readonly ArticleRiskScanner $riskScanner,
        private readonly ManualPublicationDuplicateDetector $duplicateDetector,
    ) {}

    /**
     * 在事务**之外**落敏感词命中日志（执行书 T7）。
     *
     * ⚠️ 这一层不是可有可无的包装。
     * 闸门命中时抛 DomainException，而 create() / transition() 的主体在
     * DB::transaction() 里——异常会让整个事务回滚，**连命中日志一起抹掉**。
     * 而命中日志正是平台唯一的烟雾报警器（T7）：租户侧对误伤不会有任何声音，
     * 运营只能靠这张表主动看出「哪条词加错了」。被拦的那些正是最该留证的样本，
     * 结果偏偏被回滚吃掉，等于报警器在真着火的时候不响。
     *
     * finally 里 DB::transaction() 的回滚已经执行完了，这时候写才落得住。
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function withSensitiveWordHitFlush(\Closure $callback): mixed
    {
        $this->pendingSensitiveWordHits = [];

        try {
            return $callback();
        } finally {
            $pending = $this->pendingSensitiveWordHits;
            $this->pendingSensitiveWordHits = [];
            foreach ($pending as $hit) {
                // record() 自带 try/catch，写日志失败只告警，不影响主流程。
                SensitiveWordHit::record($hit);
            }
        }
    }

    /**
     * 把 blocked 级命中排进待落库队列。
     *
     * @param  array<string, mixed>|null  $riskResult
     */
    private function queueSensitiveWordHits(?array $riskResult, ?int $articleId): void
    {
        foreach ($this->blockedMatches($riskResult) as $match) {
            $this->pendingSensitiveWordHits[] = [
                'tenant_id' => TenantContext::id(),
                'article_id' => $articleId,
                'word' => (string) ($match['word'] ?? ''),
                'severity' => (string) ($match['severity'] ?? 'blocked'),
                'origin' => (string) ($match['origin'] ?? SensitiveWordHit::ORIGIN_TENANT),
                'surface' => (string) ($match['field'] ?? 'content'),
                'context' => isset($match['snippet']) ? (string) $match['snippet'] : null,
                'trigger' => SensitiveWordHit::TRIGGER_MANUAL_PUBLISH,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  User  $creator  当前操作人（用于分配权限校验与 created_by_user_id）
     */
    public function create(array $data, User $creator): ManualPublication
    {
        return $this->withSensitiveWordHitFlush(fn (): ManualPublication => DB::transaction(function () use ($data, $creator): ManualPublication {
            $prepared = $this->prepare($data, null, $creator);
            $prepared['created_by_user_id'] = $creator->getKey();
            $initialStatus = (string) ($data['status'] ?? ManualPublication::STATUS_DRAFT);
            if (! in_array($initialStatus, [ManualPublication::STATUS_DRAFT, ManualPublication::STATUS_READY], true)) {
                throw new DomainException((string) __('admin.manual_publications.error.invalid_transition'));
            }
            $prepared['status'] = $initialStatus;
            $prepared['status_changed_at'] = now();
            $prepared['revision'] = 1;
            $this->ensureReadyRequirements($prepared);
            if ($initialStatus === ManualPublication::STATUS_READY) {
                $this->assertRiskNotBlocked(
                    (string) $prepared['risk_status'],
                    $prepared['risk_result'],
                    $prepared['article_id'] === null ? null : (int) $prepared['article_id'],
                );
            }
            $prepared['duplicate_warning_count'] = $this->duplicateDetector->find($prepared)->count();

            $publication = ManualPublication::query()->create($prepared);
            $this->recordTransition($publication, null, $initialStatus, $creator);

            return $publication->refresh();
        }));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  User  $user  当前操作人（completed 不可改；非 admin 不能改别人的单）
     */
    public function update(ManualPublication $manualPublication, array $data, int $expectedRevision, User $user): ManualPublication
    {
        // 评审项 11：completed 不可改是业务规则（上游语义 422 completed_immutable），不是权限错误；
        // 置于权限断言之前，admin 与非 admin 一律 422，不泄露权限差异。
        if ((string) $manualPublication->status === ManualPublication::STATUS_COMPLETED) {
            throw new DomainException((string) __('admin.manual_publications.error.completed_immutable'));
        }

        $this->assertCan($user, $manualPublication, 'update');

        // 评审阻断项 3：PUT 允许部分字段更新（控制器 type/persona_id/platform/content 均 sometimes），
        // 未传字段必须回退现值——否则 prepare() 直读 $data['type'] 会 500，
        // 且 assignee/account/schedule 等未传字段会被整体抹空（数据丢失）。
        $data = $this->mergeWithExisting($data, $manualPublication);

        $prepared = $this->prepare($data, $manualPublication, $user);
        $currentStatus = (string) $manualPublication->status;
        $this->ensureReadyRequirements($prepared + ['status' => $currentStatus]);
        // 已离开草稿的单子（待发 / 进行中）不许被改成命中拦截词的内容——否则
        // 只在 ready 那一道设卡就成了摆设：先用干净内容进待发，再回头把违禁内容
        // 编辑进去，闸门永远不会再被触发一次。草稿故意不拦，作者要留得住底稿。
        if (in_array($currentStatus, [ManualPublication::STATUS_READY, ManualPublication::STATUS_IN_PROGRESS], true)) {
            $this->withSensitiveWordHitFlush(function () use ($prepared): void {
                $this->assertRiskNotBlocked(
                    (string) $prepared['risk_status'],
                    $prepared['risk_result'],
                    $prepared['article_id'] === null ? null : (int) $prepared['article_id'],
                );
            });
        }
        $prepared['duplicate_warning_count'] = $this->duplicateDetector
            ->find($prepared, (int) $manualPublication->getKey())
            ->count();
        if ($manualPublication->draft_url && (
            $prepared['type'] !== ManualPublication::TYPE_POST
            || $prepared['platform'] !== ManualPublicationAccount::PLATFORM_ZHIHU
            || (int) $prepared['account_id'] !== (int) $manualPublication->account_id
            || (int) $prepared['article_id'] !== (int) $manualPublication->article_id
        )) {
            $prepared['draft_url'] = null;
            $prepared['draft_synced_at'] = null;
            $prepared['draft_content_fingerprint'] = null;
        }
        $prepared['updated_at'] = now();
        $prepared['revision'] = $expectedRevision + 1;
        $casted = (new ManualPublication)->forceFill($prepared);
        $databaseUpdates = Arr::only($casted->getAttributes(), array_keys($prepared));

        $updated = ManualPublication::query()
            ->whereKey($manualPublication->getKey())
            ->where('revision', $expectedRevision)
            ->where('status', '!=', ManualPublication::STATUS_COMPLETED)
            ->update($databaseUpdates);

        if ($updated !== 1) {
            throw new ManualPublicationConflictException;
        }

        return $manualPublication->refresh();
    }

    public function transition(
        ManualPublication $manualPublication,
        string $targetStatus,
        int $expectedRevision,
        User $actor,
        ?string $completionUrl = null,
        ?string $resultNote = null,
    ): ManualPublication {
        // 词库是活的：建单之后新加的拦截词，旧单库里的 risk_status 还停在旧值。
        // 进 ready 前按当前词库重扫一遍并落库——放在事务外是故意的：下面若因命中
        // 拦截词抛异常，事务回滚会把这次重扫也一并抹掉，界面就会出现「显示干净、
        // 却被拒绝」的自相矛盾。本方法只写 risk_status / risk_result，不碰 revision，
        // 不影响乐观锁。
        if ($targetStatus === ManualPublication::STATUS_READY) {
            $this->refreshRiskStatus($manualPublication);
        }

        return $this->withSensitiveWordHitFlush(fn (): ManualPublication => DB::transaction(function () use ($manualPublication, $targetStatus, $expectedRevision, $completionUrl, $resultNote, $actor): ManualPublication {
            $current = ManualPublication::query()
                ->whereKey($manualPublication->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $current->revision !== $expectedRevision) {
                throw new ManualPublicationConflictException;
            }

            $ability = $current->isReopenTransition($targetStatus) ? 'reopen' : 'transition';
            $this->assertCan($actor, $current, $ability);

            if (! $current->canTransitionTo($targetStatus)) {
                throw new DomainException((string) __('admin.manual_publications.error.invalid_transition'));
            }

            $fromStatus = (string) $current->status;
            if (in_array($targetStatus, ['ready', 'in_progress'], true)) {
                $this->assertReviewedSource($current);
                $this->assertCurrentAccount($current);
            }
            $transitionedAt = now();
            $normalizedResultNote = trim((string) $resultNote) ?: null;
            $updates = [
                'status' => $targetStatus,
                'status_changed_at' => $transitionedAt,
                'revision' => $expectedRevision + 1,
                'result_note' => $normalizedResultNote,
            ];

            if ($targetStatus === ManualPublication::STATUS_READY) {
                $this->assertRiskNotBlocked(
                    (string) $current->risk_status,
                    $current->risk_result,
                    $current->article_id === null ? null : (int) $current->article_id,
                );
                $this->ensureReadyRequirements(array_merge($current->getAttributes(), ['status' => $targetStatus]));
                $updates['completion_url'] = null;
                $updates['completed_at'] = null;
            }

            if ($targetStatus === ManualPublication::STATUS_COMPLETED) {
                $completionUrl = trim((string) $completionUrl);
                if ($current->account_id && ! PublicationPlatform::acceptsPublishedUrl($current->platform, $completionUrl)) {
                    throw new DomainException('请填写所选平台的公开文章或回答链接，不能使用主页、草稿或编辑地址。');
                }
                if (! $this->isHttpUrl($completionUrl)
                    || ($current->platform === ManualPublicationAccount::PLATFORM_ZHIHU
                        && preg_match('~^https://zhuanlan\.zhihu\.com/p/\d+/edit(?:[/?#]|$)~', $completionUrl))) {
                    throw new DomainException((string) __('admin.manual_publications.error.completion_url_required'));
                }
                $updates['completion_url'] = $completionUrl;
                $updates['completed_at'] = now();
            }

            $current->forceFill($updates)->save();
            if ($targetStatus === ManualPublication::STATUS_COMPLETED && $current->article_id
                && data_get($current->source_snapshot, 'article_version')) {
                Article::query()->whereKey($current->article_id)
                    ->where('version', (int) data_get($current->source_snapshot, 'article_version'))
                    ->where('status', 'approved')->update(['status' => 'published', 'published_at' => now()]);
            }
            $this->recordTransition(
                $current,
                $fromStatus,
                $targetStatus,
                $actor,
                $targetStatus === ManualPublication::STATUS_COMPLETED ? $completionUrl : null,
                $normalizedResultNote,
                $transitionedAt,
            );

            return $current->refresh();
        }));
    }

    public function assertPlatformDraftReady(ManualPublication $publication, int $revision, User $actor, bool $requireInProgress = false): ManualPublication
    {
        $this->assertCan($actor, $publication, 'transition');
        $current = $publication->fresh(['account.persona']);
        if (! $current) {
            throw new DomainException('发布单已删除，请刷新列表。');
        }
        $this->assertCan($actor, $current, 'transition');
        if ((int) $current->revision !== $revision) {
            throw new ManualPublicationConflictException;
        }
        $statuses = $requireInProgress ? ['in_progress'] : ['ready', 'in_progress'];
        if ($current->type !== 'post' || ! in_array($current->platform, PlatformPublicationDraftService::PLATFORMS, true)
            || ! in_array($current->status, $statuses, true) || $current->risk_status === 'blocked'
            || ! $current->account?->is_active || ! $current->account?->persona?->is_active) {
            throw new DomainException($requireInProgress
                ? '请先将有效平台账号的文章发布单推进到进行中，再确认草稿。'
                : '请先将有效平台账号的文章发布单推进到待发或进行中。');
        }
        $this->assertReviewedSource($current);
        $this->assertCurrentAccount($current);

        return $current;
    }

    public function draftPayload(ManualPublication $publication, int $revision, User $actor): array
    {
        $this->assertCan($actor, $publication, 'transition');
        $current = $publication->fresh(['account']);
        if ($current->revision !== $revision) {
            throw new ManualPublicationConflictException;
        }
        if ($current->status !== 'in_progress' || $current->type !== 'post' || $current->platform !== 'zhihu'
            || $current->risk_status === 'blocked' || ! $current->account?->is_active
            || ! preg_match('~^https://(?:www\.)?zhihu\.com/people/[^/?#]+/?$~', (string) $current->account->profile_url)) {
            throw new DomainException('请先使用有效知乎账号将文章发布单推进到进行中。');
        }
        $this->assertReviewedSource($current);
        $this->assertCurrentAccount($current);
        $title = trim((string) data_get($current->source_snapshot, 'title'));
        if ($title === '' || mb_strlen($title) > 100 || trim($current->content) === '') {
            throw new DomainException('请检查文章标题和正文，知乎标题不能超过 100 字。');
        }

        return [
            'title' => $title, 'body' => $current->content, 'profileUrl' => $current->account->profile_url,
            'draftUrl' => $current->draft_url, 'workspaceId' => (int) TenantContext::id(),
            'publicationId' => $current->id, 'revision' => $current->revision,
        ];
    }

    private function assertCurrentAccount(ManualPublication $publication): void
    {
        if ($publication->account_id && ! ManualPublicationAccount::query()->whereKey($publication->account_id)
            ->where('is_active', true)->where('persona_id', $publication->persona_id)
            ->where('platform', $publication->platform)
            ->whereHas('persona', fn ($query) => $query->where('is_active', true))->exists()) {
            throw new DomainException('发布账号或所属人设已停用或变更，请重新核对账号。');
        }
    }

    private function assertReviewedSource(ManualPublication $publication): void
    {
        if ($publication->type !== 'post') {
            return;
        }
        $article = Article::query()->whereHas('project')->find($publication->article_id);
        $version = data_get($publication->source_snapshot, 'article_version');
        if ($version === null) {
            throw new DomainException('历史发布单缺少审核版本，请从已审核文章重新准备发布单。');
        }
        if (! $article || ! in_array($article->status, ['approved', 'published'], true)
            || (int) $version !== (int) $article->version) {
            throw new DomainException('原文章已修改、撤回审核或归档，请重新审核并准备发布单。');
        }
    }

    public function recordZhihuDraft(
        ManualPublication $manualPublication,
        string $draftUrl,
        int $expectedRevision,
        User $actor,
    ): ManualPublication {
        $this->assertCan($actor, $manualPublication, 'transition');
        $draftUrl = trim($draftUrl);
        if (! preg_match('~^https://zhuanlan\.zhihu\.com/p/\d+/edit$~', $draftUrl)) {
            throw new DomainException('请提供知乎文章草稿的编辑链接。');
        }

        return DB::transaction(function () use ($manualPublication, $draftUrl, $expectedRevision): ManualPublication {
            $current = ManualPublication::query()
                ->whereKey($manualPublication->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $current->revision !== $expectedRevision) {
                throw new ManualPublicationConflictException;
            }
            $this->assertReviewedSource($current);
            $this->assertCurrentAccount($current);
            if ($current->type !== ManualPublication::TYPE_POST
                || $current->platform !== ManualPublicationAccount::PLATFORM_ZHIHU
                || $current->status !== ManualPublication::STATUS_IN_PROGRESS
                || $current->risk_status === 'blocked') {
                throw new DomainException('只有进行中且未被风险拦截的知乎文章发布单可以同步草稿。');
            }
            if (! $current->account_id || ! $current->account?->is_active
                || ! preg_match('~^https://(?:www\.)?zhihu\.com/people/[^/?#]+/?(?:\?.*)?$~', (string) $current->account->profile_url)) {
                throw new DomainException('请先绑定有效的知乎账号主页链接。');
            }
            if ($current->draft_url && $current->draft_url !== $draftUrl) {
                throw new DomainException('该发布单已有另一篇知乎草稿，请先核对原草稿链接。');
            }

            $current->forceFill([
                'draft_url' => $draftUrl,
                'draft_synced_at' => now(),
                'draft_content_fingerprint' => $current->content_fingerprint,
                'revision' => $expectedRevision + 1,
            ])->save();

            return $current->refresh();
        });
    }

    /** @return Collection<int, ManualPublication> */
    public function duplicatesFor(ManualPublication $manualPublication): Collection
    {
        return $this->duplicateDetector->find([
            'platform' => (string) $manualPublication->platform,
            'article_id' => $manualPublication->article_id === null ? null : (int) $manualPublication->article_id,
            'target_url_hash' => $manualPublication->target_url_hash,
            'content' => (string) $manualPublication->content,
            'content_fingerprint' => (string) $manualPublication->content_fingerprint,
        ], (int) $manualPublication->getKey());
    }

    /**
     * 记录级权限：view/update/transition/reopen（§一-2 第二层）。
     *
     * 2026-08-28 拍板：对齐本仓通例——角色定能力、租户定边界、不看记录归属。
     * 上游 GEOFlow 的 Policy 里 create/update/reopen 都是超管专属、view/transition 是
     * 「超管或被指派人」，那是作者自用的「老板 + 发帖执行工」模型。搬进多租户 SaaS 后，
     * editor 是内容团队成员：他能改本租户任何一篇文章（article:edit），就没有理由改不了
     * 本租户的发布单。故 view / update / transition 一律「有 manual_publish:manage 即可」。
     *
     * 留在 admin 手里的只有两件：指派给别人（manual_publish:assign，在 prepare() 里判）、
     * 重开终态单（manual_publish:reopen）——前者是分派他人的工作，后者是纠错 / 翻案。
     *
     * assigned_user_id 在本模块只表示「这单该谁去发」，不当作**写权限**的判据，所以这里不读它。
     * 若将来要让 member 只执行分配给自己的单，正确做法是新开 manual_publish:execute、
     * 在 routes/api.php 的路由门放行 member，并在此处按 assigned_user_id 加回判断；
     * 不要靠现有角色去凑（member 现在被路由门 403 挡在外面，那条归属分支永远走不到，
     * 留着只会让下一个人误以为「被指派人」是一道真实的门）。
     *
     * ⚠️ 别把上面这段读成「assigned_user_id 跟访问控制无关」——2026-08-29 验收时已有人
     *    这么读过，与实现不符：**列表可见性**确实按它过滤，见
     *    ManualPublication::scopeVisibleTo()：admin 看本租户全部，其余角色只看
     *    assigned_user_id = 自己的单（实测 editor 王五的列表只返回 1 条）。
     *    也就是说本模块里 assigned_user_id 管的是「看得到哪些」，不是「能改哪些」：
     *      - 可见性（列表）    → scopeVisibleTo() 按 assigned_user_id 过滤；
     *      - 写权限（增删改）  → 本方法 can()，只看角色能力 + 状态，不看归属。
     *    两者是独立的两道门，改其中一道不影响另一道。
     */
    public function can(User $user, ManualPublication $publication, string $action): bool
    {
        $role = (string) $user->role;
        $canManage = Permissions::allows($role, 'manual_publish:manage');

        return match ($action) {
            'view' => $canManage,
            'update' => $canManage && $publication->status !== ManualPublication::STATUS_COMPLETED,
            'transition' => $canManage,
            'reopen' => Permissions::allows($role, 'manual_publish:reopen')
                && in_array($publication->status, ManualPublication::REOPENABLE_STATUSES, true),
            default => false,
        };
    }

    /**
     * @throws AuthorizationException
     */
    public function assertCan(User $user, ManualPublication $publication, string $action): void
    {
        if (! $this->can($user, $publication, $action)) {
            throw new AuthorizationException((string) __('admin.manual_publications.error.forbidden'));
        }
    }

    /**
     * 部分更新合并：未出现在请求里的字段回退到现有记录值，实现真正的 PATCH 语义。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeWithExisting(array $data, ManualPublication $existing): array
    {
        $mergeKeys = [
            'type',
            'platform',
            'custom_platform',
            'persona_id',
            'account_id',
            'article_id',
            'assigned_user_id',
            'content',
            'target_url',
            'target_context',
            'scheduled_at',
        ];

        foreach ($mergeKeys as $key) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $existing->getAttribute($key);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data, ?ManualPublication $existing, User $actor): array
    {
        $type = (string) $data['type'];
        if (! in_array($type, ManualPublication::TYPES, true)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_type'));
        }
        $platform = (string) $data['platform'];
        if (! in_array($platform, ManualPublicationAccount::PLATFORMS, true)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_platform'));
        }
        $customPlatform = trim((string) ($data['custom_platform'] ?? '')) ?: null;
        if ($platform === ManualPublicationAccount::PLATFORM_CUSTOM && $customPlatform === null) {
            throw new DomainException((string) __('admin.manual_publications.error.custom_platform_required'));
        }

        $persona = ManualPublicationPersona::query()
            ->whereKey((int) $data['persona_id'])
            ->where('is_active', true)
            ->first();
        if (! $persona instanceof ManualPublicationPersona) {
            throw new DomainException((string) __('admin.manual_publications.error.persona_inactive'));
        }

        $account = null;
        if (! empty($data['account_id'])) {
            $account = ManualPublicationAccount::query()
                ->whereKey((int) $data['account_id'])
                ->where('is_active', true)
                ->first();
            if (! $account instanceof ManualPublicationAccount
                || (int) $account->persona_id !== (int) $persona->getKey()
                || $account->platform !== $platform
                || ($platform === ManualPublicationAccount::PLATFORM_CUSTOM
                    && $account->custom_platform !== null
                    && trim((string) $account->custom_platform) !== $customPlatform)) {
                throw new DomainException((string) __('admin.manual_publications.error.account_mismatch'));
            }
        }

        $article = null;
        if ($type === ManualPublication::TYPE_POST) {
            // B-1（执行书 §四 B-1）：建单校验里 article_id 是 nullable，键不传就不进 $validated，
            // 直读 $data['article_id'] 会 Undefined array key → 500。先判缺失/空值，统一走 422 业务异常通道。
            $articleId = $data['article_id'] ?? null;
            if (empty($articleId)) {
                throw new DomainException((string) __('admin.manual_publications.error.article_required'));
            }
            $query = Article::query();
            if (isset($data['article_version'])) {
                $query->lockForUpdate();
            }
            $article = $query->find((int) $articleId);
            // 本仓 Article 无 review_status 列（上游才有）；审核通过对应 status in [approved, published]
            if (! $article instanceof Article || ! in_array((string) $article->status, ['approved', 'published'], true)) {
                throw new DomainException((string) __('admin.manual_publications.error.article_not_approved'));
            }
            if (isset($data['article_version']) && (int) $data['article_version'] !== (int) $article->version) {
                throw new DomainException('关联文章版本已变化，请重新选择文章并核对正文。');
            }
        }

        // 分配权限（§一-2）：manual_publish:assign 仅 admin；非 admin 只能指派给自己。
        //
        // 2026-08-28：只在「指派对象发生变化」时才校验。改单是部分更新，mergeWithExisting()
        // 会把没传的 assigned_user_id 回填成现值——若无条件校验，editor 改一张指派给同事的单
        // 会被这里判成「试图指派给别人」而 422，等于把刚放开的 update 权限又从后门关掉。
        // 维持原样不是一次指派动作；换人才是。
        $assignedUserId = empty($data['assigned_user_id']) ? null : (int) $data['assigned_user_id'];
        $previousAssignedUserId = ($existing === null || $existing->assigned_user_id === null)
            ? null
            : (int) $existing->assigned_user_id;
        if ($assignedUserId !== null && $assignedUserId !== $previousAssignedUserId) {
            // 本仓 users 表没有 status 列（上游 admins 才有），只验存在性 + 同租户
            $assigneeExists = User::query()
                ->whereKey($assignedUserId)
                ->where('tenant_id', (int) $actor->tenant_id)
                ->exists();
            if (! $assigneeExists) {
                throw new DomainException((string) __('admin.manual_publications.error.assignee_inactive'));
            }
            // 评审项 7：走权限矩阵而非硬编码 role 判断，manual_publish:assign 在矩阵中仅 admin
            if (! Permissions::allows((string) $actor->role, 'manual_publish:assign') && $assignedUserId !== $actor->id) {
                throw new DomainException((string) __('admin.manual_publications.error.assign_forbidden'));
            }
        }

        $content = trim((string) $data['content']);
        if ($content === '' || mb_strlen($content) > ManualPublication::maxContentCharactersForType($type)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_content'));
        }
        $targetUrl = trim((string) ($data['target_url'] ?? '')) ?: null;
        $targetContext = trim((string) ($data['target_context'] ?? '')) ?: null;
        if ($targetUrl !== null && ! $this->isHttpUrl($targetUrl)) {
            throw new DomainException((string) __('admin.manual_publications.error.invalid_target_url'));
        }
        if ($type === ManualPublication::TYPE_COMMENT && ($targetUrl === null || $targetContext === null)) {
            throw new DomainException((string) __('admin.manual_publications.error.comment_target_required'));
        }
        $targetUrlHash = $this->duplicateDetector->targetUrlHash($targetUrl);
        $contentFingerprint = $this->duplicateDetector->fingerprint($content);
        $riskResult = $this->riskScanner->scan($this->riskScanPayload(
            $article?->title,
            $targetContext,
            $content,
            $persona->disclosure_text,
        ));

        $sourceSnapshot = $existing?->source_snapshot;
        if ($article instanceof Article && ((int) $existing?->article_id !== (int) $article->getKey() || ! is_array($sourceSnapshot))) {
            // 本仓 Article 无 excerpt/content 列：对应 summary / body_markdown（§一-3 同类适配）
            $sourceSnapshot = [
                'article_id' => (int) $article->getKey(),
                'article_version' => (int) $article->version,
                'project_id' => (int) $article->project_id,
                'title' => (string) $article->title,
                'slug' => (string) ($article->slug ?? ''),
                'excerpt' => (string) ($article->summary ?? ''),
                'content' => (string) ($article->body_markdown ?? ''),
                'status' => (string) $article->status,
                'snapshotted_at' => now()->toAtomString(),
            ];
        }

        return [
            'type' => $type,
            'article_id' => $article?->getKey(),
            'persona_id' => $persona->getKey(),
            'account_id' => $account?->getKey(),
            'assigned_user_id' => $assignedUserId,
            'platform' => $platform,
            'custom_platform' => $customPlatform,
            'target_url' => $targetUrl,
            'target_url_hash' => $targetUrlHash,
            'target_context' => $targetContext,
            'content' => $content,
            'content_fingerprint' => $contentFingerprint,
            'source_snapshot' => $type === ManualPublication::TYPE_POST ? $sourceSnapshot : null,
            'identity_snapshot' => $this->identitySnapshot($persona, $account),
            'disclosure_snapshot' => trim((string) ($persona->disclosure_text ?? '')) ?: null,
            'risk_status' => (string) Arr::get($riskResult, 'status', 'clean'),
            'risk_result' => $riskResult,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ];
    }

    /**
     * 风险扫描入参。建单与「进 ready 前重扫」必须用同一份字段映射，
     * 否则同一条内容两次扫出的结果会不一致。
     *
     * @return array<string, string>
     */
    private function riskScanPayload(?string $title, ?string $targetContext, string $content, ?string $disclosure): array
    {
        return [
            'title' => (string) ($title ?? ''),
            'excerpt' => (string) ($targetContext ?? ''),
            'content' => $content,
            'keywords' => '',
            'meta_description' => (string) ($disclosure ?? ''),
        ];
    }

    /** 按当前词库重扫并落库（不改 revision，见 transition() 里的说明）。 */
    private function refreshRiskStatus(ManualPublication $publication): void
    {
        $fresh = $this->riskScanner->scan($this->riskScanPayload(
            (string) Arr::get((array) $publication->source_snapshot, 'title', ''),
            $publication->target_context,
            (string) $publication->content,
            $publication->disclosure_snapshot,
        ));

        $publication->forceFill([
            'risk_status' => (string) Arr::get($fresh, 'status', 'clean'),
            'risk_result' => $fresh,
        ])->save();
    }

    /**
     * 拦截级敏感词闸门（2026-08-30 拍板：风险扫描要真的拦得住）。
     *
     * 此前 risk_status 只在建单时写进库，全仓没有任何地方读它——扫出 blocked
     * 的单子照样能一路推到已完成，等于扫了个寂寞。
     *
     * 闸门只卡 ready 这一道：状态机里 draft 出去只有 ready 一条路
     * （ManualPublication::allowedNextStatuses），失败/跳过/取消重开也只能回到
     * ready，所以卡住 ready 等于卡住全部对外发布路径，不必逐个状态设卡。
     * 故意不卡建单：草稿要留得住，作者得先看见命中了哪个词才改得动。
     *
     * @param  array<string, mixed>|null  $riskResult
     */
    private function assertRiskNotBlocked(string $riskStatus, ?array $riskResult, ?int $articleId = null): void
    {
        if ($riskStatus !== 'blocked') {
            return;
        }

        // 命中先入队，等事务结束后再落库（见 withSensitiveWordHitFlush 的说明）。
        $this->queueSensitiveWordHits($riskResult, $articleId);

        throw new DomainException($this->blockedMessage($riskResult));
    }

    /**
     * 拦截提示文案（执行书 T10）。
     *
     * | 命中的是       | 文案 |
     * |---------------|------|
     * | 租户自己加的词 | 照旧点名（词是他自己加的，本来就知道） |
     * | 平台基线词     | **不点名**，只给 PLATFORM_BLOCK_NOTICE 那一句 |
     * | 两者都有       | 只列租户词，末尾附上那一句 |
     *
     * 为什么平台词不能点名：作者试几次就能把红线清单反推出来，
     * 等于一次一个词地公开发布这份表（老板第 5 条）。
     * 拿不到命中明细时也走「不点名」这一支——宁可少说，不可误泄。
     *
     * @param  array<string, mixed>|null  $riskResult
     */
    private function blockedMessage(?array $riskResult): string
    {
        $tenantWords = $this->blockedTenantWords($riskResult);

        if ($tenantWords === []) {
            return ArticleRiskScanner::PLATFORM_BLOCK_NOTICE;
        }

        $message = (string) __('admin.manual_publications.error.risk_blocked', [
            'words' => implode('、', $tenantWords),
        ]);

        if ($this->hasBlockedPlatformMatch($riskResult)) {
            $message .= ArticleRiskScanner::PLATFORM_BLOCK_NOTICE;
        }

        return $message;
    }

    /**
     * 只挑出**租户自己加的** blocked 词，用于点名。
     * warning 级不参与拦截，混进来会误导；平台词一律不进这个列表。
     *
     * @param  array<string, mixed>|null  $riskResult
     * @return array<int, string>
     */
    private function blockedTenantWords(?array $riskResult): array
    {
        $words = [];
        foreach ($this->blockedMatches($riskResult) as $match) {
            if (($match['origin'] ?? ArticleRiskScanner::ORIGIN_TENANT) !== ArticleRiskScanner::ORIGIN_TENANT) {
                continue;
            }
            $words[] = (string) ($match['word'] ?? '');
        }

        return array_values(array_unique(array_filter($words, static fn (string $w): bool => $w !== '')));
    }

    /** @param array<string, mixed>|null $riskResult */
    private function hasBlockedPlatformMatch(?array $riskResult): bool
    {
        foreach ($this->blockedMatches($riskResult) as $match) {
            if (($match['origin'] ?? null) === ArticleRiskScanner::ORIGIN_PLATFORM) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $riskResult
     * @return array<int, array<string, mixed>>
     */
    private function blockedMatches(?array $riskResult): array
    {
        $matches = Arr::get($riskResult ?? [], 'matches', []);

        return array_values(array_filter(
            is_array($matches) ? $matches : [],
            static fn ($match): bool => is_array($match) && ($match['severity'] ?? null) === 'blocked',
        ));
    }

    /** @param array<string, mixed> $attributes */
    private function ensureReadyRequirements(array $attributes): void
    {
        if (($attributes['status'] ?? null) !== ManualPublication::STATUS_READY) {
            return;
        }
        if (empty($attributes['assigned_user_id'])) {
            throw new DomainException((string) __('admin.manual_publications.error.ready_requires_assignee'));
        }
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /** @return array<string, mixed> */
    private function identitySnapshot(ManualPublicationPersona $persona, ?ManualPublicationAccount $account): array
    {
        return [
            'persona' => [
                'id' => (int) $persona->getKey(),
                'name' => (string) $persona->name,
                'tone' => $persona->tone,
                'domain' => $persona->domain,
            ],
            'account' => $account instanceof ManualPublicationAccount ? [
                'id' => (int) $account->getKey(),
                'account_name' => (string) $account->account_name,
                'platform' => (string) $account->platform,
                'custom_platform' => $account->custom_platform,
                'profile_url' => $account->profile_url,
            ] : null,
            'snapshotted_at' => now()->toAtomString(),
        ];
    }

    private function recordTransition(
        ManualPublication $publication,
        ?string $fromStatus,
        string $toStatus,
        ?User $actor = null,
        ?string $completionUrl = null,
        ?string $resultNote = null,
        ?Carbon $createdAt = null,
    ): void {
        // 审计行归属以发布单自身的 tenant_id 为准（不依赖 TenantContext，
        // 兼容队列/worker 等无租户上下文的调用场景）
        ManualPublicationTransition::query()->create([
            'tenant_id' => $publication->tenant_id,
            'manual_publication_id' => $publication->getKey(),
            'changed_by_user_id' => $actor?->getKey(),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'completion_url' => $completionUrl,
            'result_note' => $resultNote,
            'created_at' => $createdAt ?? now(),
        ]);
    }
}
