<?php

// Render a copy of the real Blade/JS/CSS for local visual review. No server,
// database writes, credentials or production mock hooks are created here.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// Explicit, process-local opt-in for a gated module's isolated visual fixture.
// Does not write .env/config/cache or change the application's feature gate.
$includeLeads = in_array('--include-leads', $argv, true);
if ($includeLeads) config(['geo.lead_form_enabled' => true]);
if (in_array('--include-queue', $argv, true)) config(['geo.queue_health_panel_enabled' => true]);
$outputArgs = array_values(array_filter(array_slice($argv, 1), fn ($arg) => !str_starts_with($arg, '--')));
$out = $outputArgs[0] ?? sys_get_temp_dir().'/geo-workspace-ui-preview';
if (!is_dir($out)) mkdir($out, 0777, true);
// CSP nonce（计划 §9.5 前置改造，见 SecurityHeaders::NONCE_VIEW_KEY）：真实请求里由中间件
// 每次生成并 share 给视图；本地预览没有那一层中间件，这里给一个固定的预览值，
// 否则带 nonce 的内联标签会以 "Undefined variable $cspNonce" 直接把预览脚本打红。
// 固定值同时也让指纹稳定（nonce 不参与结构/文本指纹，但避免它每次变化造成无谓差异）。
$previewNonce = 'preview-nonce';
\Illuminate\Support\Facades\View::share('cspNonce', $previewNonce);

/*
 * 预览沙盘按**与生产同一条策略**下发（默认就是强制模式）。
 *
 * 为什么这么改（2026-09-21，用户选 A"先在本地用强制模式跑一遍"）：
 *   预览此前注入的是更宽松的 `script-src 'self' 'unsafe-inline'` —— 那样的沙盘里，
 *   即使某个内联脚本漏挂了 nonce 也照样执行，等于**没验**。
 *   现在策略由 SecurityHeaders::cspWithNonce() 生成（与生产逐字同源），
 *   只把沙盘用不到的两项收紧：connect-src/form-action 收成 'none'
 *   （假数据由 workspace-fixtures.js 拦截，沙盘不连网、不提交表单）。
 *   于是"漏挂 nonce"会在本地就跑出来（securitypolicyviolation → 判据报红），
 *   而不是等生产切 enforce 那天白屏。
 *
 * 需要旧行为时加 `--csp=report-only`（那时下发的是 Report-Only 头名，只报不拦）。
 */
$previewCspMode = in_array('--csp=report-only', $argv, true) ? 'report-only' : 'enforce';
$buildCspMeta = function (array $overrides = []) use ($previewNonce, $previewCspMode): string {
    $policy = \App\Http\Middleware\SecurityHeaders::cspWithNonce($previewNonce);
    $policy = str_replace("connect-src 'self'", "connect-src 'none'", $policy);
    $policy = str_replace("form-action 'self'", "form-action 'none'", $policy);
    foreach ($overrides as $from => $to) {
        $policy = str_replace($from, $to, $policy);
    }
    $header = $previewCspMode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';

    return '<meta http-equiv="'.$header.'" content="'.htmlspecialchars($policy, ENT_QUOTES).'">';
};

$html = view('welcome')->render();
$html = str_replace('<head>', '<head>'.$buildCspMeta(), $html);
$html = str_replace('<body class="geo-app">', '<body class="geo-app"><nav class="ui-preview-banner" aria-label="本地样式预览"><strong>本地样式预览 · 示例数据</strong><span>不连接真实服务，不执行任务</span><a href="#projects">项目</a><a href="#skill">诊断与专项功能</a><a href="#content-library">文章表单入口</a><a href="#result/101">报告样板</a></nav><style>.ui-preview-banner{display:flex;flex-wrap:wrap;gap:16px;align-items:center;padding:10px 24px;background:#edf3f0;font:var(--md-type-caption)}.ui-preview-banner strong{font-weight:500}.ui-preview-banner a{color:#285b47}</style>', $html);
if ($includeLeads) $html = str_replace('不连接真实服务，不执行任务</span>', '不连接真实服务，不执行任务</span><a href="#leads">线索（仅预览开启）</a>', $html);
$fixtureVersion = filemtime(dirname(__DIR__).'/tests/fixtures/ui/workspace-fixtures.js');
$html = str_replace('<script src="/geo-entry.js', '<script src="/workspace-fixtures.js?v='.$fixtureVersion.'"></script><script src="/geo-entry.js', $html);
file_put_contents($out.'/index.html', $html);
$loginHtml = view('login')->render();
$loginHtml = str_replace('<head>', '<head>'.$buildCspMeta(), $loginHtml);
$loginHtml = preg_replace('/(<body\b[^>]*>)/i', '$1<div style="position:fixed;z-index:2;top:0;left:0;right:0;padding:6px 16px;background:#e1ece4;color:#223e36;text-align:center">本地登录样板 · 示例界面 · 不连接真实账户</div>', $loginHtml, 1);
if (!is_dir($out.'/login')) mkdir($out.'/login', 0777, true);
file_put_contents($out.'/login/index.html', $loginHtml);
copy(dirname(__DIR__).'/public/geo-login.js', $out.'/geo-login.js');
// EXEC-10B: the existing CSS glob also includes geo-project-home.css.
foreach (glob(dirname(__DIR__).'/public/geo-*.css') as $file) copy($file, $out.'/'.basename($file));
// Brand assets used by the login and main workspace are served from the preview origin.
$brandRoot = dirname(__DIR__).'/public/brand/yanji/v1';
foreach (new DirectoryIterator($brandRoot) as $brandFile) {
    if (!$brandFile->isFile()) continue;
    $brandDest = $out.'/brand/yanji/v1';
    if (!is_dir($brandDest)) mkdir($brandDest, 0777, true);
    copy($brandFile->getPathname(), $brandDest.'/'.$brandFile->getFilename());
}
// Font files and OFL licenses are served locally, just like production assets.
$fontRoot = dirname(__DIR__).'/public/fonts';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fontRoot, FilesystemIterator::SKIP_DOTS)) as $fontFile) {
    if (!$fontFile->isFile()) continue;
    $fontDest = $out.'/fonts/'.substr($fontFile->getPathname(), strlen($fontRoot) + 1);
    if (!is_dir(dirname($fontDest))) mkdir(dirname($fontDest), 0777, true);
    copy($fontFile->getPathname(), $fontDest);
}
copy(dirname(__DIR__).'/public/geo-entry.js', $out.'/geo-entry.js');
copy(dirname(__DIR__).'/public/geo-page-navigation.js', $out.'/geo-page-navigation.js');
copy(dirname(__DIR__).'/public/geo-report-editor.js', $out.'/geo-report-editor.js');
copy(dirname(__DIR__).'/public/geo-entry-mobile-menu.js', $out.'/geo-entry-mobile-menu.js');
copy(dirname(__DIR__).'/public/geo-entry-workspace-settings.js', $out.'/geo-entry-workspace-settings.js');
copy(dirname(__DIR__).'/public/geo-entry-url-import-detail.js', $out.'/geo-entry-url-import-detail.js');
copy(dirname(__DIR__).'/public/geo-points.js', $out.'/geo-points.js');
copy(dirname(__DIR__).'/public/geo-entry-magpie.js', $out.'/geo-entry-magpie.js');
copy(dirname(__DIR__).'/public/geo-entry-management.js', $out.'/geo-entry-management.js');
copy(dirname(__DIR__).'/public/geo-entry-billing.js', $out.'/geo-entry-billing.js');
copy(dirname(__DIR__).'/public/geo-entry-enterprise-knowledge.js', $out.'/geo-entry-enterprise-knowledge.js');
copy(dirname(__DIR__).'/public/geo-entry-knowledge-base.js', $out.'/geo-entry-knowledge-base.js');
copy(dirname(__DIR__).'/public/geo-entry-content-library.js', $out.'/geo-entry-content-library.js');
copy(dirname(__DIR__).'/public/geo-entry-publishing.js', $out.'/geo-entry-publishing.js');
copy(dirname(__DIR__).'/public/geo-entry-leads.js', $out.'/geo-entry-leads.js');
copy(dirname(__DIR__).'/public/geo-entry-lead-export.js', $out.'/geo-entry-lead-export.js');
copy(dirname(__DIR__).'/public/geo-entry-platform.js', $out.'/geo-entry-platform.js');
copy(dirname(__DIR__).'/public/geo-entry-skills.js', $out.'/geo-entry-skills.js');
copy(dirname(__DIR__).'/public/geo-entry-reports.js', $out.'/geo-entry-reports.js');
copy(dirname(__DIR__).'/public/geo-entry-projects.js', $out.'/geo-entry-projects.js');
copy(dirname(__DIR__).'/public/geo-entry-project-home.js', $out.'/geo-entry-project-home.js');
// 计划 §9.5 账户注销（welcome.blade.php 里那一行 <script src="/geo-entry-account.js">）。
// 漏了这一行，预览页会去请求一个不存在的脚本，静态服务器回 index.html，
// 浏览器报 "SyntaxError: Unexpected token '<'" —— 指纹里的 JS 异常计数就是这么红的（实测过）。
copy(dirname(__DIR__).'/public/geo-entry-account.js', $out.'/geo-entry-account.js');
copy(dirname(__DIR__).'/public/geo-report-reader.js', $out.'/geo-report-reader.js');
copy(dirname(__DIR__).'/tests/fixtures/ui/workspace-fixtures.js', $out.'/workspace-fixtures.js');
// Standalone monitoring page: render only an isolated HTML copy, without enabling its route.
$visibilityHtml = view('visibility')->render();
$visibilityHtml = str_replace('<head>', '<head>'.$buildCspMeta(), $visibilityHtml);
$visibilityHtml = str_replace('<body class="geo-visibility geo-app">', '<body class="geo-visibility geo-app"><div style="padding:12px 24px;font-size:13px;background:var(--md-primary-soft)">本地样式预览 · 示例数据 · 不提交真实监测 <a href="/">返回工作台样板</a></div>', $visibilityHtml);
$visibilityHtml = str_replace('<script src="/geo-visibility.js', '<script src="/workspace-fixtures.js?v='.$fixtureVersion.'"></script><script src="/geo-visibility.js', $visibilityHtml);
if (!is_dir($out.'/visibility')) mkdir($out.'/visibility', 0777, true);
file_put_contents($out.'/visibility/index.html', $visibilityHtml);
copy(dirname(__DIR__).'/public/geo-visibility.js', $out.'/geo-visibility.js');
$manualHtml = view('manual-publish')->render();
$manualHtml = str_replace('<head>', '<head>'.$buildCspMeta(), $manualHtml);
$manualHtml = str_replace('<body class="mp geo-app">', '<body class="mp geo-app"><div style="padding:12px 24px;font-size:13px;background:var(--md-primary-soft)">本地样式预览 · 示例数据 · 不创建或流转真实发布单 <a href="/">返回工作台样板</a></div>', $manualHtml);
$manualHtml = str_replace('<script src="/geo-manual-publish.js', '<script src="/workspace-fixtures.js?v='.$fixtureVersion.'"></script><script src="/geo-manual-publish.js', $manualHtml);
if (!is_dir($out.'/manual-publish')) mkdir($out.'/manual-publish', 0777, true);
file_put_contents($out.'/manual-publish/index.html', $manualHtml);
copy(dirname(__DIR__).'/public/geo-manual-publish.js', $out.'/geo-manual-publish.js');
$themeHtml = view('theme-package')->render();
$themeHtml = str_replace('<head>', '<head>'.$buildCspMeta(["frame-ancestors 'self';" => "frame-ancestors 'self'; frame-src 'self' about:;"]), $themeHtml);
$themeHtml = str_replace('<body class="tp md-app geo-app">', '<body class="tp md-app geo-app"><div style="padding:12px 24px;font-size:13px;background:var(--md-primary-soft);flex-shrink:0">本地样式预览 · 示例数据 · 不生成真实主题包 <a href="/">返回工作台样板</a></div>', $themeHtml);
$themeHtml = str_replace('<script src="/geo-theme-package.js', '<script src="/workspace-fixtures.js?v='.$fixtureVersion.'"></script><script src="/geo-theme-package.js', $themeHtml);
if (!is_dir($out.'/theme-package')) mkdir($out.'/theme-package', 0777, true);
file_put_contents($out.'/theme-package/index.html', $themeHtml);
copy(dirname(__DIR__).'/public/geo-theme-package.js', $out.'/geo-theme-package.js');
$analyticsHtml = view('analytics')->render();
$analyticsHtml = str_replace('<head>', '<head>'.$buildCspMeta(), $analyticsHtml);
$analyticsHtml = str_replace('<body class="geo-analytics geo-app">', '<body class="geo-analytics geo-app"><div style="padding:12px 24px;font-size:13px;background:var(--md-primary-soft)">本地样式预览 · 固定示例数据 · 非真实访问统计 <a href="/">返回工作台样板</a></div>', $analyticsHtml);
$analyticsHtml = str_replace('<script src="/geo-analytics.js', '<script src="/workspace-fixtures.js?v='.$fixtureVersion.'"></script><script src="/geo-analytics.js', $analyticsHtml);
if (!is_dir($out.'/analytics')) mkdir($out.'/analytics', 0777, true);
file_put_contents($out.'/analytics/index.html', $analyticsHtml);
copy(dirname(__DIR__).'/public/geo-analytics.js', $out.'/geo-analytics.js');

// Remaining pages are rendered directly, without enabling their application routes.
$renderPreview = function (string $viewName, array $data, string $folder, string $note) use ($out, $fixtureVersion, $buildCspMeta) {
    $page = view($viewName, $data)->render();
    $page = str_replace('<head>', '<head>'.$buildCspMeta(), $page);
    $banner = '<div style="padding:12px 24px;font:13px/1.6 sans-serif;background:var(--md-primary-soft);color:var(--md-text)">本地样式预览 · '.htmlspecialchars($note).' · <a href="/review/">返回统一验收目录</a></div>';
    $page = preg_replace('/(<body[^>]*>)/', '$1'.$banner, $page, 1);
    $page = preg_replace('/(<script src="\/geo-)/', '<script src="/workspace-fixtures.js?v='.$fixtureVersion.'"></script>$1', $page, 1);
    if (!is_dir($out.'/'.$folder)) mkdir($out.'/'.$folder, 0777, true);
    file_put_contents($out.'/'.$folder.'/index.html', $page);
};
$renderPreview('platform-ops', [
    'isProduction' => false, 'envLabel' => '本地样例',
    'geoOpsConfig' => ['accountLoginUrl' => '/review/', 'accountUrl' => '/review/'],
    'cssVersion' => filemtime(public_path('geo-platform-ops.css')), 'jsVersion' => filemtime(public_path('geo-platform-ops.js')),
], 'platform/ops', '固定示例数据，不修改真实订单或租户');
$renderPreview('workbench', [], 'workbench', '受开关控制的 v2 页面，仅渲染样式副本；含原有演示回退');
$renderPreview('reading.article', [
    'seo' => ['title' => '文化体验与亲子出游（样例）', 'site_name' => '本地阅读样例', 'description' => '用于检查正文、表格和引用的样式', 'canonical_url' => '', 'og_title' => '本地阅读样例', 'og_description' => '固定样例正文', 'og_image' => ''],
    'article' => (object) ['title' => '文化体验与亲子出游：出发前需要确认哪些信息？'],
    'html' => '<p>这是本地排版样例，未发布为正式文章。正文采用统一阅读宽度，标题、段落、表格和引用保持清晰的层级。</p><h2>先整理出游问题</h2><p>记录同行人数、年龄、兴趣和行程时间，再逐项确认开放安排与预约信息。<strong>强调只用于关键内容。</strong></p><blockquote><p>资料中的开放时间、票价与预约要求，应以发布方的有效公告为准。</p></blockquote><h3>资料检查表</h3><table><thead><tr><th>资料</th><th>用途</th><th>确认内容</th><th>更新说明</th></tr></thead><tbody><tr><td>景点公告</td><td>安排行程</td><td>开放时间与预约方式</td><td>记录来源与更新时间</td></tr><tr><td>交通说明</td><td>安排接驳</td><td>班次、地点与同行人数</td><td>较长内容用于检查窄屏表格的局部横向滚动</td></tr></tbody></table><h3>记录格式</h3><pre><code>{ "source": "本地排版样例", "status": "待核对", "notes": "较长代码行保持局部滚动，不撑宽整页" }</code></pre><ul><li>保留信息来源。</li><li>对未确认信息标明状态。</li></ul>',
], 'read-preview', '固定文章样例，未对外发布');
$fields = [];
foreach (['text' => '称呼', 'email' => '邮箱', 'phone' => '联系电话', 'select' => '出游意向', 'textarea' => '补充说明', 'checkbox' => '同意联系'] as $type => $label) {
    $fields[] = ['type' => $type, 'name' => 'sample_'.$type, 'label' => $label, 'required' => $type === 'text', 'options' => ['文化体验', '亲子活动']];
}
$renderPreview('lead-form', [
    'form' => (object) ['name' => '出游信息登记（本地样例）'], 'siteName' => '本地表单样例', 'description' => '填写出游意向，便于整理需要确认的资料。此页面不会保存或发送真实信息。',
    'formAction' => '/local-preview-form', 'csrfToken' => 'local-preview-only', 'fields' => $fields, 'submitLabel' => '提交资料', 'errors' => new Illuminate\Support\ViewErrorBag(),
    'cssVersion' => filemtime(public_path('geo-lead-form.css')), 'jsVersion' => filemtime(public_path('geo-lead-form.js')),
], 'form-preview', '六种字段样例；提交只显示预览提示');
foreach (['geo-platform-ops.js', 'geo-workbench.js', 'geo-workbench-views.js', 'geo-lead-form.js'] as $script) copy(public_path($script), $out.'/'.$script);

$reviewGroups = [
    '主工作台' => ['用户登录' => '/login/', '项目资料' => '/#projects', '诊断与写作' => '/#skill', '历史报告' => '/#asset', '报告阅读' => '/#result/101', '文章' => '/#content-library', '文章分类' => '/#categories', '素材' => '/#materials', '自动化' => '/#automation', 'URL 导入' => '/#url-imports', '知识库与企业档案' => '/#knowledge', '发布渠道' => '/#publishing', '线索管理' => '/#leads', '项目与用量分析' => '/#analytics'],
    '账户与设置' => ['用量明细' => '/#usage', 'AI Key' => '/#aikeys', '敏感词' => '/#sensitive-words', '提示词' => '/#skill-prompts', '平台汇总' => '/#platform'],
    '独立工具' => ['AI 监测' => '/visibility/?project_id=901#sec-new', '手动发布' => '/manual-publish/', '主题包' => '/theme-package/', '爬虫访问统计' => '/analytics/'],
    '剩余页面' => ['平台运营与敏感词（样例平台身份）' => '/platform/ops/?preview-platform-admin', '对外文章' => '/read-preview/', '公开表单' => '/form-preview/', '新版工作台（门控）' => '/workbench/'],
];
$reviewHtml = '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UI 统一验收目录</title><link rel="stylesheet" href="/geo-tokens.css"><style>body{margin:0;background:var(--md-bg-page);color:var(--md-text);font:14px/1.6 var(--md-font-sans)}main{max-width:1040px;margin:auto;padding:32px 20px}h1{font-size:24px;font-weight:600}h2{font-size:16px;font-weight:600;margin-top:32px}p{color:var(--md-text-secondary)}nav{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}a{padding:16px;border:1px solid var(--md-border);border-radius:8px;background:var(--md-surface);color:var(--md-primary-hover);text-decoration:none}a:hover{background:var(--md-primary-soft)}a:focus-visible{outline:2px solid var(--md-primary)}</style><main><h1>UI 统一验收目录</h1><p>第 1–15 批本地样式入口。所有数据为样例，未部署；真实业务请求被阻断。第 3–15 批视觉样式由你统一确认。</p><p>建议先看：诊断与写作的提示字重、报告阅读的留白，再看各模块表单、列表与手机宽度。需要填写资料的弹窗可在发票页或删除确认入口打开，取消即可退出。</p>';
foreach ($reviewGroups as $group => $links) {
    $reviewHtml .= '<h2>'.htmlspecialchars($group).'</h2><nav>';
    foreach ($links as $label => $href) $reviewHtml .= '<a href="'.htmlspecialchars($href).'">'.htmlspecialchars($label).'</a>';
    $reviewHtml .= '</nav>';
}
$reviewHtml .= '</main></html>';
if (!is_dir($out.'/review')) mkdir($out.'/review', 0777, true);
file_put_contents($out.'/review/index.html', $reviewHtml);
if (!is_dir($out.'/typography')) mkdir($out.'/typography', 0777, true);
copy(dirname(__DIR__).'/tests/fixtures/ui/typography-preview.html', $out.'/typography/index.html');
echo "Local preview generated: ".$out.PHP_EOL;
