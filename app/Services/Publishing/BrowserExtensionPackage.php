<?php

namespace App\Services\Publishing;

use App\Support\InstanceConfiguration;
use RuntimeException;
use ZipArchive;

final class BrowserExtensionPackage
{
    public function version(): string
    {
        $path = base_path('browser-extension/manifest.json');
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('浏览器助手版本信息缺失。');
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        $version = $manifest['version'] ?? null;
        if (! is_string($version) || ! preg_match('/^\d+(?:\.\d+){0,3}$/D', $version)) {
            throw new RuntimeException('浏览器助手版本信息无效。');
        }

        return $version;
    }

    public function filename(): string
    {
        return 'yanji-publishing-helper-'.$this->version().'-'.$this->instance()['id'].'.zip';
    }

    public function instance(): array
    {
        // Only operator configuration is trusted. Never use the request Host or
        // forwarded headers to grant an extension access to another website.
        InstanceConfiguration::validate();
        $url = (string) config('app.url');
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || str_contains($parts['host'], '*')) {
            throw new RuntimeException('浏览器助手需要有效且不含通配符的 APP_URL。');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $origin = $scheme.'://'.$host.($default ? '' : ':'.$port);
        return ['origin' => $origin, 'id' => substr(hash('sha256', $origin), 0, 16),
            // Chrome patterns without a port match every port. Specify even
            // 80/443 so manifest permissions agree with the exact origin guard.
            'match' => $scheme.'://'.$host.':'.$port.'/manual-publish*'];
    }

    public function build(): string
    {
        $this->version();
        $instance = $this->instance();
        $path = tempnam(sys_get_temp_dir(), 'yanji-helper-');
        if ($path === false) {
            throw new RuntimeException('无法创建浏览器助手安装包。');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('无法生成浏览器助手安装包。');
            }
            // A fixed file list keeps local artifacts and tenant data out of the download.
            foreach (['manifest.json', 'README.md', 'LICENSE', 'NOTICE', 'src/instance-config.js', 'src/instance-runtime.js', 'src/yanji-bridge.js', 'src/service-worker.js', 'src/zhihu-article.js',
                'src/publishing-worker.js', 'src/weibo-worker.js', 'src/weibo-bridge.js', 'src/weibo-article.js'] as $file) {
                $source = base_path('browser-extension/'.$file);
                if (! is_file($source) || ! is_readable($source)) {
                    $zip->close();
                    throw new RuntimeException('浏览器助手文件不完整，请联系管理员。');
                }
                $contents = (string) file_get_contents($source);
                if ($file === 'manifest.json') {
                    $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                    $manifest['content_scripts'][0]['matches'] = [$instance['match']];
                    $manifest['description'] = '实例：'.$instance['origin'].'。保存到平台草稿，公开发布由用户完成。';
                    $contents = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                } elseif ($file === 'src/instance-config.js') {
                    $contents = 'globalThis.YANJI_INSTANCE = Object.freeze('.json_encode(
                        ['origin' => $instance['origin'], 'id' => $instance['id'], 'version' => $this->version()],
                        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
                    ).');';
                } elseif ($file === 'README.md') {
                    $contents = "实例地址：{$instance['origin']}\n实例标识：{$instance['id']}\n版本：{$this->version()}\n\n".$contents;
                }
                if (!$zip->addFromString($file, $contents)) {
                    $zip->close();
                    throw new RuntimeException('无法写入浏览器助手安装包。');
                }
            }
            $zip->close();
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new RuntimeException('读取浏览器助手安装包失败。');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }
}
