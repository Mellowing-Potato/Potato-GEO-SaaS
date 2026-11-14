<?php

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * 轻量 HTML → Markdown 转换器（阶段 1.3，对标 GEOFlow UrlImportProcessingService）
 *
 * 不引入第三方库：抽取正文主区域（<main> → [role=main] → <article> → <body>）后，
 * 按常见标签递归转换为 Markdown，用于把外部文章 URL 抓取后清洗为可编辑的 Markdown 草稿。
 */
class HtmlToMarkdownConverter
{
    /**
     * 将 HTML 转换为 Markdown。
     *
     * @param  string  $html     原始 HTML
     * @param  string  $baseUrl  用于把相对链接/图片解析为绝对地址（可空）
     */
    public function convert(string $html, string $baseUrl = ''): string
    {
        $doc = $this->loadDocument($html);
        $root = $this->selectMainRegion($doc) ?? $doc->documentElement;

        if ($baseUrl !== '') {
            $this->absolutize($root, $baseUrl);
        }

        $markdown = $this->walk($root);

        return $this->cleanup($markdown);
    }

    private function loadDocument(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        $source = '<meta charset="utf-8">' . mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        // Let libxml create the normal html/body tree. NOIMPLIED makes a leading
        // meta node the document root for complete pages on some libxml builds,
        // leaving the later article outside the traversed tree.
        @$doc->loadHTML($source, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);

        return $doc;
    }

    private function selectMainRegion(DOMDocument $doc): ?DOMElement
    {
        // Landing pages often contain several small <article> cards inside one
        // <main>. Selecting the first card discards the rest of the page.
        foreach (['main'] as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            if ($nodes->length > 0) {
                return $nodes->item(0);
            }
        }

        $xpath = new DOMXPath($doc);
        $roleMain = $xpath->query('//*[@role="main"]');
        if ($roleMain && $roleMain->length > 0) {
            return $roleMain->item(0);
        }

        $articles = $doc->getElementsByTagName('article');
        if ($articles->length > 0) {
            return $articles->item(0);
        }

        $body = $doc->getElementsByTagName('body');
        if ($body->length > 0) {
            return $body->item(0);
        }

        return null;
    }

    private function absolutize(DOMElement $root, string $baseUrl): void
    {
        foreach (['href', 'src'] as $attr) {
            foreach ($root->getElementsByTagName('*') as $el) {
                if (! $el instanceof DOMElement) {
                    continue;
                }
                $value = $el->getAttribute($attr);
                if ($value !== '' && ! preg_match('~^(https?:|mailto:|tel:|#|data:)~i', $value)) {
                    $absolute = $this->resolveUrl($baseUrl, $value);
                    if ($absolute !== '') {
                        $el->setAttribute($attr, $absolute);
                    }
                }
            }
        }
    }

    private function resolveUrl(string $base, string $rel): string
    {
        $baseParts = parse_url($base);
        if (empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return $rel;
        }

        if (str_starts_with($rel, '//')) {
            return $baseParts['scheme'] . ':' . $rel;
        }
        if (str_starts_with($rel, '/')) {
            $path = $rel;
        } else {
            $dir = isset($baseParts['path']) ? dirname($baseParts['path']) : '/';
            $path = rtrim($dir, '/') . '/' . $rel;
        }

        return $baseParts['scheme'] . '://' . $baseParts['host']
            . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '')
            . $this->normalizePath($path);
    }

    private function normalizePath(string $path): string
    {
        $segments = explode('/', $path);
        $stack = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $seg;
        }

        return '/' . implode('/', $stack);
    }

    private function walk(DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            $text = preg_replace('/\s+/', ' ', $node->nodeValue ?? '');

            return $text ?? '';
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->nodeName);

        if (HtmlVisibility::isHidden($node)
            || in_array($tag, ['script', 'style', 'noscript', 'template', 'head', 'meta', 'link'], true)) {
            return '';
        }

        $childMarkdown = '';
        foreach ($node->childNodes as $child) {
            $childMarkdown .= $this->walk($child);
        }

        return $this->renderTag($tag, $node, $childMarkdown);
    }

    private function renderTag(string $tag, DOMElement $el, string $content): string
    {
        $content = trim($content);

        switch ($tag) {
            case 'h1':
                return "\n# " . $content . "\n\n";
            case 'h2':
                return "\n## " . $content . "\n\n";
            case 'h3':
                return "\n### " . $content . "\n\n";
            case 'h4':
                return "\n#### " . $content . "\n\n";
            case 'h5':
                return "\n##### " . $content . "\n\n";
            case 'h6':
                return "\n###### " . $content . "\n\n";
            case 'p':
            case 'div':
            case 'section':
            case 'article':
            case 'main':
                return $content !== '' ? "\n" . $content . "\n\n" : '';
            case 'br':
                return "  \n";
            case 'hr':
                return "\n---\n\n";
            case 'blockquote':
                $lines = array_filter(explode("\n", trim($content)));
                $quoted = implode("\n", array_map(static fn (string $l): string => '> ' . ltrim($l), $lines));

                return "\n" . $quoted . "\n\n";
            case 'ul':
            case 'ol':
                return "\n" . $this->renderList($el, $tag === 'ol') . "\n";
            case 'li':
                return $content . "\n";
            case 'pre':
                return "\n```\n" . $content . "\n```\n\n";
            case 'code':
                return '`' . $content . '`';
            case 'strong':
            case 'b':
                return '**' . $content . '**';
            case 'em':
            case 'i':
                return '*' . $content . '*';
            case 'a':
                return '[' . $content . '](' . $el->getAttribute('href') . ')';
            case 'img':
                return '![' . $el->getAttribute('alt') . '](' . $el->getAttribute('src') . ')';
            case 'table':
            case 'tr':
                return "\n" . $content . "\n";
            case 'td':
            case 'th':
                return $content . ' | ';
            default:
                return $content;
        }
    }

    private function renderList(DOMElement $el, bool $ordered): string
    {
        $out = '';
        $index = 1;

        foreach ($el->childNodes as $child) {
            if (! ($child instanceof DOMElement) || strtolower($child->nodeName) !== 'li'
                || HtmlVisibility::isHidden($child)) {
                continue;
            }
            $text = rtrim(trim($this->walk($child)));
            $marker = $ordered ? ($index++ . '. ') : '- ';
            $out .= $marker . $text . "\n";
        }

        return rtrim($out);
    }

    private function cleanup(string $markdown): string
    {
        // 折叠多余空行
        $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

        return trim($markdown);
    }
}
