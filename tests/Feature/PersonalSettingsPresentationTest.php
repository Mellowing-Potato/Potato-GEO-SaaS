<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PersonalSettingsPresentationTest extends TestCase
{
    #[DataProvider('deploymentModes')]
    public function test_personal_settings_hide_internal_permission_details_and_wait_for_authorization(bool $desktop): void
    {
        $root = sys_get_temp_dir().'/yanji-personal-settings-'.bin2hex(random_bytes(8));
        File::makeDirectory($root, 0700, true);
        file_put_contents($root.'/.portable-layout', "yanji-portable-v1\n");
        config(['app.url' => 'http://127.0.0.1:8000', 'instance.portable' => $desktop,
            'instance.desktop_mode' => $desktop, 'instance.portable_root' => $root]);
        try {
            $html = view('workbench.panels.personal-settings')->render();
            foreach (['我的权限', 'team:manage', '/api/v1', 'myPermissionsRole', 'myPermissionsList', '权限清单'] as $detail) {
                $this->assertStringNotContainsString($detail, $html);
            }
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//*[@id="workspaceName" and @disabled]')->length, 'The rename input must wait for authorization.');
            $this->assertSame(1, $xpath->query('//*[@id="saveWorkspaceName" and @disabled]')->length, 'The rename button must wait for authorization.');
        } finally {
            File::deleteDirectory($root);
        }
    }

    public static function deploymentModes(): array
    {
        return ['self hosted server' => [false], 'local desktop' => [true]];
    }
}
