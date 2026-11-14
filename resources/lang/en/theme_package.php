<?php

/*
|--------------------------------------------------------------------------
| 主题包生成 · 文案
|--------------------------------------------------------------------------
|
| 关于这个文件放在 resources/lang/en/ 下却写着中文：
|   ① 目录：本仓 resources/lang/ 已存在，Laravel 的 lang_path() 优先取它，
|      base_path('lang') 那份永远读不到（放错目录 = 所有 __() 原样吐出 key）。
|      本仓既有 admin.php / messages.php 也都在 resources/lang/{en,zh-CN}/。
|   ② 语种：config/app.php:81 的 locale 是 en（Laravel 默认值，项目没改过），
|      fallback 也是 en，所以只有 en/ 下的文件会被 __() 读到。
|      而面板本身是中文的，故这里直接提供中文文案，不去动全项目的 locale。
|
| 关于 key 前缀：
|   上游 GEOFlow 是 __('admin.theme_replication.*')，本仓没有 admin 后台这个概念，
|   改成 __('theme_package.*')，与功能名「主题包生成」对齐。
|
| 关于文案口径（老板 09-01 拍板，见执行书 §〇-3）：
|   这个功能不接 AI，产出的是同一套 Blade 骨架 + 换配色换字体，
|   复刻不出参考站的版式。所以界面上不许出现「复制 / 克隆 / 复刻某站点」，
|   统一叫「主题包生成」，功能说明是「读取参考站点的配色与字体，生成一套可下载的主题包」。
|
*/

return [
    'progress' => [
        'step' => [
            'created' => '创建任务',
            'queued' => '进入队列',
            'fetching' => '抓取页面',
            'extracting' => '抽取配色与字体',
            'iterating' => '反馈迭代',
            'analyzing' => '生成蓝图',
            'generating' => '写入草稿',
            'scanning' => '安全扫描',
            'ready' => '预览就绪',
        ],
        'step_desc' => [
            'created' => '记录主题包名称、标识和三个参考页面。',
            'queued' => '等待队列 worker 接管生成任务。',
            'fetching' => '访问参考页面并收集可用 HTML 与 CSS 摘要。',
            'extracting' => '从 CSS 里读取页面使用的配色与字体。',
            'iterating' => '读取反馈，准备生成下一版草稿。',
            'analyzing' => '综合参考页面的配色与字体，生成主题蓝图。',
            'generating' => '把蓝图写入隔离草稿 Blade 与静态资源目录。',
            'scanning' => '检查危险 Blade、外链脚本、远程图片和不合规资源。',
            'ready' => '草稿通过检查，可以预览、继续迭代或下载主题包。',
        ],
    ],

    'status' => [
        'queued' => '已排队',
        'fetching' => '抓取中',
        'extracting' => '抽取中',
        'analyzing' => '生成中',
        'generating' => '写入中',
        'scanning' => '扫描中',
        'iterating' => '迭代中',
        'ready' => '待确认',
        'published' => '主题包已生成',
        'archived' => '已归档',
        'failed' => '失败',
    ],

    'failure' => [
        'fetch_title' => '参考页面抓取失败',
        'fetch_desc' => '通常是 URL 无法访问、目标站拦截、页面过大或返回非 200 状态。',
        'fetch_actions' => [
            '确认三个 URL 可公开访问',
            '避免使用登录后页面、内网地址或跳转链过长的页面',
            '必要时换成更稳定的参考页面后重新排队',
        ],
        // 上游这里叫 ai_*，本单不接 AI（蓝图是纯 PHP 拼的），改成 blueprint_*
        'blueprint_title' => '蓝图生成失败',
        'blueprint_desc' => '通常是参考页面结构过于特殊、抽取结果为空或样式信息不足。',
        'blueprint_actions' => [
            '确认三个参考页面能正常访问且内容完整',
            '换用配色与字体更明确的参考页面',
            '重新排队重试',
        ],
        'file_title' => '草稿文件生成失败',
        'file_desc' => '通常是本地存储目录不可写、草稿源文件丢失或主题包创建异常。',
        'file_actions' => [
            '检查 storage 目录权限',
            '确认本地磁盘空间充足',
            '重新排队生成草稿文件',
        ],
        'scan_title' => '安全扫描未通过',
        'scan_desc' => '生成文件包含外链脚本、远程图片、危险 Blade 或不允许的请求。',
        'scan_actions' => [
            '查看日志中的违规文件和规则',
            '调整反馈说明，要求移除外部资源和危险代码',
            '重新生成草稿后再下载主题包',
        ],
        'unknown_title' => '任务失败原因待确认',
        'unknown_desc' => '当前错误无法自动归类，需要结合日志和服务器错误信息排查。',
        'unknown_actions' => [
            '查看最近任务日志',
            '确认队列 worker 正常运行',
            '修正配置后重新排队',
        ],
    ],

    'preview' => [
        'home' => '首页预览',
        'category' => '列表页预览',
        'article' => '详情页预览',
        'sample_category' => '示例分类',
        'sample_category_desc' => '用于模板预览的示例分类说明。',
        'sample_article' => '示例文章标题',
        'sample_excerpt' => '这是一段用于模板预览的文章摘要。',
        'sample_section' => '示例章节',
        'sample_body' => '这里会展示正文渲染后的排版效果。',
    ],

    'safe_preview' => [
        'notice' => '安全预览：只应用生成的配色与字体（theme.css），不执行生成的 Blade 与 JavaScript；示例文字为固定占位内容。',
        'home_title' => '清晰引导内容发现的首页结构',
        'home_description' => '这个可信样例使用固定占位内容展示信息层级、留白和卡片节奏。',
        'home_card_one_title' => '重点内容',
        'home_card_one_description' => '醒目的首要模块用于介绍当前最重要的主题。',
        'home_card_two_title' => '最新更新',
        'home_card_two_description' => '紧凑列表帮助读者快速浏览新信息。',
        'home_card_three_title' => '探索主题',
        'home_card_three_description' => '简洁的内容分组引导读者发现相关内容。',
        'category_title' => '聚焦主题并保持稳定阅读节奏',
        'category_description' => '这个可信样例通过固定标题和摘要展示列表页信息层级。',
        'category_card_one_title' => '分类介绍',
        'category_card_one_description' => '简短说明帮助读者理解分类范围和阅读预期。',
        'category_card_two_title' => '重点文章',
        'category_card_two_description' => '首篇内容获得清晰强调，过程中不会运行动态代码。',
        'category_card_three_title' => '相关文章',
        'category_card_three_description' => '一致的卡片结构提升列表浏览效率。',
        'article_title' => '适合长内容阅读的舒适结构',
        'article_description' => '这个可信样例使用固定内容展示标题、摘要和章节间距。',
        'article_card_one_title' => '文章摘要',
        'article_card_one_description' => '简明介绍帮助读者快速理解主题。',
        'article_card_two_title' => '正文段落',
        'article_card_two_description' => '适中的行长和留白支持专注阅读。',
        'article_card_three_title' => '相关背景',
        'article_card_three_description' => '结尾模块展示人工主题可以扩展页面的方向。',
    ],

    'validation' => [
        'theme_id_invalid' => '主题标识格式不正确，请使用英文、数字、短横线或下划线',
        'theme_id_exists' => '这个主题标识已经存在，请换一个名称',
        'url_required' => '请填写参考页面 URL',
        'url_invalid' => '参考页面 URL 格式不正确',
        'url_scheme' => '参考页面只支持 http 或 https',
        'url_private' => '不允许使用内网、localhost、私有 IP 或保留地址作为参考页面',
    ],

    'error' => [
        'fetch_failed' => '抓取参考页面失败，HTTP :status',
        'html_too_large' => '参考页面体积过大',
        'compliance_failed' => '生成文件未通过安全扫描',
        'compliance_violation' => ':path 触发安全规则 :rule',
        'job_failed' => '主题包生成任务执行失败',
        'preview_unavailable' => '当前任务还没有可用预览',
        'no_draft_version' => '没有可用于生成主题包的草稿版本',
        'source_missing' => '草稿文件不存在，请重新生成',
        'package_create_failed' => '主题包创建失败',
        'invalid_package_path' => '主题包包含不安全的文件路径',
        'theme_target_exists' => '主题包目标位置已存在文件，请更换主题标识',
        'quota_exceeded' => '本租户主题包存储已达上限（已用 :used / 上限 :limit），请先删除旧任务再新建',
        'not_your_tenant' => '该主题包不属于当前租户，无权访问',
        'package_not_ready' => '主题包尚未生成完成，暂不可下载',
        'iterate_only_ready' => '仅「待确认 / 主题包已生成」状态支持反馈迭代',
        // 内部装配问题（出站安全链未按契约注入等）。绝不能复用 url_private —— 那会
        // 把我们自己的 bug 说成「你填的是内网地址」，让客户白折腾（单 8 评审同款教训）。
        'outbound_misconfigured' => '出站请求组件配置异常，已记录日志，请联系平台处理（非参考站点问题）',
        'create_failed' => '主题包任务创建失败，请稍后重试',
        'artifacts_reclaimed' => '该主题包的生成文件已按保留期回收，请点「重新生成」再下载',
    ],

    'message' => [
        'migration_required_title' => '主题包数据表尚未初始化',
        'migration_required' => '请先执行数据库迁移：php artisan migrate --force，然后刷新本页重试。',
        'created' => '主题包生成任务已创建',
        'retried' => '任务已重新排队',
        'retry_unavailable' => '当前任务状态不支持重新排队',
        'iteration_queued' => '反馈已提交，系统正在生成下一版草稿',
        'iteration_running' => '系统正在生成下一版，请稍后刷新查看。',
        'iteration_unavailable' => '当前状态暂不支持反馈迭代',
        'published' => '主题包已就绪，可下载后自行安装',
        'package_ready' => '主题包已生成，可下载后自行安装',
        'lifecycle_unavailable' => '当前任务还在处理中，暂不支持该操作。',
    ],

    'log' => [
        'created' => '主题包生成任务已创建',
        'queued' => '任务已进入生成队列',
        'retry' => '任务已重新排队等待处理',
        'iteration_queued' => '反馈迭代已进入队列',
        'iterating' => '开始根据反馈生成下一版',
        'fetching' => '开始抓取参考页面',
        'extracting' => '开始抽取页面配色与字体',
        'analyzing' => '开始生成主题蓝图',
        'generating' => '开始写入草稿主题文件',
        'scanning' => '开始执行生成文件安全扫描',
        'ready' => '草稿主题已生成并通过安全扫描',
        'published' => '主题包已生成并等待下载',
        'package_created' => '主题包已生成',
        'pruned' => '生成文件与主题包已按保留期回收（库行保留，可重新生成）',
    ],
];
