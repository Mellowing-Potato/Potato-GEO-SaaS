<?php

// This helper only mints a browser nonce for a disposable native acceptance-test instance.
use Yanji\Portable\Instance;
use Yanji\Portable\DesktopLauncher;
use Yanji\Portable\Platform;

[, $root, $output] = $argv;
if (!is_file($root.'/.portable-acceptance') || file_get_contents($root.'/.portable-acceptance') !== "desktop-fixture\n") {
    throw new RuntimeException('This fixture requires a disposable desktop acceptance-test instance.');
}
$release = PHP_OS_FAMILY === 'Windows'
    ? trim((string) file_get_contents($root.'/current-release.txt'))
    : realpath($root.'/current');
require $release.'/app/portable/src/Platform.php';
require $release.'/app/portable/src/Instance.php';
require $release.'/app/portable/src/Installation.php';
require $release.'/app/portable/src/DesktopLauncher.php';
$instance = new Instance($root);
if (Platform::normalize(dirname($output)) !== $instance->root.'/run' || file_exists($output) || is_link($output)) {
    throw new RuntimeException('The capture must be a new private file in the disposable instance.');
}
$opened = DesktopLauncher::open($instance, opener: static function (string $uri) use ($output): bool {
    Instance::atomicFile($output, json_encode(['launch_file_uri' => $uri], JSON_THROW_ON_ERROR)."\n");
    return true;
});
exit($opened ? 0 : 1);
