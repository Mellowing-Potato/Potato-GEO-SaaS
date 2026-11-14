<?php

namespace Tests\Feature;

use App\Services\Publishing\BrowserExtensionPackage;
use Tests\TestCase;
use ZipArchive;

class BrowserExtensionInstanceTest extends TestCase
{
    public function test_package_pins_configured_origin_even_when_request_host_differs(): void
    {
        config(['app.url' => 'https://YANJI.example.test:443/']);
        request()->headers->set('Host', 'untrusted.example.test');
        $package = new BrowserExtensionPackage;
        $path = tempnam(sys_get_temp_dir(), 'extension-instance-');
        try {
            file_put_contents($path, $package->build());
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path));
            $manifest = json_decode($zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['https://yanji.example.test:443/manual-publish*'], $manifest['content_scripts'][0]['matches']);
            $this->assertSame(['storage'], $manifest['permissions']);
            $this->assertArrayNotHasKey('host_permissions', $manifest);
            $this->assertStringContainsString('"origin":"https://yanji.example.test"', $zip->getFromName('src/instance-config.js'));
            $this->assertStringNotContainsString('untrusted.example.test', $zip->getFromName('src/instance-config.js'));
            $this->assertStringContainsString('https://yanji.example.test', $zip->getFromName('README.md'));
            $this->assertStringContainsString($package->instance()['id'], $package->filename());
            $this->assertSame(13, $zip->numFiles);
            $this->assertSame(file_get_contents(base_path('LICENSE')), $zip->getFromName('LICENSE'));
            $this->assertSame(file_get_contents(base_path('NOTICE')), $zip->getFromName('NOTICE'));
            $this->assertFalse($zip->locateName('.env'));
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_protocol_host_and_port_each_define_a_different_instance(): void
    {
        $ids = [];
        foreach (['http://yanji.localhost:18765', 'http://yanji.localhost:18766',
            'https://yanji.localhost:18765', 'http://another.localhost:18765'] as $url) {
            config(['app.url' => $url]);
            $instance = (new BrowserExtensionPackage)->instance();
            $this->assertSame($url, $instance['origin']);
            $this->assertSame($url.'/manual-publish*', $instance['match']);
            $ids[] = $instance['id'];
        }
        $this->assertCount(4, array_unique($ids));
    }

    public function test_unsafe_operator_origins_are_rejected_instead_of_widening_permissions(): void
    {
        foreach (['https://*.example.test', 'https://user:password@example.test', 'https://example.test/path',
            'https://example.test?token=test-value', 'https://example.test#fragment', 'file:///tmp/helper'] as $url) {
            config(['app.url' => $url]);
            try {
                (new BrowserExtensionPackage)->build();
                $this->fail('Unsafe origin was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('APP_URL', $exception->getMessage());
            }
        }
    }
}
