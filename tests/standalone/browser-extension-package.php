<?php

// Portable package contract: no PHPUnit, application boot, database or network required.
$projectRoot = dirname(__DIR__, 2);
require $projectRoot.'/vendor/autoload.php';

use App\Services\Publishing\BrowserExtensionPackage;
use Illuminate\Foundation\Application;

$app = new Application($projectRoot);
$app->instance('config', new Illuminate\Config\Repository(['app' => ['url' => 'http://yanji.localhost:18765']]));
$files = ['manifest.json', 'README.md', 'LICENSE', 'NOTICE', 'src/instance-config.js', 'src/instance-runtime.js', 'src/yanji-bridge.js', 'src/service-worker.js', 'src/zhihu-article.js', 'src/publishing-worker.js', 'src/weibo-worker.js', 'src/weibo-bridge.js', 'src/weibo-article.js'];
$fixture = sys_get_temp_dir().'/yanji-package-contract-'.bin2hex(random_bytes(6));
mkdir($fixture.'/browser-extension/src', 0700, true);
$zipPath = tempnam(sys_get_temp_dir(), 'yanji-package-test-');
if ($zipPath === false) {
    throw new RuntimeException('Test archive unavailable');
}
try {
    foreach ($files as $file) {
        if (! copy($projectRoot.'/browser-extension/'.$file, $fixture.'/browser-extension/'.$file)) {
            throw new RuntimeException('Fixture copy failed');
        }
    }
    file_put_contents($fixture.'/browser-extension/tenant-content-not-for-download.txt', 'test-only tenant data');
    $app->setBasePath($fixture);
    $package = new BrowserExtensionPackage;
    $bytes = $package->build();
    file_put_contents($zipPath, $bytes);
    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Invalid package');
    }
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }
    $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['LICENSE', 'NOTICE'] as $notice) {
        if ($zip->getFromName($notice) !== file_get_contents($projectRoot.'/browser-extension/'.$notice)) {
            throw new RuntimeException('Bundled license notice changed or missing');
        }
    }
    $zip->close();
    sort($entries);
    $expected = $files;
    sort($expected);
    if ($entries !== $expected || $package->filename() !== 'yanji-publishing-helper-'.$manifest['version'].'-'.$package->instance()['id'].'.zip') {
        throw new RuntimeException('Package metadata or privacy contract failed');
    }
    $filename = $package->filename();
    file_put_contents($fixture.'/browser-extension/manifest.json', json_encode(['version' => "0.2.4\r\nInjected: header"]));
    $rejected = false;
    try {
        $package->filename();
    } catch (RuntimeException) {
        $rejected = true;
    }
    if (! $rejected) {
        throw new RuntimeException('Invalid version was accepted');
    }
    echo json_encode(['version' => $manifest['version'], 'filename' => $filename,
        'archive_files' => count($entries), 'extra_tenant_file_excluded' => true,
        'invalid_version_rejected' => true], JSON_THROW_ON_ERROR), "\n";
} finally {
    unlink($zipPath);
    foreach (array_merge($files, ['tenant-content-not-for-download.txt']) as $file) {
        @unlink($fixture.'/browser-extension/'.$file);
    }
    rmdir($fixture.'/browser-extension/src');
    rmdir($fixture.'/browser-extension');
    rmdir($fixture);
}
