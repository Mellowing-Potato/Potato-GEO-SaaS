<?php

namespace App\Console\Commands;

use RuntimeException;
use Symfony\Component\Console\Input\StreamableInputInterface;

trait ReadsLocalPassword
{
    private function localPassword(string $prompt): string
    {
        if (! $this->option('password-stdin')) {
            return (string) ($this->option('password') ?? $this->secret($prompt));
        }
        if ($this->option('password') !== null) {
            throw new RuntimeException('请选择一种密码输入方式。');
        }
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $stream ??= STDIN;
        // At most 128 UTF-8 characters and one trailing line ending. A terminal reads one hidden line.
        if (stream_isatty($stream)) {
            return (string) $this->secret($prompt);
        }
        $raw = stream_get_contents($stream, 515);
        if ($raw === false || strlen($raw) > 514) {
            throw new RuntimeException('标准输入须只含一行有效密码。');
        }
        $value = preg_replace('/\r?\n\z/', '', $raw);
        if ($value === '' || strpbrk($value, "\r\n\0") !== false) {
            throw new RuntimeException('标准输入须只含一行有效密码。');
        }
        return $value;
    }
}
