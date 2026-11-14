<?php

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Html;
use RuntimeException;

class ArticleWordExporter
{
    public function toDocx(string $title, string $markdown, ?string $summary = null): string
    {
        // Only Markdown-generated HTML enters PHPWord; it must never fetch article images
        // or treat user-supplied HTML as file paths, stylesheets, or embedded objects.
        $html = (string) Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $dom = new DOMDocument('1.0', 'UTF-8');
        if (! $dom->loadXML('<body>'.$html.'</body>', LIBXML_NONET)) {
            throw new RuntimeException('文章正文无法转换为 Word 文档。');
        }
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $image) {
            $alt = trim($image->getAttribute('alt'));
            $image->parentNode?->replaceChild(
                $dom->createTextNode($alt !== '' ? '［图片：'.$alt.'］' : '［图片］'),
                $image,
            );
        }

        $document = new PhpWord;
        $document->setDefaultFontName('Microsoft YaHei');
        $document->setDefaultFontSize(11);
        $document->setDefaultParagraphStyle(['spaceAfter' => 120]);
        $document->getDocInfo()->setTitle($title);
        for ($level = 1; $level <= 6; $level++) {
            $document->addTitleStyle($level, ['bold' => true, 'size' => max(12, 22 - $level * 2)]);
        }

        $section = $document->addSection();
        $heading = $dom->getElementsByTagName('h1')->item(0);
        $previousEscaping = Settings::isOutputEscapingEnabled();
        $path = tempnam(sys_get_temp_dir(), 'geo-docx-');
        if ($path === false) {
            throw new RuntimeException('无法创建 Word 导出文件。');
        }

        try {
            Settings::setOutputEscapingEnabled(true);
            if ($title !== '' && (! $heading instanceof DOMElement || trim($heading->textContent) !== $title)) {
                $section->addTitle($title, 1);
            }
            $summary = trim((string) $summary);
            if ($summary !== '') {
                $section->addText('摘要', ['bold' => true]);
                $section->addText($summary);
            }
            Html::addHtml($section, $dom->saveXML($dom->documentElement), true, false);
            IOFactory::createWriter($document, 'Word2007')->save($path);
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('无法读取 Word 导出文件。');
            }

            return $bytes;
        } finally {
            Settings::setOutputEscapingEnabled($previousEscaping);
            @unlink($path);
        }
    }
}
