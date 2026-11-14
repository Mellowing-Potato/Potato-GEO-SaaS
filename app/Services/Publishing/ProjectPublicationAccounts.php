<?php

namespace App\Services\Publishing;

use App\Models\Article;
use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProjectPublicationAccounts
{
    public function boundIds(int $projectId): Collection
    {
        Project::query()->findOrFail($projectId);

        return DB::table('project_publication_accounts')
            ->where('tenant_id', TenantContext::id())->where('project_id', $projectId)
            ->pluck('account_id')->map(fn ($id): int => (int) $id);
    }

    public function accounts(): Collection
    {
        return ManualPublicationAccount::query()->with('persona:id,name,is_active')
            ->orderBy('platform')->orderBy('account_name')->get();
    }

    public function payload(ManualPublicationAccount $account): array
    {
        $active = $account->is_active && $account->persona?->is_active;

        return [
            'id' => $account->id,
            'account_name' => $account->account_name,
            'platform' => $account->platform,
            'platform_label' => PublicationPlatform::label($account->platform, $account->custom_platform),
            'persona_name' => $account->persona?->name,
            'persona_id' => $account->persona_id,
            'custom_platform' => $account->custom_platform,
            'profile_url' => $account->profile_url,
            'is_active' => (bool) $active,
            'delivery_mode' => 'manual',
            'browser_draft_available' => $active && $account->platform === 'zhihu'
                && config('geo.zhihu_draft_sync_enabled', false),
        ];
    }

    public function forProject(int $projectId): Collection
    {
        $ids = $this->boundIds($projectId);

        return $this->accounts()->whereIn('id', $ids->all())
            ->filter(fn ($account): bool => (bool) ($account->is_active && $account->persona?->is_active))
            ->map(fn ($account): array => $this->payload($account))->values();
    }

    public function bind(int $projectId, array $accountIds): void
    {
        DB::transaction(function () use ($projectId, $accountIds): void {
            Project::query()->lockForUpdate()->findOrFail($projectId);
            $ids = array_values(array_unique(array_map('intval', $accountIds)));
            $eligible = ManualPublicationAccount::query()->whereIn('id', $ids)->where('is_active', true)
                ->whereHas('persona', fn ($query) => $query->where('is_active', true))->lockForUpdate()->get()->count();
            if ($eligible !== count($ids)) {
                throw ValidationException::withMessages(['manual_account_ids' => '账号不存在、已停用，或不属于当前工作区。']);
            }
            DB::table('project_publication_accounts')->where('tenant_id', TenantContext::id())
                ->where('project_id', $projectId)->delete();
            foreach ($ids as $id) {
                DB::table('project_publication_accounts')->insert([
                    'tenant_id' => TenantContext::id(), 'project_id' => $projectId, 'account_id' => $id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function start(Article $article, int $accountId, int $version, User $actor): array
    {
        return DB::transaction(function () use ($article, $accountId, $version, $actor): array {
            // Binding changes and creation serialize on the same project lock.
            Project::query()->lockForUpdate()->findOrFail($article->project_id);
            $current = Article::query()->lockForUpdate()->findOrFail($article->id);
            if (! in_array($current->status, ['approved', 'published'], true) || $current->version !== $version) {
                throw ValidationException::withMessages(['article_version' => '文章尚未审核通过或版本已变化，请重新打开文章。']);
            }
            if (! $this->boundIds($current->project_id)->contains($accountId)) {
                throw ValidationException::withMessages(['account_id' => '请先将这个平台账号绑定到当前项目。']);
            }
            $account = ManualPublicationAccount::query()->where('is_active', true)
                ->whereHas('persona', fn ($query) => $query->where('is_active', true))->findOrFail($accountId);
            $existing = ManualPublication::query()->where('article_id', $current->id)->where('account_id', $accountId)
                ->whereNotIn('status', ['cancelled', 'skipped'])->latest('id')->get()
                ->first(fn ($publication): bool => (int) data_get($publication->source_snapshot, 'article_version') === $version);
            if ($existing) {
                return ['publication' => $existing, 'created' => false];
            }
            $publication = app(ManualPublicationService::class)->create([
                'type' => 'post', 'article_id' => $current->id, 'persona_id' => $account->persona_id,
                'account_id' => $account->id, 'platform' => $account->platform,
                'custom_platform' => $account->custom_platform,
                'assigned_user_id' => $actor->id, 'content' => $current->body_markdown, 'status' => 'draft',
            ], $actor);

            return ['publication' => $publication, 'created' => true];
        });
    }
}
