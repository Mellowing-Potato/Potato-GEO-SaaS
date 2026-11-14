<?php

namespace Tests\Unit;

use App\Services\AI\MockAiProvider;
use App\Services\Content\HtmlToMarkdownConverter;
use App\Services\Knowledge\DocumentIngestionService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ReadableHtmlExtractionTest extends TestCase
{
    private const LANDING_PAGE = '<html><body><main>'
        . '<h1>言及服务</h1>'
        . '<article><h2>第一张卡片</h2><p>卡片一正文。</p></article>'
        . '<section><h2>服务与交付</h2><p>梳理问题，建设内容，持续复盘。</p></section>'
        . '<article><h2>第二张卡片</h2><p>卡片二正文。</p></article>'
        . '</main><footer>页脚文字</footer></body></html>';

    public function test_url_import_keeps_content_after_the_first_article_card(): void
    {
        $markdown = (new HtmlToMarkdownConverter())->convert(self::LANDING_PAGE);

        $this->assertStringContainsString('第一张卡片', $markdown);
        $this->assertStringContainsString('服务与交付', $markdown);
        $this->assertStringContainsString('第二张卡片', $markdown);
    }

    public function test_knowledge_import_keeps_content_after_the_first_article_card(): void
    {
        $service = new DocumentIngestionService(new MockAiProvider());
        $extract = new ReflectionMethod($service, 'extractReadableText');
        $text = $extract->invoke($service, self::LANDING_PAGE);

        $this->assertStringContainsString('第一张卡片', $text);
        $this->assertStringContainsString('服务与交付', $text);
        $this->assertStringContainsString('第二张卡片', $text);
        $this->assertStringContainsString('服务与交付 梳理问题', $text);
        $this->assertStringNotContainsString('页脚文字', $text);
    }

    public function test_hidden_download_fallbacks_are_excluded_from_knowledge_and_url_imports(): void
    {
        $html = '<html><body><main><section><h1>下载客户端</h1>'
            .'<p>Windows 和 macOS v0.1.0 现已提供</p>'
            .'<button hidden>安装包准备中</button>'
            .'<div aria-hidden="true"><span>macOS 开发中</span></div>'
            .'<button style="display: none">Apple 芯片版准备中</button>'
            .'<span style="visibility: hidden">Intel 芯片版准备中</span>'
            .'<a href="/download">前往下载</a></section></main></body></html>';

        $service = new DocumentIngestionService(new MockAiProvider());
        $extract = new ReflectionMethod($service, 'extractReadableText');
        $knowledgeText = $extract->invoke($service, $html);
        $importText = (new HtmlToMarkdownConverter())->convert($html);

        foreach ([$knowledgeText, $importText] as $text) {
            $this->assertStringContainsString('现已提供', $text);
            $this->assertStringContainsString('前往下载', $text);
            $this->assertStringNotContainsString('安装包准备中', $text);
            $this->assertStringNotContainsString('macOS 开发中', $text);
            $this->assertStringNotContainsString('Apple 芯片版准备中', $text);
            $this->assertStringNotContainsString('Intel 芯片版准备中', $text);
        }
    }
}
