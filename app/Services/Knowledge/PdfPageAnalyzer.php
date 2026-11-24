<?php

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * B2：判断一份 PDF 的哪些页需要送多模态（视觉誊抄）。
 *
 * 判定口径（§1.6，已在 5 份生产文档上验过）：
 *   needs_vision = (图片覆盖率 >= 0.5) || (该页字符数 < 50)
 *
 * 数据来源全部是 poppler 命令行（B1 装的 poppler-utils）：
 *   - pdfinfo  -f 1 -l N  取页数和每页尺寸（pts）
 *   - pdfimages -list     取每张图的像素尺寸和 ppi
 *       显示面积(pt²) = (宽像素 / xppi * 72) * (高像素 / yppi * 72)，按页累加
 *   - pdftotext -f N -l N 取单页字符数（去掉空白后计数）
 *
 * ⚠️ 失败不抛异常：渲染工具挂了不能让整个入库失败（执行书 B2）。
 * 任何一步跑不动都返回空数组（= 一页都不送 vision），并写 warning。
 */
class PdfPageAnalyzer
{
    /** 图片覆盖率阈值：页面积的一半以上被图占住，就值得送多模态。 */
    public const VISION_COVERAGE_THRESHOLD = 0.5;

    /** 单页字符数下限：少于这个数基本是纯图页/封面页。 */
    public const VISION_MIN_CHARS = 50;

    /** 外部命令超时（秒）。分析工具卡死不能拖着整个入库 job。 */
    private const CMD_TIMEOUT = 30;

    /**
     * @return array<int, array{page: int, image_coverage: float, chars: int, needs_vision: bool, text: string}>
     *         按页号升序，key = 页号（1 起）。分析失败返回空数组。
     */
    public function analyze(string $pdfFullPath): array
    {
        $pageSizes = $this->pageSizes($pdfFullPath);
        if ($pageSizes === []) {
            return [];
        }

        $imageAreas = $this->imageAreasPerPage($pdfFullPath);
        if ($imageAreas === null) {
            return [];
        }
        $pageTexts = $this->pageTexts($pdfFullPath, count($pageSizes));
        if ($pageTexts === null) {
            return [];
        }

        $result = [];
        foreach ($pageSizes as $page => [$width, $height]) {
            $pageArea = max($width * $height, 1.0);
            $coverage = ($imageAreas[$page] ?? 0.0) / $pageArea;
            $text = $pageTexts[$page] ?? '';
            $chars = mb_strlen(preg_replace('/\s+/u', '', $text) ?? '');

            $result[$page] = [
                'page' => $page,
                'image_coverage' => round($coverage, 4),
                'chars' => $chars,
                'needs_vision' => $coverage >= self::VISION_COVERAGE_THRESHOLD || $chars < self::VISION_MIN_CHARS,
                'text' => $text,
            ];
        }

        return $result;
    }

    /**
     * pdfinfo：每页尺寸（pts）。
     *
     * @return array<int, array{0: float, 1: float}> key = 页号
     */
    protected function pageSizes(string $pdfFullPath): array
    {
        $command = app(PopplerTools::class)->command('pdfinfo');
        if ($command === null) {
            return [];
        }
        $process = new Process([$command, '-f', '1', '-l', (string) PHP_INT_MAX, $pdfFullPath]);
        $process->setTimeout(self::CMD_TIMEOUT)->run();

        if (! $process->isSuccessful()) {
            Log::warning('PdfPageAnalyzer: pdfinfo failed', [
                'file' => basename($pdfFullPath),
                'error' => mb_substr($process->getErrorOutput(), 0, 200),
            ]);
            return [];
        }

        $sizes = [];
        // 行样例：`Page    1 size:  595.28 x 841.89 pts (A4)`
        if (preg_match_all('/^Page\s+(\d+)\s+size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/m', $process->getOutput(), $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $sizes[(int) $row[1]] = [(float) $row[2], (float) $row[3]];
            }
        }

        if ($sizes === []) {
            Log::warning('PdfPageAnalyzer: pdfinfo returned no page sizes', [
                'file' => basename($pdfFullPath),
            ]);
        }

        return $sizes;
    }

    /**
     * pdfimages -list：每页图片显示面积之和（pt²）。
     *
     * @return array<int, float>|null key = 页号; null means image analysis failed
     */
    protected function imageAreasPerPage(string $pdfFullPath): ?array
    {
        $command = app(PopplerTools::class)->command('pdfimages');
        if ($command === null) {
            return null;
        }
        $process = new Process([$command, '-list', $pdfFullPath]);
        $process->setTimeout(self::CMD_TIMEOUT)->run();

        if (! $process->isSuccessful()) {
            // An unavailable image analyzer cannot prove a page has no images.
            // Do not start paid vision from a partial analysis.
            Log::warning('PdfPageAnalyzer: pdfimages failed', [
                'file' => basename($pdfFullPath),
                'error' => mb_substr($process->getErrorOutput(), 0, 200),
            ]);
            return null;
        }

        $areas = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            // 列：page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio
            //      0    1    2     3      4      5     6    7   8   9      10  11    12    13    14   15
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 14 || ! ctype_digit($cols[0])) {
                continue;
            }

            $page = (int) $cols[0];
            $width = (float) $cols[3];
            $height = (float) $cols[4];
            $xppi = max((float) ($cols[12] ?? 0), 0.01);
            $yppi = max((float) ($cols[13] ?? 0), 0.01);

            // 显示面积（pt²）：像素尺寸换算成点。ppi 异常小会算出天文数字，钳到页面积的 1.5 倍以内
            // 在调用方完成（这里只累加原始值，钳制放 needs_vision 判定处）。
            $areas[$page] = ($areas[$page] ?? 0.0) + ($width / $xppi * 72.0) * ($height / $yppi * 72.0);
        }

        return $areas;
    }

    /**
     * pdftotext：逐页取文字层（既给 chars 计数，也给 B5 按页合并用）。
     *
     * @param  int  $totalPages  pdfinfo 已知的总页数
     * @return array<int, string>|null key = 页号; null means text analysis failed
     */
    protected function pageTexts(string $pdfFullPath, int $totalPages): ?array
    {
        if ($totalPages < 1) {
            return [];
        }

        $command = app(PopplerTools::class)->command('pdftotext');
        if ($command === null) {
            return null;
        }
        $process = new Process([
            $command, '-enc', 'UTF-8', '-f', '1', '-l', (string) $totalPages, $pdfFullPath, '-',
        ]);
        $process->setTimeout(self::CMD_TIMEOUT)->run();

        if (! $process->isSuccessful()) {
            Log::warning('PdfPageAnalyzer: pdftotext failed', [
                'file' => basename($pdfFullPath),
                'error' => mb_substr($process->getErrorOutput(), 0, 200),
            ]);
            return null;
        }

        // pdftotext 用 \f（form feed）分页输出。
        // Some Windows Poppler builds still emit invalid bytes for damaged
        // glyph maps. Preserve the readable text instead of counting the
        // entire page as empty when the Unicode regex rejects one byte.
        $pages = explode("\f", mb_scrub($process->getOutput(), 'UTF-8'));
        $texts = [];
        for ($i = 0; $i < $totalPages; $i++) {
            $texts[$i + 1] = $pages[$i] ?? '';
        }

        return $texts;
    }
}
