<?php

declare(strict_types=1);

namespace Yanji\Portable;

/** A read-only installation contract for any local automation client. */
final class Onboarding
{
    public static function report(Instance $instance, ?array $status): array
    {
        $desktop = Installation::desktopMode($instance);
        $initialized = is_file($instance->root.'/.portable-initialized')
            && !is_link($instance->root.'/.portable-initialized');
        $ready = $initialized && ($status['ready'] ?? null) === true
            && ($status['writes_enabled'] ?? null) === true;
        foreach (['web', 'worker', 'scheduler'] as $role) {
            $ready = $ready && ($status['services'][$role]['healthy'] ?? null) === true;
        }
        $version = trim((string) @file_get_contents($instance->release().'/app/VERSION'));
        if (!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version)) {
            $version = 'unknown';
        }
        $entry = $desktop ? DesktopEntry::describe($instance) : null;
        $launcher = $instance->root.'/'.Platform::launcherName();
        $launcherAvailable = is_file($launcher) && !is_link($launcher)
            && (Platform::isWindows() || is_executable($launcher));
        $needsEntry = $desktop && ($entry['file'] ?? null) === null;
        $action = !$launcherAvailable || !$initialized ? 'repair_installation' : ($needsEntry ? 'entry' : 'open');

        return [
            'schema_version' => 1,
            'product' => 'yanji',
            'version' => $version,
            'mode' => $desktop ? 'desktop' : 'account',
            'initialized' => $initialized,
            'services_ready' => $ready,
            'state' => !$launcherAvailable ? 'needs_installation_repair'
                : (!$ready ? 'not_ready' : ($needsEntry ? 'needs_entry_repair' : 'awaiting_browser_confirmation')),
            'entry' => $entry,
            // A successful OS dispatch is not evidence that a person can use the page.
            'browser_confirmation_required' => true,
            'next_action' => [
                'action' => $action,
                'command' => $action === 'repair_installation' ? [] : [Platform::nativePath($launcher), $action],
                'human_entry' => $entry['file'] ?? null,
            ],
        ];
    }
}
