<?php

namespace App\Support;

/**
 * Static navigation only; feature gates are evaluated by the Blade renderer.
 *
 * 外链绝不能带 data-view-target：SPA 点击处理会 preventDefault，吞掉跳转并让主区变白。
 * 菜单门控必须与路由使用同一个 config('geo.*_enabled')；关闭时不渲染，避免留下 404 链接。
 * 团队管理因 2026-08-31“一人一租户”决定冻结，入口保持 hidden，冻结视图名单保持不变。
 * 复活团队入口须有产品决定，再删除 hidden 并清空 FROZEN_WORKSPACE_VIEWS，不能只放开一边。
 * API 令牌入口按 2026-09-22 口径撤下：租户只展示积分，凭据签发/吊销由运营处理。
 * 复活令牌入口须先有明确产品决定，并同步修改凭据边界测试，不能随导航重排带回。
 */
final class WorkspaceNavigation
{
    public static function menus(): array
    {
        return [
            'work' => [
                ['key' => 'projects', 'label' => '', 'items' => [
                    self::entry('项目工作台', 'projects', view: 'projects'),
                    self::entry('项目总览', 'projects', view: 'analytics'),
                ]],
                ['key' => 'analysis', 'label' => '分析诊断', 'items' => [
                    self::entry('诊断与路线', 'analysis', view: 'skill'),
                    self::entry('历史报告', 'analysis', view: 'asset'),
                ]],
                ['key' => 'content', 'label' => '内容', 'items' => [
                    self::entry('文章', 'content', view: 'content-library'),
                    self::entry('素材', 'content', view: 'materials'),
                    self::entry('企业知识库', 'content', view: 'knowledge'),
                    self::entry('URL 导入', 'content', view: 'url-imports'),
                ]],
                ['key' => 'publishing', 'label' => '发布', 'items' => [
                    self::entry('发布管理', 'publishing', view: 'publishing'),
                    self::entry('手动发布', 'publishing', page: 'manual-publish', id: 'manualPublishMenuLink', flag: 'manual_publish_enabled', roles: ['admin', 'platform_admin', 'editor']),
                    self::entry('自动化任务', 'publishing', view: 'automation'),
                    self::entry('线索表单', 'publishing', view: 'leads', id: 'leadsMenuLink', flag: 'lead_form_enabled'),
                ]],
                ['key' => 'monitoring', 'label' => '效果监测', 'items' => [
                    self::entry('智能回答监测', 'monitoring', page: 'visibility', id: 'visibilityMenuLink', flag: 'visibility_panel_enabled'),
                    self::entry('AI 爬虫访问', 'monitoring', page: 'analytics', id: 'analyticsPanelMenuLink', flag: 'analytics_panel_enabled'),
                ]],
                ['key' => 'platform', 'label' => '平台管理', 'id' => 'platformAdminSection', 'hidden' => true, 'items' => [
                    self::entry('运营面板', 'platform', view: 'platform'),
                ]],
            ],
            'settings' => [
                ['key' => 'account', 'label' => '账户', 'items' => [
                    self::entry('个人设置', 'account', view: 'personal-settings'),
                    self::entry('账号安全', 'account', view: 'account-security'),
                    self::entry('模型配置', 'account', view: 'aikeys'),
                    self::entry('团队管理', 'account', view: 'team', id: 'teamMenuLink', hidden: true),
                ]],
                ['key' => 'content-control', 'label' => '内容管控', 'items' => [
                    self::entry('用词规则', 'content-control', view: 'sensitive-words'),
                    self::entry('生成偏好', 'content-control', view: 'skill-prompts'),
                ]],
                ['key' => 'usage', 'label' => '用量', 'items' => [
                    self::entry('用量明细', 'usage', view: 'usage'),
                ]],
            ],
        ];
    }

    private static function entry(string $label, string $group, ?string $view = null, ?string $page = null, ?string $id = null, ?string $flag = null, array $roles = [], bool $hidden = false): array
    {
        return compact('label', 'view', 'page', 'id', 'flag', 'roles', 'hidden', 'group');
    }
}
