<?php

namespace App\Services\ThemePackaging;

use App\Models\SiteThemeReplication;

/**
 * 主题包存储路径的唯一真相源
 *
 * 为什么要有这个类：
 *   上游是把 "geoflow-theme-replications/{$id}/draft/{$v}" 这种字符串硬编码散在
 *   ScaffoldWriter / PackageService / Service 三处的。多租户下路径要多插一层
 *   {tenant_id}，如果继续散着写，漏一处就是一次跨租户串档。
 *   所有路径都从这里出，清理任务、配额统计、下载端点用的是同一套，不会走偏。
 *
 * 目录结构：
 *   theme-packages/{tenant_id}/{replication_id}/draft/{version}/views|assets
 *   theme-packages/{tenant_id}/{replication_id}/packages/{theme_id}-v{n}.zip
 *
 * ⚠️ 每个 id 都过一遍 guard：tenant_id 和 replication_id 必须是正整数，
 *    所以不可能拼出 ../ 或绝对路径。
 */
class ThemePackagePaths
{
    public const ROOT = 'theme-packages';

    public function __construct(private readonly ThemeReplicationPackagePathGuard $guard) {}

    public function tenantRoot(int $tenantId): string
    {
        return self::ROOT.'/'.$this->guard->validatedTenantId($tenantId);
    }

    public function replicationRoot(int $tenantId, int $replicationId): string
    {
        return $this->tenantRoot($tenantId).'/'.$this->guard->positiveInteger($replicationId);
    }

    public function draft(int $tenantId, int $replicationId, int $version): string
    {
        return $this->replicationRoot($tenantId, $replicationId).'/draft/'.$this->guard->positiveInteger($version);
    }

    public function draftRoot(int $tenantId, int $replicationId): string
    {
        return $this->replicationRoot($tenantId, $replicationId).'/draft';
    }

    public function packages(int $tenantId, int $replicationId): string
    {
        return $this->replicationRoot($tenantId, $replicationId).'/packages';
    }

    /**
     * 下面三个 *_For() 走模型读 tenant_id —— tenant_id 来自数据库而不是用户输入，
     * 比让调用方自己传更安全（调用方传的话，传错就是串到别人目录里）。
     */
    public function replicationRootFor(SiteThemeReplication $replication): string
    {
        return $this->replicationRoot($this->tenantIdOf($replication), (int) $replication->id);
    }

    public function draftFor(SiteThemeReplication $replication, int $version): string
    {
        return $this->draft($this->tenantIdOf($replication), (int) $replication->id, $version);
    }

    public function draftRootFor(SiteThemeReplication $replication): string
    {
        return $this->draftRoot($this->tenantIdOf($replication), (int) $replication->id);
    }

    public function packagesFor(SiteThemeReplication $replication): string
    {
        return $this->packages($this->tenantIdOf($replication), (int) $replication->id);
    }

    private function tenantIdOf(SiteThemeReplication $replication): int
    {
        return $this->guard->validatedTenantId($replication->tenant_id);
    }
}
