<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Yanji\Portable\DesktopLauncher;
use Yanji\Portable\Installation;
use Yanji\Portable\Instance;

require_once __DIR__.'/../../portable/src/Instance.php';
require_once __DIR__.'/../../portable/src/Installation.php';
require_once __DIR__.'/../../portable/src/DesktopLauncher.php';

final class DesktopLauncherTest extends TestCase
{
    private string $directory;
    private Instance $instance;
    private string $artisan;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The fake standalone PHP launcher uses a Unix symlink.');
        }
        $this->directory = sys_get_temp_dir().'/yanji-browser-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
        $this->instance = new Instance($this->directory.'/instance');
        $this->instance->prepare();
        $release = $this->directory.'/release';
        mkdir($release.'/runtime/bin', 0700, true);
        mkdir($release.'/app/public', 0700, true);
        symlink(PHP_BINARY, $release.'/runtime/bin/php');
        $this->artisan = $release.'/app/artisan';
        touch($this->artisan);
        $this->instance->activate($release);
        Instance::atomicFile($this->instance->root.'/instance.json', '{"port":8123}');
        Instance::atomicFile($this->instance->root.'/.env', "YANJI_DESKTOP_MODE=true\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new Filesystem)->deleteDirectory($this->directory);
        }
    }

    private function launchRecord(string $url, int $expires): void
    {
        $record = json_encode(['url' => $url, 'expires_at' => $expires], JSON_THROW_ON_ERROR);
        file_put_contents($this->artisan, '<?php foreach ($argv as $arg) { if (str_starts_with($arg, "--output=")) { $path = substr($arg, 9); file_put_contents($path, '.var_export($record, true).'); chmod($path, 0600); } }');
    }

    public function test_browser_receives_a_private_file_uri_instead_of_authorization_in_its_arguments(): void
    {
        $url = 'http://127.0.0.1:8123/local-start#ticket='.str_repeat('a', 64);
        $this->launchRecord($url, time() + 120);
        $opened = [];
        ob_start();
        try {
            self::assertTrue(DesktopLauncher::open($this->instance, opener: static function (string $value) use (&$opened): bool {
                $opened[] = $value;
                return true;
            }));
        } finally {
            $output = ob_get_clean();
        }
        self::assertCount(1, $opened);
        self::assertStringStartsWith('file:///', $opened[0]);
        self::assertStringNotContainsString('ticket=', $opened[0]);
        self::assertStringNotContainsString(str_repeat('a', 64), $opened[0]);
        $launch = rawurldecode(parse_url($opened[0], PHP_URL_PATH));
        self::assertFileExists($launch);
        self::assertSame(0600, fileperms($launch) & 0777);
        self::assertSame(realpath($this->instance->root.'/run'), realpath(dirname($launch)));
        $document = new \DOMDocument;
        @$document->loadHTML(file_get_contents($launch));
        $xpath = new \DOMXPath($document);
        self::assertSame('0;url='.$url, $xpath->query('//meta[@http-equiv="refresh"]')->item(0)->getAttribute('content'));
        self::assertSame('no-referrer', $xpath->query('//meta[@name="referrer"]')->item(0)->getAttribute('content'));
        self::assertSame(0, $xpath->query('//script|//link|//img|//iframe')->length);
        self::assertSame('', $output);
        self::assertSame([], glob($this->instance->root.'/run/browser-launch-*.json'));
    }

    public function test_expired_private_launch_files_are_removed_without_following_symlinks(): void
    {
        $old = $this->instance->root.'/run/browser-launch-old.html';
        Instance::atomicFile($old, 'expired fixture');
        touch($old, time() - 200);
        $outside = $this->directory.'/outside.html';
        Instance::atomicFile($outside, 'outside fixture');
        symlink($outside, $this->instance->root.'/run/browser-launch-link.html');
        $this->launchRecord('http://127.0.0.1:8123/local-start#ticket='.str_repeat('b', 64), time() + 120);
        self::assertTrue(DesktopLauncher::open($this->instance, opener: static fn () => true));
        self::assertFileDoesNotExist($old);
        self::assertSame('outside fixture', file_get_contents($outside));
        self::assertTrue(is_link($this->instance->root.'/run/browser-launch-link.html'));
    }

    public function test_invalid_expired_or_foreign_instance_urls_never_reach_the_browser(): void
    {
        foreach ([
            ['https://example.com/#ticket='.str_repeat('a', 64), time() + 120],
            ['http://127.0.0.1:8124/local-start#ticket='.str_repeat('a', 64), time() + 120],
            ['http://127.0.0.1:8123/local-start#ticket='.str_repeat('a', 64), time() - 1],
        ] as [$url, $expires]) {
            $this->launchRecord($url, $expires);
            $opened = false;
            try {
                DesktopLauncher::open($this->instance, opener: static function () use (&$opened): bool {
                    $opened = true;
                    return true;
                });
                self::fail('An invalid browser authorization was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringNotContainsString('ticket=', $error->getMessage());
            }
            self::assertFalse($opened);
            self::assertSame([], glob($this->instance->root.'/run/browser-launch-*.json'));
        }
    }

    public function test_legacy_instances_are_not_automatically_authorized_or_opened(): void
    {
        Instance::atomicFile($this->instance->root.'/.env', "APP_URL=http://127.0.0.1:8123\n");
        $opened = false;
        self::assertTrue(DesktopLauncher::open($this->instance, automatic: true, opener: static function () use (&$opened): bool {
            $opened = true;
            return true;
        }));
        self::assertFalse($opened);
        self::assertFalse(Installation::desktopMode($this->instance));
    }

    public function test_explicit_no_browser_does_not_create_a_ticket_or_invoke_a_browser(): void
    {
        file_put_contents($this->artisan, '<?php exit(7);');
        $opened = false;
        ob_start();
        try {
            self::assertTrue(DesktopLauncher::open($this->instance, automatic: true,
                opener: static function () use (&$opened): bool { $opened = true; return false; }, noBrowser: true));
        } finally {
            $output = ob_get_clean();
        }
        self::assertFalse($opened);
        self::assertSame('', $output);
        self::assertTrue(Installation::desktopMode($this->instance), 'Suppressing the GUI must not change the authorization mode.');
        self::assertSame([], glob($this->instance->root.'/run/browser-launch-*.json'));
    }
}
