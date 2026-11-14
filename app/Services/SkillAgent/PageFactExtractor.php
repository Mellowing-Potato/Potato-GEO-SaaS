<?php

namespace App\Services\SkillAgent;

use App\Services\Content\HtmlVisibility;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * 从抓到的 HTML 里抽出**可核验的事实**，形成喂给模型的「观察清单」。
 *
 * 用 DOMDocument 而不是正则：页面审计要数 H 标签层级、要读 JSON-LD 的 @type、要区分
 * 首屏正文和脚本内容，正则在真实世界的 HTML 上做不对这几件事（属性顺序、单双引号、
 * 自闭合、嵌套都会翻车），而这里抽错一个字段，模型就会照着错的写一条「观察到」的结论。
 */
class PageFactExtractor
{
    /** 正文抽取时整段丢掉的标签 —— 它们的文字不是给读者看的 */
    private const NON_PROSE_TAGS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe'];

    private const MAX_HEADINGS = 40;

    private const MAX_ANCHOR_SAMPLES = 15;

    private const MAX_DOWNLOAD_CANDIDATES = 3;

    /**
     * @param  array<string, mixed>  $extra  抓取层已知的事实（robots / sitemap / 响应头等）
     * @return array<string, mixed>
     */
    public function extract(string $html, string $finalUrl, array $extra = []): array
    {
        $document = $this->parse($html);
        $xpath = new DOMXPath($document);

        $headings = $this->headings($xpath);

        return [
            'html_bytes' => strlen($html),
            'lang' => $this->attribute($xpath, '/html', 'lang'),
            'head' => [
                'title' => $this->text($xpath, '//head/title'),
                'meta_description' => $this->metaContent($xpath, 'description'),
                'meta_robots' => $this->metaContent($xpath, 'robots'),
                'canonical' => $this->linkHref($xpath, 'canonical'),
                'og' => $this->openGraph($xpath),
            ],
            'headings' => $headings,
            'heading_outline' => $this->outline($headings),
            'landmarks' => [
                'has_main' => $this->exists($xpath, '//main'),
                'has_article' => $this->exists($xpath, '//article'),
                'has_nav' => $this->exists($xpath, '//nav'),
                'has_header' => $this->exists($xpath, '//header'),
                'has_footer' => $this->exists($xpath, '//footer'),
            ],
            'structured_data' => $this->structuredData($xpath),
            'links' => $this->links($xpath, $finalUrl),
            'prose' => $this->prose($document),
            ...$extra,
        ];
    }

    /**
     * ⚠️ 必须显式声明 UTF-8。DOMDocument 默认按 ISO-8859-1 解析，中文站点直接乱码 ——
     *    乱码之后 title 和 H 标签全是问号，模型会把它当成「页面内容缺失」写进报告。
     *    用 XML 声明而不是 mb_convert_encoding(HTML-ENTITIES)，后者在 PHP 8.2 起已废弃。
     */
    private function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // 真实页面几乎必有不合规标记，解析告警照单全收会淹掉日志，这里只取解析结果。
        $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /** @return list<array{level:int,text:string}> */
    private function headings(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//h1|//h2|//h3');
        $headings = [];
        foreach ($nodes ?? [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $text = $this->clean($node->textContent, 160);
            if ($text === '') {
                continue;
            }
            $headings[] = ['level' => (int) substr($node->nodeName, 1), 'text' => $text];
            if (count($headings) >= self::MAX_HEADINGS) {
                break;
            }
        }

        return $headings;
    }

    /**
     * H 标签层级体检。GEO 关心的是「有没有唯一 H1」和「层级有没有跳级」，
     * 这两项算好了给模型，比让它自己数一遍可靠。
     *
     * @param  list<array{level:int,text:string}>  $headings
     * @return array<string, mixed>
     */
    private function outline(array $headings): array
    {
        $h1 = array_values(array_filter($headings, static fn (array $h): bool => $h['level'] === 1));

        $skips = [];
        $previous = null;
        foreach ($headings as $heading) {
            if ($previous !== null && $heading['level'] > $previous + 1) {
                $skips[] = 'H'.$previous.' 之后直接出现 H'.$heading['level'].'：'.$heading['text'];
            }
            $previous = $heading['level'];
        }

        return [
            'h1_count' => count($h1),
            'h1_texts' => array_column($h1, 'text'),
            'level_skips' => $skips,
        ];
    }

    /** @return array<string, mixed> */
    private function structuredData(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//script[@type="application/ld+json"]');
        $types = [];
        $invalid = 0;
        $blocks = 0;

        foreach ($nodes ?? [] as $node) {
            $blocks++;
            $decoded = json_decode(trim($node->textContent), true);
            if (! is_array($decoded)) {
                $invalid++;

                continue;
            }
            foreach ($this->collectTypes($decoded) as $type) {
                $types[] = $type;
            }
        }

        return [
            'json_ld_blocks' => $blocks,
            'json_ld_invalid_blocks' => $invalid,
            'json_ld_types' => array_values(array_unique($types)),
            'microdata_itemtypes' => $this->microdataTypes($xpath),
        ];
    }

    /**
     * JSON-LD 的 @type 可能藏在 @graph、数组、或嵌套对象里，得递归找。
     *
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function collectTypes(array $data): array
    {
        $types = [];
        foreach ($data as $key => $value) {
            if ($key === '@type') {
                foreach ((array) $value as $type) {
                    if (is_string($type) && $type !== '') {
                        $types[] = $type;
                    }
                }

                continue;
            }
            if (is_array($value)) {
                $types = [...$types, ...$this->collectTypes($value)];
            }
        }

        return $types;
    }

    /** @return list<string> */
    private function microdataTypes(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//*[@itemtype]');
        $types = [];
        foreach ($nodes ?? [] as $node) {
            if ($node instanceof DOMElement) {
                $types[] = $node->getAttribute('itemtype');
            }
        }

        return array_values(array_unique($types));
    }

    /** @return array<string, mixed> */
    private function links(DOMXPath $xpath, string $finalUrl): array
    {
        $host = strtolower((string) (parse_url($finalUrl, PHP_URL_HOST) ?: ''));
        $nodes = $xpath->query('//a[@href]');

        $internal = 0;
        $external = 0;
        $emptyAnchor = 0;
        $samples = [];
        $downloadCandidates = [];

        foreach ($nodes ?? [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($this->isHidden($node)) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')
                || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:')) {
                continue;
            }

            $linkHost = strtolower((string) (parse_url($href, PHP_URL_HOST) ?: ''));
            $isInternal = $linkHost === '' || $linkHost === $host;
            $isInternal ? $internal++ : $external++;

            $anchor = $this->clean($node->textContent, 60);
            if ($anchor === '') {
                $emptyAnchor++;

                continue;
            }
            if ($isInternal && count($downloadCandidates) < self::MAX_DOWNLOAD_CANDIDATES
                && preg_match('/下载|客户端|download|installer/i', $anchor.' '.$href) === 1) {
                $downloadCandidates[] = ['anchor' => $anchor, 'href' => mb_substr($href, 0, 500)];
            }
            if ($isInternal && count($samples) < self::MAX_ANCHOR_SAMPLES) {
                $samples[] = ['anchor' => $anchor, 'href' => mb_substr($href, 0, 200)];
            }
        }

        return [
            'internal_count' => $internal,
            'external_count' => $external,
            'empty_anchor_count' => $emptyAnchor,
            'internal_samples' => $samples,
            'download_candidates' => $downloadCandidates,
        ];
    }

    /**
     * 首屏 HTML 里的正文体量。
     *
     * 这一项单独存在是有目的的：它是判断「页面是不是纯 JS 渲染」的唯一依据。
     * 我们抓的是原始 HTML、不执行 JavaScript，所以正文字数接近 0 但 DOM 有壳，
     * 基本就是内容全靠前端渲染 —— 对 GEO 是致命问题，因为抓取方看到的和人看到的不是一个东西。
     *
     * @return array<string, mixed>
     */
    private function prose(DOMDocument $document): array
    {
        $clone = clone $document;
        $xpath = new DOMXPath($clone);

        foreach (self::NON_PROSE_TAGS as $tag) {
            $nodes = $xpath->query('//'.$tag);
            foreach ($nodes ?? [] as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // 静态 HTML 里常保留备用状态按钮（例如 hidden 的“安装包准备中”），
        // 浏览器不展示，原始 textContent 却会读到。报告不能把它当作可见正文。
        foreach ($xpath->query('//*[@hidden or @aria-hidden or @style]') ?? [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($this->isHidden($node)) {
                $node->parentNode?->removeChild($node);
            }
        }

        $body = $clone->getElementsByTagName('body')->item(0);
        $text = $this->clean($body?->textContent ?? '', 200000);

        return [
            'first_paint_char_count' => mb_strlen($text),
            'first_paint_sample' => mb_substr($text, 0, 1200),
        ];
    }

    private function isHidden(DOMElement $node): bool
    {
        return HtmlVisibility::isHidden($node);
    }

    /** @return array<string, string> */
    private function openGraph(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//meta[@property]');
        $og = [];
        foreach ($nodes ?? [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $property = strtolower($node->getAttribute('property'));
            if (! str_starts_with($property, 'og:')) {
                continue;
            }
            $og[$property] = $this->clean($node->getAttribute('content'), 300);
        }

        return $og;
    }

    private function metaContent(DOMXPath $xpath, string $name): ?string
    {
        // 属性大小写不定（Description / ROBOTS 都见过），用 translate() 折成小写再比。
        $nodes = $xpath->query(
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="'.$name.'"]/@content'
        );
        $value = $nodes && $nodes->length > 0 ? $this->clean((string) $nodes->item(0)?->nodeValue, 500) : '';

        return $value !== '' ? $value : null;
    }

    private function linkHref(DOMXPath $xpath, string $rel): ?string
    {
        $nodes = $xpath->query(
            '//link[translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="'.$rel.'"]/@href'
        );
        $value = $nodes && $nodes->length > 0 ? trim((string) $nodes->item(0)?->nodeValue) : '';

        return $value !== '' ? mb_substr($value, 0, 500) : null;
    }

    private function attribute(DOMXPath $xpath, string $path, string $attribute): ?string
    {
        $nodes = $xpath->query($path.'/@'.$attribute);
        $value = $nodes && $nodes->length > 0 ? trim((string) $nodes->item(0)?->nodeValue) : '';

        return $value !== '' ? mb_substr($value, 0, 40) : null;
    }

    private function text(DOMXPath $xpath, string $path): ?string
    {
        $nodes = $xpath->query($path);
        $value = $nodes && $nodes->length > 0 ? $this->clean((string) $nodes->item(0)?->textContent, 300) : '';

        return $value !== '' ? $value : null;
    }

    private function exists(DOMXPath $xpath, string $path): bool
    {
        $nodes = $xpath->query($path);

        return $nodes !== false && $nodes->length > 0;
    }

    private function clean(string $value, int $limit): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return mb_substr($value, 0, $limit);
    }
}
