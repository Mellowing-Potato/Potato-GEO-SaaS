<?php

namespace Tests\Feature;

use App\Support\PublicAssetVersion;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PublicAssetVersionTest extends TestCase
{
    private string $publicDirectory;
    private string $originalPublicDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPublicDirectory = public_path();
        $this->publicDirectory = sys_get_temp_dir().'/yanji-public-assets-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->publicDirectory, 0700, true);
        $this->app->usePublicPath($this->publicDirectory);
    }

    protected function tearDown(): void
    {
        $this->app->usePublicPath($this->originalPublicDirectory);
        File::deleteDirectory($this->publicDirectory);
        parent::tearDown();
    }

    public function test_same_size_assets_with_zero_mtime_receive_versions_from_their_current_bytes(): void
    {
        $asset = $this->publicDirectory.'/fixture.js';
        file_put_contents($asset, 'const value = "before";');
        touch($asset, 0);
        $first = PublicAssetVersion::for('fixture.js');
        $this->assertSame(hash_file('sha256', $asset), $first);
        $originalSize = filesize($asset);
        file_put_contents($asset, 'const value = "after!";');
        touch($asset, 0);
        clearstatcache(true, $asset);
        $this->assertSame(0, filemtime($asset));
        $this->assertSame($originalSize, filesize($asset));
        $second = PublicAssetVersion::for('fixture.js');
        $this->assertSame(hash_file('sha256', $asset), $second);
        $this->assertNotSame($first, $second, 'An in-process asset replacement must not reuse the prior cache key.');
    }

    public function test_workspace_html_uses_content_versions_for_the_changed_magpie_and_settings_scripts(): void
    {
        foreach ([...glob($this->originalPublicDirectory.'/*.css'), ...glob($this->originalPublicDirectory.'/*.js')] as $source) {
            $target = $this->publicDirectory.'/'.basename($source);
            copy($source, $target);
            touch($target, 0);
        }
        $before = view('welcome', ['cspNonce' => 'test-asset-nonce'])->render();
        foreach (['geo-entry-magpie.js', 'geo-entry-workspace-settings.js', 'geo-entry.js', 'geo-entry.css'] as $filename) {
            $this->assertTrue(str_contains($before, '/'.$filename.'?v='.hash_file('sha256', $this->publicDirectory.'/'.$filename)), 'Rendered '.$filename.' must use its content hash.');
            $this->assertFalse(str_contains($before, '/'.$filename.'?v=0"'), 'A normalized archive timestamp must never be the version for '.$filename.'.');
        }
        $path = $this->publicDirectory.'/geo-entry-magpie.js';
        $oldHash = hash_file('sha256', $path);
        $bytes = file_get_contents($path);
        file_put_contents($path, ($bytes[0] === '/' ? ' ' : '/').substr($bytes, 1));
        touch($path, 0);
        clearstatcache(true, $path);
        $this->assertSame(strlen($bytes), filesize($path));
        $this->assertSame(0, filemtime($path));
        $after = view('welcome', ['cspNonce' => 'test-asset-nonce'])->render();
        $this->assertTrue(str_contains($after, '/geo-entry-magpie.js?v='.hash_file('sha256', $path)), 'Updated script content must change the rendered asset URL.');
        $this->assertFalse(str_contains($after, '/geo-entry-magpie.js?v='.$oldHash), 'The prior content version must disappear after replacing the asset.');
    }

    public function test_missing_optional_asset_keeps_a_noncacheable_fallback_version(): void
    {
        $earliest = time();
        $version = PublicAssetVersion::for('missing.css');
        $this->assertGreaterThanOrEqual($earliest, (int) $version);
        $this->assertLessThanOrEqual(time(), (int) $version);
    }
}
