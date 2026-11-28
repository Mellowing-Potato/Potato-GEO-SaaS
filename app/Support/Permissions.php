<?php

namespace App\Support;

/**
 * 角色权限矩阵
 *
 * 定义每个角色能执行的操作。中间件 CheckRole 会查这个矩阵来决定是否放行。
 *
 * 角色说明：
 * - platform_admin : 平台运营和自己的独立工作区；不扩大租户边界
 * - admin          : 租户管理员，能管团队 + 租户内所有操作
 * - editor         : 内容编辑，能写文章、发布、看数据
 * - member         : 普通成员，只能查看和执行分配给自己的任务
 *
 * 扩展方式：
 *   在 PERMISSIONS 数组里加新的 permission key，然后在路由中间件里引用：
 *   Route::middleware('can:publish_article')
 */
class Permissions
{
    /**
     * 所有角色定义
     */
    public const ROLES = ['admin', 'editor', 'member'];

    /**
     * 平台角色不能由租户团队管理接口分配。
     */
    public const PLATFORM_ROLES = ['platform_admin'];

    /**
     * 权限矩阵：permission => [允许的角色列表]
     *
     * admin 默认拥有所有权限，不需要在每个 permission 里重复列出。
     */
    public const MATRIX = [
        // 团队管理（仅 admin）
        'team:manage'          => ['admin'],
        'team:invite'          => ['admin'],
        'team:remove'          => ['admin'],
        'team:change_role'     => ['admin'],

        // 项目管理
        'project:create'       => ['admin', 'editor'],
        'project:delete'       => ['admin'],
        'project:view'         => ['admin', 'editor', 'member'],

        // 内容生产
        'article:create'       => ['admin', 'editor'],
        'article:edit'         => ['admin', 'editor'],
        'article:publish'      => ['admin', 'editor'],
        'article:delete'       => ['admin'],
        'article:view'         => ['admin', 'editor', 'member'],

        // 素材库管理（标题 / 关键词 / 图片 / 作者，阶段 1.1）
        'material:manage'      => ['admin', 'editor'],

        // 文章分类管理（阶段 2.1）
        'category:manage'      => ['admin', 'editor'],

        // 提示词管理台（每技能租户提示词覆盖，阶段 1.2）
        'prompt:manage'        => ['admin', 'editor'],

        // 任务自动化编排（定时任务 CRUD / 启停 / 立即运行，阶段 1.4）
        'task:manage'          => ['admin', 'editor'],

        // 企业知识库 revision 流（来源 / 草稿 / 修订 / 发布，阶段 1.5）
        'knowledge:manage'     => ['admin', 'editor'],

        // 数据看板
        'analytics:view'       => ['admin', 'editor'],
        'analytics:export'     => ['admin', 'editor'],

        // 设置
        'settings:view'        => ['admin'],
        'settings:update'      => ['admin'],

        // 分发渠道管理（渠道配置 / 密钥 / Agent 包，仅 admin —— 编辑不得新增外发渠道或读取密钥）
        'channel:manage'       => ['admin'],

        // 手动发布模块（单 7）——2026-08-28 拍板：按本仓通例「角色定能力、租户定边界、不看记录归属」，
        // 与 article:edit 同构。上游 GEOFlow 是作者自用的单人产品（建单/改单都是超管专属），
        // 那是「老板 + 发帖执行工」模型；本仓 editor 是内容团队成员，不是执行工。
        //
        // manage   : 建单 / 改单 / 流转 / 读人设账号列表（建单表单靠它填下拉框）
        'manual_publish:manage'   => ['admin', 'editor'],
        // assign   : 指派给别人（editor 只能指派给自己）
        'manual_publish:assign'   => ['admin'],
        // reopen   : 把终态单（completed 除外）重开回 ready —— 纠错 / 翻案动作
        'manual_publish:reopen'   => ['admin'],
        // settings : 人设 / 账号的「写」。全租户共用的主数据，一个人改错影响所有单，
        //            故与 channel:manage 同级收在 admin；「读」留在 manage 里，否则 editor 建不了单。
        'manual_publish:settings' => ['admin'],

        // 线索表单模块（单 8）：表单 CRUD + 提交记录管理 + 转化统计。
        // admin 天然拥有（Permissions::allows 里 role==='admin' 直接 true），
        // 这里实际只约束 editor 与 member（editor 可管，member 不可）。
        'lead:manage' => ['admin', 'editor'],

        // 平台运营（不进入租户 middleware）
        'platform:operations'  => ['platform_admin'],
    ];

    /**
     * 检查某角色是否有某权限
     */
    public static function allows(string $role, string $permission): bool
    {
        if (str_starts_with($permission, 'platform:')) {
            return in_array($role, self::PLATFORM_ROLES, true)
                && in_array($role, self::MATRIX[$permission] ?? [], true);
        }

        // admin 拥有所有租户内权限
        if (in_array($role, ['admin', 'platform_admin'], true)) {
            return true;
        }

        $allowedRoles = self::MATRIX[$permission] ?? [];

        return in_array($role, $allowedRoles, true);
    }

    /**
     * 检查某角色是否满足一组权限（全部满足才返回 true）
     */
    public static function allowsAll(string $role, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! self::allows($role, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 获取某角色拥有的所有权限列表
     */
    public static function getPermissionsForRole(string $role): array
    {
        return array_values(array_filter(array_keys(self::MATRIX), fn (string $permission): bool => self::allows($role, $permission)));
    }
}
