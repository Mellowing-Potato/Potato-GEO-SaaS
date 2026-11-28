<?php

namespace App\Services\Distribution;

use App\Models\PublishingChannel;
use App\Models\Project;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * B2.4 项目绑定分发渠道范围
 *
 * 通过 project_publishing_channels 透视表管理项目与渠道的绑定关系。
 * 未绑定的项目不外发。租户拥有渠道不代表同租户的每个项目都选择了该渠道。
 */
class TaskDistributionChannelSelector
{
    /**
     * Get the publishing channels available for a given project.
     *
     * If the project has bound channels, only those are returned.
     * If no channels are bound, no channels are returned.
     *
     * @param  int  $projectId
     * @return \Illuminate\Support\Collection<PublishingChannel>
     */
    public function channelsForProject(int $projectId): \Illuminate\Support\Collection
    {
        $boundChannelIds = $this->boundChannelIds($projectId);

        $query = PublishingChannel::query()->where('is_active', true)
            ->whereIn('type', [PublishingChannel::TYPE_WORDPRESS_REST, PublishingChannel::TYPE_HTTP_WEBHOOK]);

        $query->whereIn('id', $boundChannelIds);

        return $query->get();
    }

    /**
     * Bind channels to a project (replaces existing bindings).
     *
     * @param  int  $projectId
     * @param  array<int>  $channelIds
     * @return int Number of bindings created
     */
    public function bindChannels(int $projectId, array $channelIds): int
    {
        $tenantId = TenantContext::id();

        return DB::transaction(function () use ($projectId, $channelIds, $tenantId) {
            Project::query()->lockForUpdate()->findOrFail($projectId);
            DB::table('project_publishing_channels')
                ->where('project_id', $projectId)
                ->where('tenant_id', $tenantId)
                ->delete();

            $inserted = 0;
            $now = now();
            foreach (array_unique($channelIds) as $channelId) {
                DB::table('project_publishing_channels')->insert([
                    'project_id' => $projectId,
                    'publishing_channel_id' => (int) $channelId,
                    'tenant_id' => $tenantId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $inserted++;
            }

            return $inserted;
        });
    }

    /**
     * Unbind all channels from a project (disables automatic distribution).
     */
    public function unbindAll(int $projectId): int
    {
        $tenantId = TenantContext::id();

        return DB::table('project_publishing_channels')
            ->where('project_id', $projectId)
            ->where('tenant_id', $tenantId)
            ->delete();
    }

    /**
     * Get bound channel IDs for a project.
     *
     * @return \Illuminate\Support\Collection<int>
     */
    public function boundChannelIds(int $projectId): \Illuminate\Support\Collection
    {
        $tenantId = TenantContext::id();

        return DB::table('project_publishing_channels')
            ->where('project_id', $projectId)
            ->where('tenant_id', $tenantId)
            ->pluck('publishing_channel_id');
    }

    /**
     * Check whether a project has explicit channel bindings.
     */
    public function hasBindings(int $projectId): bool
    {
        return $this->boundChannelIds($projectId)->isNotEmpty();
    }
}
