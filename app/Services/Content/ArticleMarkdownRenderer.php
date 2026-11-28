<?php

namespace App\Services\Content;

use League\CommonMark\CommonMarkConverter;
use Throwable;

/**
 * 文章正文 Markdown 纯内容渲染服务。
 *
 * 供工作台文章预览接口将 Markdown 转为受限 HTML（剥离内嵌原始 HTML，禁用不安全协议链接），
 * 不承载任何站内公开托管页路由或租户解析逻辑。
 */
final class ArticleMarkdownRenderer
{
    private ?CommonMarkConverter $converter = null;

    public function renderMarkdown(string $markdown): string
    {
        if ($markdown === '') {
            return '';
        }

        if ($this->converter === null) {
            $this->converter = new CommonMarkConverter([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
        }

        try {
            return (string) $this->converter->convert($markdown);
        } catch (Throwable) {
            return e($markdown);
        }
    }
}
