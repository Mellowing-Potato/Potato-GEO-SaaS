<?php

namespace App\Services\SkillAgent;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Python 渲染脚本桥接服务
 *
 * 调用 yao-geo-skills 仓库中的 Python render_*.py 脚本，
 * 将 LLM 输出的结构化 JSON 渲染为 MD / HTML / DOCX / PDF 多格式文件。
 *
 * 调用约定：
 *   python3 render_*.py --input report_input.json --output-dir ./output/
 *
 * 输出目录结构（由 Python 脚本生成）：
 *   output/
 *   ├── manifest.json
 *   ├── input-brief.json
 *   ├── research-summary.md
 *   ├── quality-report.json
 *   └── deliverables/
 *       ├── {slug}.md
 *       ├── {slug}.docx
 *       ├── {slug}.pdf
 *       └── html-package/
 *           └── index.html
 */
class RenderBridgeService
{
    // 渲染脚本已把内联用的字体子集化成 woff2（一份中文报告约几十 KB），这里只留
    // 足够宽裕的上限：一旦子集化失效、CSS 又指回完整字体（CJK collection > 20 MiB），
    // 下载会被拒绝并记为交付检查失败，而不是悄悄产出一个几十 MB 的单文件 HTML。
    private const MAX_HTML_FONT_BYTES = 4 * 1024 * 1024;

    private const MAX_HTML_FONT_TOTAL_BYTES = 8 * 1024 * 1024;

    /**
     * 吃 Markdown 而不是 JSON 的渲染脚本，以及它们各自的参数名。
     *
     * 11 个渲染脚本里 9 个是 `--input <json> --output-dir <目录>`，只有这两个不是：
     * 它们要一份写好的 Markdown 正文，参数名也各不相同（一个 --out-dir、一个
     * --output-dir，只差一个字）。此前 RenderBridge 对所有脚本一律传 --input，
     * 于是这两个的 argparse 在 main() 第一行就退出，渲染必然失败，客户只拿到
     * 一段裸文字 —— 2026-08-07 查出，先停用止血，本适配层是正解。
     *
     * summaryHeading 是摘要那一节的标题：知识库构建的脚本会检查正文里有没有
     * 「结构化摘要」等几个必需章节（REQUIRED_SECTION_GROUPS），标题对不上质检就不过。
     *
     * 加新脚本前先确认它到底吃什么：`grep add_argument <脚本>`。
     */
    private const MARKDOWN_SOURCE_SCRIPTS = [
        'yao-geo-ranking-article-builder' => [
            'source' => '--source',
            'output' => '--output-dir',
            'summaryHeading' => '榜单速览',
        ],
        'yao-geo-knowledge-base-builder' => [
            'source' => '--source',
            'output' => '--out-dir',
            // 脚本默认把 quality-report.json 写到 out-dir 的父目录，桥接层此前
            // 扫不到，导致脚本明确失败后又被通用评分覆盖成完成。
            'quality' => '--quality-report',
            'summaryHeading' => '结构化摘要',
        ],
    ];

    private string $pythonBin;

    private RenderSchemaRegistry $schemaRegistry;

    private StructuredMarkdownBuilder $markdownBuilder;

    public function __construct()
    {
        $this->pythonBin = (string) config('geo.python_bin');
        $this->schemaRegistry = new RenderSchemaRegistry;
        $this->markdownBuilder = new StructuredMarkdownBuilder;
    }

    /**
     * 判断某个 Skill 是否有可用的渲染脚本
     */
    public function hasRenderScript(SkillAssets $assets): bool
    {
        return $assets->renderScript !== null
            && file_exists($assets->renderScript);
    }

    /**
     * 按脚本的调用约定组装命令行。
     *
     * 绝大多数脚本吃 JSON，走默认那支；MARKDOWN_SOURCE_SCRIPTS 里的那几个吃
     * Markdown，先把结构化 JSON 转成正文写成 .md 再传。
     *
     * @return list<string>
     */
    private function commandFor(
        SkillAssets $assets,
        array $jsonData,
        string $tempDir,
        string $inputFile,
        string $outputDir,
    ): array {
        $contract = self::MARKDOWN_SOURCE_SCRIPTS[$assets->skillId] ?? null;

        if ($contract === null) {
            $command = [$this->pythonBin, $assets->renderScript, '--input', $inputFile, '--output-dir', $outputDir];
            if ($assets->skillId === 'yao-geo-content-refiner') {
                $command = [...$command, '--basename', 'content-refiner'];
            }

            return $command;
        }

        $markdownFile = $tempDir.'/report_source.md';
        file_put_contents(
            $markdownFile,
            $this->markdownBuilder->build($jsonData, $contract['summaryHeading']),
        );

        Log::info('RenderBridge: converted JSON to markdown source', [
            'skill_id' => $assets->skillId,
            'markdown_bytes' => filesize($markdownFile),
        ]);

        $command = [
            $this->pythonBin,
            $assets->renderScript,
            $contract['source'], $markdownFile,
            $contract['output'], $outputDir,
        ];

        if (isset($contract['quality'])) {
            $command[] = $contract['quality'];
            $command[] = $outputDir.'/quality-report.json';
        }

        return $command;
    }

    /**
     * 执行渲染：JSON 数据 → Python 脚本 → 多格式文件
     *
     * @param  array  $jsonData  report_input.json 内容
     * @param  SkillAssets  $assets  Skill 资产包（含 renderScript 路径）
     * @param  int  $executionId  执行记录 ID，用于隔离输出目录
     */
    public function render(array $jsonData, SkillAssets $assets, int $executionId, bool $isolatedOutput = false): RenderResult
    {
        if (! $this->hasRenderScript($assets)) {
            return RenderResult::skipped('No render script available');
        }

        if (! is_executable($this->pythonBin) && (new ExecutableFinder)->find($this->pythonBin) === null) {
            return RenderResult::failure('未找到配置的 Python 解释器。', $this->formats($assets->skillId, [], [], 'python'));
        }

        $tempDir = storage_path("app/temp/render-{$executionId}-".uniqid());
        $baseStoragePath = "executions/{$executionId}";
        if ($isolatedOutput) {
            $baseStoragePath .= '/rerender-'.bin2hex(random_bytes(12));
        }
        $inputFile = $tempDir.'/report_input.json';
        $outputDir = $tempDir.'/output';

        try {
            // ─── 1. 字段自动补齐（确保通过 Python 脚本校验） ───
            $jsonData = $this->schemaRegistry->normalizeInput($assets->skillId, $jsonData);

            Log::info('RenderBridge: normalized JSON input', [
                'skill_id' => $assets->skillId,
                'top_keys' => array_keys($jsonData),
                'key_count' => count($jsonData),
            ]);

            // ─── 2. 创建临时目录并写入 JSON ───
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0700, true);
            }
            $encodedInput = json_encode(
                $jsonData,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($encodedInput === false) {
                return RenderResult::failure('Failed to encode render input JSON: '.json_last_error_msg());
            }
            file_put_contents($inputFile, $encodedInput);

            // ─── 2. 按脚本各自的调用约定组命令 ───
            $process = new Process(
                $this->commandFor($assets, $jsonData, $tempDir, $inputFile, $outputDir)
            );
            $process->setTimeout(180);
            $process->setEnv(['PYTHONIOENCODING' => 'utf-8']);

            Log::info('RenderBridge: invoking Python script', [
                'script' => basename($assets->renderScript),
                'execution_id' => $executionId,
                'input_size' => strlen(file_get_contents($inputFile)),
            ]);

            $process->run();

            // ─── 3. 收集输出文件并持久化（无论脚本是否完全成功） ───
            $paths = [];
            $qualityReport = null;
            $publishableArticle = null;

            if (is_dir($outputDir)) {
                $htmlFontDeliveryCheck = null;
                $paths = $this->collectOutputFiles($outputDir, $executionId, $htmlFontDeliveryCheck, $baseStoragePath);
                $qualityReport = $this->normalizeRendererQualityReport($this->loadQualityReport($outputDir));
                // A successful font check alone must not replace the runner's
                // fallback content checks when Python produced no quality report.
                if ($htmlFontDeliveryCheck !== null && ($qualityReport !== null || ! $htmlFontDeliveryCheck['passed'])) {
                    $qualityReport ??= ['passed' => true, 'checks' => []];
                    $qualityReport['checks'][] = $htmlFontDeliveryCheck;
                    $qualityReport['passed'] = $qualityReport['passed'] && $htmlFontDeliveryCheck['passed'];
                    if (! $htmlFontDeliveryCheck['passed']) {
                        $qualityReport['issues'][] = $htmlFontDeliveryCheck['name'];
                        $qualityReport['next_action'] = 'HTML 字体附件需要修复，其他已生成格式仍可下载';
                    }
                }
                if ($qualityReport !== null) {
                    // The downloadable quality report must describe the final
                    // normalized verdict and stored HTML, not the renderer's
                    // potentially contradictory raw fields.
                    $qualityPath = $baseStoragePath.'/quality-report.json';
                    Storage::disk('reports')->put($qualityPath, json_encode($qualityReport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $paths['quality-report'] = $qualityPath;
                }
                if (str_contains($assets->skillId, 'explainer-builder')) {
                    $publishableArticle = $this->loadPublishableArticle($outputDir);
                }
            }

            $record = is_file($outputDir.'/format-status.json')
                ? json_decode((string) file_get_contents($outputDir.'/format-status.json'), true) : [];
            $record = is_array($record) ? $record : [];
            if (isset($htmlFontDeliveryCheck) && ! $htmlFontDeliveryCheck['passed']) {
                $record['html'] = ['status' => 'failed', 'reason' => 'html_fonts'];
            }
            $formatStatus = $this->formats($assets->skillId, $paths, $record);
            if (! $process->isSuccessful()) {
                $stderr = $process->getErrorOutput();
                Log::warning('RenderBridge: Python script exited non-zero, checking partial output', [
                    'exit_code' => $process->getExitCode(),
                    'stderr_tail' => mb_substr($stderr, -1000),
                    'collected_formats' => array_keys($paths),
                ]);

                // 如果已经收集到至少一个渲染文件，视为部分成功
                $hasRenderedFile = ! empty(array_filter(
                    $paths,
                    fn ($key) => in_array($key, ['markdown', 'html', 'docx', 'pdf']),
                    ARRAY_FILTER_USE_KEY
                ));

                if ($hasRenderedFile) {
                    // 有文件不等于内容合格。部分格式失败（例如只缺 Word）可以继续交付，
                    // 但渲染器已经写出的自检报告若明确 failed，必须把它带回 runner，
                    // 让执行状态按内容质量失败收口，不能再被通用长度检查覆盖成通过。
                    if (is_array($qualityReport) && $qualityReport['passed'] === false) {
                        $qualityReport['renderer_exit_code'] = $process->getExitCode();
                        $qualityReport['next_action'] ??= '修复报告内容后重新检查';
                    }

                    Log::info('RenderBridge: partial render success', [
                        'formats' => array_keys($paths),
                        'skipped_error' => mb_substr($stderr, -500),
                    ]);

                    return new RenderResult(
                        success: true,
                        renderedPaths: $paths,
                        qualityReport: $qualityReport,
                        errorMessage: $stderr !== '' ? mb_substr($stderr, -1000) : null,
                        publishableArticle: $publishableArticle,
                        formatStatus: $formatStatus,
                    );
                }

                return RenderResult::failure($stderr ?: 'Python render script failed', $formatStatus);
            }

            Log::info('RenderBridge: Python script succeeded', [
                'formats' => array_keys($paths),
            ]);

            return new RenderResult(
                success: true,
                renderedPaths: $paths,
                qualityReport: $qualityReport,
                publishableArticle: $publishableArticle,
                formatStatus: $formatStatus,
            );

        } catch (\Throwable $e) {
            Log::error('RenderBridge: exception during render', [
                'error' => $e->getMessage(),
                'execution_id' => $executionId,
            ]);

            return RenderResult::failure($e->getMessage(), $this->formats($assets->skillId, [], []));
        } finally {
            // 清理临时目录
            $this->cleanupDir($tempDir);
        }
    }

    /** A retained draft can be downloaded while its delivery check is still failing. */
    private function formats(string $skillId, array $paths, array $record, ?string $missingDependency = null): array
    {
        $status = [];
        $supported = RenderResult::supportedFormats($skillId);
        foreach (['markdown', 'html', 'docx', 'pdf'] as $format) {
            $entry = is_array($record[$format] ?? null) ? $record[$format] : [];
            if ($format === 'html' && ($entry['status'] ?? null) === 'failed' && ($entry['reason'] ?? null) === 'html_fonts') {
                $status[$format] = ['status' => 'failed', 'reason' => 'html_fonts'];
            } elseif (isset($paths[$format])) {
                $status[$format] = ['status' => 'available'];
            } elseif ($supported !== [] && ! in_array($format, $supported, true)) {
                $status[$format] = ['status' => 'unsupported'];
            } elseif ($missingDependency !== null) {
                $status[$format] = ['status' => 'unavailable', 'reason' => $missingDependency];
            } else {
                $status[$format] = [
                    'status' => ($entry['status'] ?? null) === 'unavailable' ? 'unavailable' : 'failed',
                    'reason' => in_array($entry['reason'] ?? '', [
                        'pdf_engine', 'python_docx', 'reportlab', 'python_markdown', 'beautifulsoup',
                        'pdf_reader', 'python_dependency', 'pandoc', 'pdftoppm', 'pyftsubset',
                        'native_library', 'html_fonts', 'export_failed',
                    ], true) ? $entry['reason'] : 'missing_output',
                ];
            }
        }

        return $status;
    }

    /**
     * 从渲染输出目录中收集文件，持久化到 Laravel storage
     *
     * 不同渲染脚本的目录结构不同：
     *   - explainer-builder: deliverables/{slug}.md + deliverables/html-package/index.html
     *   - tracking/title-optimizer/intent-miner: {slug}.md/.html/.docx（根目录）
     *
     * @return array<string, string> 格式 => 存储路径
     */
    private function collectOutputFiles(string $outputDir, int $executionId, ?array &$htmlFontDeliveryCheck = null, ?string $baseStoragePath = null): array
    {
        $paths = [];
        $baseStoragePath ??= "executions/{$executionId}";

        // 搜索目录列表：deliverables/ 子目录 + 根输出目录
        $searchDirs = [$outputDir];
        $deliverDir = $outputDir.'/deliverables';
        if (is_dir($deliverDir)) {
            array_unshift($searchDirs, $deliverDir);
        }

        foreach ($searchDirs as $dir) {
            // Markdown
            if (! isset($paths['markdown'])) {
                foreach (glob($dir.'/*.md') as $file) {
                    $name = basename($file);
                    if (in_array($name, ['research-summary.md', 'publishable-article.md'], true)) {
                        continue;
                    }
                    $stored = $this->storeFile($file, $baseStoragePath.'/'.$name);
                    if ($stored) {
                        $paths['markdown'] = $stored;
                        break;
                    }
                }
            }

            // DOCX
            if (! isset($paths['docx'])) {
                foreach (glob($dir.'/*.docx') as $file) {
                    $stored = $this->storeFile($file, $baseStoragePath.'/'.basename($file));
                    if ($stored) {
                        $paths['docx'] = $stored;
                        break;
                    }
                }
            }

            // PDF
            if (! isset($paths['pdf'])) {
                foreach (glob($dir.'/*.pdf') as $file) {
                    $stored = $this->storeFile($file, $baseStoragePath.'/'.basename($file));
                    if ($stored) {
                        $paths['pdf'] = $stored;
                        break;
                    }
                }
            }

            // HTML (standalone files or html-package)
            if (! isset($paths['html'])) {
                // Check html-package subdirectory first
                $htmlPackageDir = $dir.'/html-package';
                if (is_dir($htmlPackageDir) && file_exists($htmlPackageDir.'/index.html')) {
                    $stored = $this->storeHtmlFile($htmlPackageDir.'/index.html', $baseStoragePath.'/report.html', $htmlFontDeliveryCheck);
                    if ($stored) {
                        $paths['html'] = $stored;
                    }
                } else {
                    // Check for standalone .html files (not index.html at root)
                    foreach (glob($dir.'/*.html') as $file) {
                        $stored = $this->storeHtmlFile($file, $baseStoragePath.'/'.basename($file), $htmlFontDeliveryCheck);
                        if ($stored) {
                            $paths['html'] = $stored;
                            break;
                        }
                    }
                }
            }
        }

        // Meta files from root output directory
        foreach (['manifest.json', 'research-summary.md'] as $metaFile) {
            $src = $outputDir.'/'.$metaFile;
            if (file_exists($src)) {
                $stored = $this->storeFile($src, $baseStoragePath.'/'.$metaFile);
                if ($stored) {
                    $key = pathinfo($metaFile, PATHINFO_FILENAME);
                    $paths[$key] = $stored;
                }
            }
        }

        // 上游脚本存在两种命名：quality-report.json 与 {slug}-quality-review.json。
        // 后一种此前完全收不到，导致 Python 明确判失败后，PHP 又生成一份通用“通过”。
        $qualityCandidates = array_merge(
            [$outputDir.'/quality-report.json'],
            glob($outputDir.'/*-quality-review.json') ?: [],
        );
        foreach ($qualityCandidates as $src) {
            if (! is_file($src)) {
                continue;
            }
            $stored = $this->storeFile($src, $baseStoragePath.'/quality-report.json');
            if ($stored) {
                $paths['quality-report'] = $stored;
                break;
            }
        }

        Log::info('RenderBridge: collected output files', [
            'execution_id' => $executionId,
            'paths' => $paths,
        ]);

        return $paths;
    }

    /**
     * Keep the existing single-file HTML download usable after the render
     * directory is removed. Only inline font-face resources inside its package;
     * never follow remote URLs, absolute paths or symlinks outside the package.
     */
    private function storeHtmlFile(string $srcPath, string $storagePath, ?array &$deliveryCheck): ?string
    {
        $deliveryCheck = [
            'name' => 'html_download_fonts_embedded',
            'label' => 'HTML 下载字体完整性',
            'passed' => false,
        ];

        try {
            $html = file_get_contents($srcPath);
            $packageRoot = realpath(dirname($srcPath));
            if ($html === false || trim($html) === '' || $packageRoot === false) {
                throw new \RuntimeException('HTML package could not be read.');
            }
            $fontBytes = 0;
            $fontCount = 0;
            $html = preg_replace_callback('~(<style\b[^>]*>)(.*?)(</style\s*>)~is', function (array $style) use ($packageRoot, &$fontBytes, &$fontCount): string {
                $css = preg_replace_callback('~@font-face\s*\{[^{}]*\}~is', function (array $face) use ($packageRoot, &$fontBytes, &$fontCount): string {
                    return preg_replace_callback('~url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s)]*))\s*\)~i', function (array $url) use ($packageRoot, &$fontBytes, &$fontCount): string {
                        $source = $url[1] !== '' ? $url[1] : (($url[2] ?? '') !== '' ? $url[2] : ($url[3] ?? ''));
                        // Existing embedded fonts already travel with the file.
                        if (preg_match('~^data:font/(?:ttf|otf|woff2?|collection);base64,[A-Za-z0-9+/=]+$~i', $source)) {
                            $encoded = substr($source, strpos($source, ',') + 1);
                            if (strlen($encoded) > 4 * (int) ceil(self::MAX_HTML_FONT_BYTES / 3)) {
                                throw new \RuntimeException('Embedded HTML font exceeds the size limit.');
                            }
                            $bytes = strlen(base64_decode($encoded, true) ?: '');
                            if ($bytes === 0 || $bytes > self::MAX_HTML_FONT_BYTES || $fontBytes + $bytes > self::MAX_HTML_FONT_TOTAL_BYTES) {
                                throw new \RuntimeException('Embedded HTML font exceeds the size limit or is invalid.');
                            }
                            $fontBytes += $bytes;
                            $fontCount++;

                            return $url[0];
                        }
                        if (! preg_match('~^(?:\./)?(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_. -]+\.(ttf|otf|ttc|woff|woff2)$~i', $source, $extension)) {
                            throw new \RuntimeException('HTML font source is not a supported relative file.');
                        }
                        $fontPath = realpath($packageRoot.'/'.$source);
                        $rootPrefix = rtrim(str_replace('\\', '/', $packageRoot), '/').'/';
                        if ($fontPath === false || ! is_file($fontPath)
                            || ! str_starts_with(str_replace('\\', '/', $fontPath), $rootPrefix)) {
                            throw new \RuntimeException('HTML font is missing or outside its package.');
                        }
                        $size = filesize($fontPath);
                        if ($size === false || $size === 0 || $size > self::MAX_HTML_FONT_BYTES || $fontBytes + $size > self::MAX_HTML_FONT_TOTAL_BYTES) {
                            throw new \RuntimeException('HTML font exceeds the download size limit.');
                        }
                        $font = file_get_contents($fontPath, length: self::MAX_HTML_FONT_BYTES + 1);
                        if ($font === false || strlen($font) !== $size) {
                            throw new \RuntimeException('HTML font could not be read completely.');
                        }
                        $format = strtolower($extension[1]);
                        $signature = substr($font, 0, 4);
                        $validSignature = match ($format) {
                            'ttf' => in_array($signature, ["\x00\x01\x00\x00", 'true'], true),
                            'otf' => $signature === 'OTTO',
                            'ttc' => $signature === 'ttcf',
                            'woff' => $signature === 'wOFF',
                            'woff2' => $signature === 'wOF2',
                        };
                        if (! $validSignature) {
                            throw new \RuntimeException('HTML font file does not match its format.');
                        }
                        $fontBytes += $size;
                        $fontCount++;
                        $mime = $format === 'ttc' ? 'collection' : $format;

                        return 'url("data:font/'.$mime.';base64,'.base64_encode($font).'")';
                    }, $face[0]) ?? throw new \RuntimeException('HTML font CSS could not be processed.');
                }, $style[2]) ?? throw new \RuntimeException('HTML font CSS could not be processed.');

                return $style[1].$css.$style[3];
            }, $html) ?? throw new \RuntimeException('HTML styles could not be processed.');

            if (! Storage::disk('reports')->put($storagePath, $html)) {
                throw new \RuntimeException('HTML download could not be stored.');
            }
            $deliveryCheck['passed'] = true;
            $deliveryCheck['embedded_fonts'] = $fontCount;
            $deliveryCheck['embedded_font_bytes'] = $fontBytes;

            return $storagePath;
        } catch (\Throwable $e) {
            // Other formats remain available; do not advertise an HTML download
            // with broken fonts or conceal its failure behind temporary checks.
            $deliveryCheck['note'] = '字体附件缺失、不安全、格式不符或过大，HTML 暂不可下载。';
            Log::warning('RenderBridge: HTML font delivery failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * 将报告存入私有存储（仅通过鉴权下载接口交付）
     *
     * @return string|null 私有存储相对路径
     */
    private function storeFile(string $srcPath, string $storagePath): ?string
    {
        try {
            $content = file_get_contents($srcPath);
            if ($content === false || $content === '') {
                return null;
            }
            $extension = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
            if ($extension === 'pdf' && ! str_starts_with($content, '%PDF-')) {
                return null;
            }
            if ($extension === 'docx') {
                $document = new \ZipArchive;
                if ($document->open($srcPath, \ZipArchive::RDONLY) !== true) {
                    return null;
                }
                $hasBody = $document->locateName('word/document.xml') !== false;
                $document->close();
                if (! $hasBody) {
                    return null;
                }
            }
            if (! Storage::disk('reports')->put($storagePath, $content)) {
                throw new \RuntimeException('Report file could not be stored.');
            }

            return $storagePath;
        } catch (\Throwable $e) {
            Log::warning('RenderBridge: failed to store file', [
                'src' => $srcPath,
                'dest' => $storagePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 加载 Python 脚本输出的质量报告
     */
    private function loadQualityReport(string $outputDir): ?array
    {
        $candidates = array_merge(
            [$outputDir.'/quality-report.json'],
            glob($outputDir.'/*-quality-review.json') ?: [],
        );
        foreach ($candidates as $reportPath) {
            if (! is_file($reportPath)) {
                continue;
            }
            $data = json_decode(file_get_contents($reportPath), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Turn the renderer's several historical verdict shapes into one fail-closed
     * conclusion before delivery checks are merged into it.
     *
     * Some renderers emit `status: fail` plus `issues`, but no boolean `passed`.
     * Previously the successful HTML-font check supplied the missing boolean as
     * true, producing the impossible combination `status=fail, passed=true` and
     * allowing an incomplete report through the runner's blocking gate.
     */
    private function normalizeRendererQualityReport(?array $report): ?array
    {
        if ($report === null) {
            return null;
        }

        $status = strtolower(trim((string) ($report['status'] ?? '')));
        $statusFailed = in_array($status, ['fail', 'failed', 'error', 'blocked'], true);
        $failedChecks = is_array($report['failed_checks'] ?? null)
            ? array_values(array_filter($report['failed_checks']))
            : [];
        $issues = is_array($report['issues'] ?? null)
            ? array_values(array_filter($report['issues']))
            : [];
        $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
        $hasFailedCheck = ! empty(array_filter(
            $checks,
            static function (mixed $check): bool {
                if (is_bool($check)) {
                    return $check === false;
                }

                return is_array($check)
                    && ((array_key_exists('passed', $check) && $check['passed'] !== true)
                        || (array_key_exists('pass', $check) && $check['pass'] !== true));
            },
        ));
        $score = $report['score'] ?? $report['overall_score'] ?? null;

        // Renderers use both `passed` and `overall_pass`; the roadmap renderer
        // emits the latter with a list of per-check `pass` booleans.
        $explicitPassed = $report['passed'] ?? $report['overall_pass'] ?? null;
        if (is_bool($explicitPassed)) {
            $passed = $explicitPassed && ! $statusFailed && $failedChecks === [] && $issues === [] && ! $hasFailedCheck;
        } elseif ($statusFailed || $failedChecks !== [] || $issues !== [] || $hasFailedCheck) {
            $passed = false;
        } elseif (in_array($status, ['pass', 'passed', 'success', 'ok'], true)) {
            $passed = true;
        } elseif ($score !== null && is_numeric($score)) {
            $passed = (float) $score >= 60;
        } elseif (array_key_exists('failed_checks', $report) && $checks !== []) {
            // Several renderers publish a complete check matrix plus an empty
            // failed_checks list, but no separate status/passed field.
            $passed = true;
        } else {
            $passed = in_array($status, ['pass', 'passed', 'success', 'ok'], true);
        }

        $report['passed'] = $passed;
        $report['blocking'] = ! $passed;
        if (! $passed) {
            $report['next_action'] ??= '修复报告内容后重新检查';
        }

        return $report;
    }

    /** The explainer renderer's final body is an internal result, not a report download. */
    private function loadPublishableArticle(string $outputDir): ?string
    {
        $path = $outputDir.'/publishable-article.md';
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        $content = file_get_contents($path, length: 1024 * 1024 + 1);
        if ($content === false || trim($content) === '' || strlen($content) > 1024 * 1024) {
            return null;
        }

        return $content;
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        try {
            $this->recursiveDelete($dir);
        } catch (\Throwable $e) {
            Log::warning('RenderBridge: cleanup failed', [
                'dir' => $dir,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recursiveDelete(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->recursiveDelete($path.'/'.$item);
        }

        rmdir($path);
    }
}
