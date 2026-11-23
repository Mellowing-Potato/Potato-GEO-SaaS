<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Filesystem\Filesystem;
use Yanji\Portable\Instance;

require_once __DIR__.'/../../portable/src/Instance.php';

class PortableRuntimeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/yanji-portable-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->directory = realpath($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }

    private function program(string $name): string
    {
        $release = $this->directory.'/'.$name;
        mkdir($release.'/app/public', 0700, true);
        mkdir($release.'/runtime/bin', 0700, true);
        touch($release.'/app/artisan');
        touch($release.'/app/public/index.php');
        touch($release.'/runtime/bin/php');
        chmod($release.'/runtime/bin/php', 0700);
        return $release;
    }

    public function test_public_conflict_keeps_the_served_version_and_all_managed_links(): void
    {
        $i = new Instance($this->directory.'/中文 instance');
        $i->prepare();
        $old = $this->program('old');
        $new = $this->program('new');
        touch($old.'/app/public/old.png');
        touch($new.'/app/public/new.png');
        $i->activate($old);
        file_put_contents($i->root.'/public/user.txt', 'owned');
        try {
            $i->activate($new);
            self::fail('Unexpectedly accepted an unmanaged public file');
        } catch (\RuntimeException) {
            self::assertSame($old, $i->release());
            self::assertFileExists($i->root.'/public/old.png');
            self::assertFalse(is_link($i->root.'/public/new.png'));
            self::assertSame('owned', file_get_contents($i->root.'/public/user.txt'));
        }
    }

    public function test_two_ports_on_the_same_host_have_different_cookies_caches_and_storage(): void
    {
        $release = $this->program('shared');
        $environments = [];
        foreach ([8000,8001] as $port) {
            $i = new Instance($this->directory.'/instance-'.$port);
            $i->prepare();
            $i->activate($release);
            Instance::atomicFile($i->root.'/instance.json', json_encode(['port'=>$port]));
            $env = $i->environment();
            self::assertSame($i->root.'/public', $env['YANJI_PUBLIC_PATH']);
            self::assertSame($i->root.'/database/database.sqlite', $env['DB_DATABASE']);
            $environments[] = $env;
        }
        foreach (['YANJI_BROWSER_COOKIE_NAME','SESSION_COOKIE','APP_CONFIG_CACHE','LARAVEL_STORAGE_PATH','CACHE_PREFIX'] as $key) {
            self::assertNotSame($environments[0][$key], $environments[1][$key]);
        }
    }

    public function test_unrelated_nonempty_directory_is_never_initialized(): void
    {
        $path = $this->directory.'/unrelated';
        mkdir($path, 0700);
        file_put_contents($path.'/keep.txt', 'keep');
        $this->expectException(\RuntimeException::class);
        (new Instance($path))->validateDestination();
    }

}
