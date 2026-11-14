<?php

/**
 * 工作台渲染结果的**结构指纹**（计划 §7 第 2 步：拆 Blade 面板时的等价性判据）。
 *
 * 为什么不是"HTML 逐字节哈希"：Blade 的 `@include` 会吃掉指令行后多出来的一个空行，
 * 于是搬走一个面板，渲染结果就少一行空白。拿字节哈希当判据会一直假红，
 * 而真正的判据是"DOM 没变"——元素、属性、非空白文本一个都不能变。
 *
 * 用法（拆面板前后各跑一次，比对输出的 DOM_FINGERPRINT）：
 *
 *   php -r '$_SERVER["REQUEST_URI"]="/";' ...        # 不需要，直接跑下面的 artisan 命令
 *   php artisan tinker --execute=""                  # 也不需要
 *
 * 实际用法（HTTP 层渲染，与浏览器一致）：
 *   php scripts/blade-dom-fingerprint.php
 *
 * 输出：
 *   DOM_FINGERPRINT=<sha256>
 *   ELEMENTS=1234 TEXTS=567 BYTES=154157
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$request = Illuminate\Http\Request::create('/', 'GET');
$response = $kernel->handle($request);

if ($response->getStatusCode() !== 200) {
    fwrite(STDERR, '工作台页面渲染失败：HTTP '.$response->getStatusCode()."\n");
    exit(1);
}

$html = $response->getContent();

$dom = new DOMDocument;
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

// 1) 结构与属性：每个元素按文档顺序记 tagName + 排序后的属性。
$structure = [];
foreach ($xpath->query('//*') as $element) {
    $attributes = [];
    foreach ($element->attributes as $attribute) {
        $attributes[$attribute->name] = $attribute->value;
    }
    ksort($attributes);
    $structure[] = $element->tagName.'|'.json_encode($attributes, JSON_UNESCAPED_UNICODE);
}

// 2) 非空白文本（空白布局不算变化：换行/缩进属于模板排版，不属于内容）。
$texts = [];
foreach ($xpath->query('//text()') as $node) {
    $text = trim($node->nodeValue);
    if ($text !== '') {
        $texts[] = $node->parentNode->nodeName.':'.$text;
    }
}

echo 'DOM_FINGERPRINT='.hash('sha256', json_encode([$structure, $texts], JSON_UNESCAPED_UNICODE))."\n";
echo 'ELEMENTS='.count($structure).' TEXTS='.count($texts).' BYTES='.strlen($html)."\n";
