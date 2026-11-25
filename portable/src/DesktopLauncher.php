<?php

declare(strict_types=1);

namespace Yanji\Portable;

use RuntimeException;

/** Keeps short-lived authorization out of console output and process arguments. */
final class DesktopLauncher
{
    public static function open(Instance $instance, bool $automatic = false, ?callable $opener = null, bool $noBrowser = false): bool
    {
        if ($noBrowser) {
            return true;
        }
        $desktop = Installation::desktopMode($instance);
        if ($automatic && !$desktop) {
            return true;
        }
        $url = 'http://127.0.0.1:'.$instance->port();
        if ($desktop) {
            $path = $instance->root.'/run/browser-launch-'.bin2hex(random_bytes(12)).'.json';
            try {
                if ($instance->artisan(['yanji:desktop-launch', '--output='.$path], quiet: true) !== 0
                    || !Platform::isPrivate($path)) {
                    throw new RuntimeException('本机工作台授权未完成，请运行 yanji doctor。');
                }
                $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $url = $record['url'] ?? '';
                $prefix = 'http://127.0.0.1:'.$instance->port().'/local-start#ticket=';
                if (!is_string($url) || !str_starts_with($url, $prefix)
                    || preg_match('/\A[a-f0-9]{64}\z/', substr($url, strlen($prefix))) !== 1
                    || !is_int($record['expires_at'] ?? null) || $record['expires_at'] <= time()) {
                    throw new RuntimeException('本机工作台授权未完成，请运行 yanji doctor。');
                }
            } finally {
                if (is_file($path) && !is_link($path)) {
                    unlink($path);
                }
            }
            $launch = $instance->root.'/run/browser-launch-'.bin2hex(random_bytes(12)).'.html';
            $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
                .'<meta name="referrer" content="no-referrer">'
                .'<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;">'
                .'<meta http-equiv="refresh" content="0;url='.$escaped.'"><title>打开言及</title></head>'
                .'<body><p>正在打开本机工作台…</p><p><a href="'.$escaped.'" rel="noreferrer">打开工作台</a></p></body></html>';
            Instance::atomicFile($launch, $html);
            Platform::protectPrivate($launch, false);
            if (!Platform::isPrivate($launch)) {
                throw new RuntimeException('无法保护本机浏览器启动文件。');
            }
            // The OS opener receives only a private file URI. Passing the ticket URL as argv
            // would expose it to other local users through process inspection on Unix.
            $parts = explode('/', Platform::normalize($launch));
            if (Platform::isWindows()) {
                $drive = array_shift($parts);
                $url = 'file:///'.$drive.'/'.implode('/', array_map('rawurlencode', $parts));
            } else {
                $url = 'file://'.implode('/', array_map('rawurlencode', $parts));
            }
            self::cleanExpiredLaunchFiles($instance, $launch);
        }
        if ($opener !== null) {
            return $opener($url);
        }
        $env = null;
        if (Platform::isWindows()) {
            $env = Platform::windowsPowerShellEnv(getenv());
            $env['YANJI_DESKTOP_URL'] = $url;
            $command = [Platform::windowsPowerShell(), '-NoProfile', '-NonInteractive', '-EncodedCommand',
                Platform::encodePowerShellCommand("\$ErrorActionPreference = 'Stop'; Start-Process -FilePath \$env:YANJI_DESKTOP_URL")];
        } else {
            $command = [PHP_OS_FAMILY === 'Darwin' ? '/usr/bin/open' : 'xdg-open', $url];
        }
        $null = Platform::nullDevice();
        $process = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        return is_resource($process) && proc_close($process) === 0;
    }

    private static function cleanExpiredLaunchFiles(Instance $instance, string $current): void
    {
        foreach (glob($instance->root.'/run/browser-launch-*.html') ?: [] as $path) {
            if ($path !== $current && !is_link($path) && is_file($path) && Platform::isPrivate($path)
                && filemtime($path) < time() - 180) {
                @unlink($path);
            }
        }
    }
}
