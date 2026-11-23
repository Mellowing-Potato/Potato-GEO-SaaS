<?php

namespace Tests\Feature;

use App\Support\LocalMagpieAuthorization;
use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LocalMagpieAuthorizationTest extends TestCase
{
    private string $dir;
    private string $path;
    private string $key;
    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/yanji-magpie-grant-'.bin2hex(random_bytes(8));
        mkdir($this->dir, 0700);
        $this->path = $this->dir.'/.env';
        $this->key = 'base64:'.base64_encode(random_bytes(32));
        $this->id = bin2hex(random_bytes(16));
        file_put_contents($this->path, "APP_KEY={$this->key}\nYANJI_INSTANCE_ID={$this->id}\n");
    }

    protected function tearDown(): void
    {
        foreach (['.env', '.env.write.lock'] as $name) {
            if (is_file($this->dir.'/'.$name)) {
                unlink($this->dir.'/'.$name);
            }
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function definition(): array
    {
        return ['origin' => 'HTTP://127.0.0.1:03425/', 'tenant_ids' => [8], 'enabled' => true,
            'revision' => 'r17', 'auth_mode' => 'loopback', 'addresses' => ['127.0.0.1']];
    }

    private function writeTargets(array $targets): void
    {
        file_put_contents($this->path, "GEO_MAGPIE_TARGETS='".json_encode($targets, JSON_THROW_ON_ERROR)."'\n", FILE_APPEND);
    }

    public function test_reuses_the_only_matching_origin_without_resetting_its_alias_pins_or_revision(): void
    {
        $targets = [
            'desktop' => $this->definition(),
            'remote' => array_replace($this->definition(), ['origin' => 'https://gateway.example.test:3425',
                'auth_mode' => 'gateway_key', 'addresses' => ['192.0.2.17']]),
        ];
        $this->writeTargets($targets);
        (new LocalMagpieAuthorization)->grant($this->path, 12);
        $targets['desktop']['tenant_ids'][] = 12;
        $parsed = Dotenv::parse(file_get_contents($this->path));
        $this->assertSame($targets, json_decode($parsed['GEO_MAGPIE_TARGETS'], true, flags: JSON_THROW_ON_ERROR));
    }

    public static function conflictingRegistrations(): array
    {
        return [
            'disabled localhost' => ['desktop', ['origin' => 'http://localhost:3425', 'enabled' => false], false],
            'IPv6 loopback' => ['desktop', ['origin' => 'http://[::1]:3425'], false],
            'existing TLS credentials' => ['desktop', ['origin' => 'https://127.0.0.1:3425', 'auth_mode' => 'gateway_key'], false],
            'hostname pinned to loopback' => ['desktop', ['origin' => 'https://gateway.example.test:3425', 'auth_mode' => 'gateway_key'], false],
            'disabled alias' => ['desktop', ['enabled' => false], false],
            'different auth on existing alias' => ['desktop', ['auth_mode' => 'gateway_key'], false],
            'incompatible pins' => ['local', ['addresses' => ['127.0.0.2']], false],
            'ambiguous aliases' => ['desktop', [], true],
            'local already occupied' => ['local', ['origin' => 'https://example.com'], false],
        ];
    }

    #[DataProvider('conflictingRegistrations')]
    public function test_conflicting_existing_registration_is_kept_unchanged(string $alias, array $changes, bool $duplicate): void
    {
        $targets = [$alias => array_replace($this->definition(), ['origin' => 'http://127.0.0.1:3425'], $changes)];
        if ($duplicate) {
            $targets['other'] = $this->definition();
        }
        $this->writeTargets($targets);
        $before = file_get_contents($this->path);
        try {
            (new LocalMagpieAuthorization)->grant($this->path, 12);
            $this->fail('Conflicting registration must be reviewed before authorizing another workspace.');
        } catch (\RuntimeException $error) {
            $this->assertSame($before, file_get_contents($this->path));
        }
    }

    public function test_binding_verified_before_lock_cannot_authorize_a_replaced_environment(): void
    {
        $old = hash('sha256', json_encode([$this->key, $this->id], JSON_THROW_ON_ERROR));
        $replaced = str_replace($this->id, bin2hex(random_bytes(16)), file_get_contents($this->path));
        file_put_contents($this->path, $replaced);
        try {
            (new LocalMagpieAuthorization)->grant($this->path, 12, $old);
            $this->fail('A different instance binding must never receive the previously selected workspace.');
        } catch (\RuntimeException $error) {
            $this->assertSame($replaced, file_get_contents($this->path));
        }
    }

    public function test_assignment_like_text_inside_an_unrelated_multiline_value_remains_literal(): void
    {
        $note = "COMMENT=\"first line\nGEO_MAGPIE_TARGETS=example\nlast line\"\n";
        file_put_contents($this->path, $note, FILE_APPEND);
        $before = Dotenv::parse(file_get_contents($this->path));
        $binding = hash('sha256', json_encode([$this->key, $this->id], JSON_THROW_ON_ERROR));
        (new LocalMagpieAuthorization)->grant($this->path, 12, $binding);
        $contents = file_get_contents($this->path);
        $parsed = Dotenv::parse($contents);
        $this->assertSame($before['COMMENT'], $parsed['COMMENT']);
        $this->assertStringContainsString($note, $contents);
        $this->assertSame([12], json_decode($parsed['GEO_MAGPIE_TARGETS'], true, flags: JSON_THROW_ON_ERROR)['local']['tenant_ids']);
        (new LocalMagpieAuthorization)->grant($this->path, 13, $binding);
        $targets = json_decode(Dotenv::parse(file_get_contents($this->path))['GEO_MAGPIE_TARGETS'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([12, 13], $targets['local']['tenant_ids']);
    }
}
