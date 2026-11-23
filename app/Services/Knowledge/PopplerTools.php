<?php

namespace App\Services\Knowledge;

use Symfony\Component\Process\ExecutableFinder;

/** Optional PDF tools are resolved once per operation, without shell commands. */
final class PopplerTools
{
    private const COMMANDS = ['pdfinfo', 'pdfimages', 'pdftotext', 'pdftoppm'];

    public function command(string $name): ?string
    {
        if (! in_array($name, self::COMMANDS, true)) {
            throw new \InvalidArgumentException('Unsupported PDF tool.');
        }
        $directory = (string) config('geo.knowledge.poppler_bin', '');
        if ($directory !== '') {
            $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            return is_file($path) && is_executable($path) ? $path : null;
        }
        return (new ExecutableFinder())->find($name);
    }

    /** @return list<string> */
    public function missing(array $names = self::COMMANDS): array
    {
        return array_values(array_filter($names, fn (string $name): bool => $this->command($name) === null));
    }

    public function notice(array $missing): string
    {
        return '图片未识别：缺少 Poppler 工具（'.implode('、', $missing).'）。请安装 Poppler，必要时设置 GEO_POPPLER_BIN 后重启；文字层仍可入库，扫描件请补齐工具后重新上传。';
    }
}
