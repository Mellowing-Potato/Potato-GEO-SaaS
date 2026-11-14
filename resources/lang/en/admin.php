<?php

/**
 * 手动发布模块（单 7）翻译。
 * 上游 admin.php 含庞大后台翻译树；本仓此前无 lang 目录，此处补齐 manual_publications 子树，
 * 使 __('admin.manual_publications.*') 不再回退为裸 key。
 */
return [
    'manual_publications' => [
        'page_title' => 'Manual Publishing',
        'create_title' => 'New Manual Publication',
        'edit_title' => 'Edit Manual Publication #:id',
        'detail_title' => 'Manual Publication #:id',

        'error' => [
            'revision_conflict' => '该发布单已被其他人修改，请刷新后重试。',
            'invalid_transition' => '状态流转不合法。',
            'completed_immutable' => '已完成（completed）的发布单不可修改。',
            'invalid_type' => '无效的发布类型。',
            'invalid_platform' => '无效的目标平台。',
            'custom_platform_required' => '使用自定义平台时必须填写平台名称。',
            'persona_inactive' => '所选人设不存在或已停用。',
            'account_mismatch' => '账号与所选人设/平台不匹配。',
            'article_not_approved' => '关联文章未通过审核，无法发布。',
            'article_required' => '请先选择文章。',
            'assignee_inactive' => '指派的用户不存在或未激活。',
            'invalid_content' => 'Content is empty or exceeds the 20,000-character article / 2,000-character comment limit.',
            'invalid_target_url' => '目标链接格式非法（需 http/https）。',
            'comment_target_required' => '评论类型必须填写目标链接与上下文。',
            'ready_requires_assignee' => '进入待发（ready）必须指定执行人。',
            'risk_blocked' => '内容命中拦截级敏感词（:words），请修改内容后再置为待发。',
            'completion_url_required' => 'A public publication URL is required; a Zhihu draft edit URL is not publication evidence.',
            'forbidden' => '无权操作该发布单。',
            'assign_forbidden' => '仅管理员可把发布单指派给他人。',
        ],

        'message' => [
            'created' => '发布单已创建。',
            'updated' => '发布单已更新。',
            'transitioned' => '状态已更新。',
        ],

        'status' => [
            'draft' => '草稿',
            'ready' => '待发',
            'in_progress' => '进行中',
            'completed' => '已完成',
            'failed' => '失败',
            'skipped' => '跳过',
            'cancelled' => '已取消',
        ],

        'type' => [
            'post' => '文章',
            'comment' => '评论',
        ],

        'platform' => [
            'zhihu' => '知乎',
            'xiaohongshu' => '小红书',
            'weibo' => '微博',
            'wechat' => '微信',
            'douyin' => '抖音',
            'bilibili' => 'B站',
            'reddit' => 'Reddit',
            'x' => 'X',
            'linkedin' => 'LinkedIn',
            'custom' => '自定义',
        ],

        'risk' => [
            'clean' => '干净',
            'warning' => '警告',
            'blocked' => '拦截',
        ],
    ],
];
