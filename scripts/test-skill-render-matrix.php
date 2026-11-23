<?php

declare(strict_types=1);

use App\Services\SkillAgent\RenderBridgeService;
use App\Services\SkillAgent\RenderResult;
use App\Services\SkillAgent\SkillAssetLoader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;

// Local fixtures only. No worker, model provider, channel or application database is used.
$root = dirname(__DIR__);
$options = getopt('', ['skills:', 'python:', 'mode:']);
$skills = realpath($options['skills'] ?? $root.'/yao-geo-skills/skills');
$python = $options['python'] ?? (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
$mode = $options['mode'] ?? 'full';
if (! $skills || ! in_array($mode, ['full', 'optional'], true)) {
    fwrite(STDERR, "Use --skills=<verified snapshot>/skills --python=<interpreter> --mode=full|optional\n");
    exit(1);
}
$lock = json_decode(file_get_contents($root.'/resources/skills/upstream-lock.json'), true, flags: JSON_THROW_ON_ERROR);
if (trim((string) @file_get_contents(dirname($skills).'/.upstream-commit')) !== $lock['upstream_commit']) {
    throw new RuntimeException('The skill snapshot does not match the pinned commit.');
}
foreach ($lock['files'] as $name => $digest) {
    if (! is_file($skills.'/'.$name) || hash_file('sha256', $skills.'/'.$name) !== $digest) {
        throw new RuntimeException('The skill snapshot failed its file integrity check.');
    }
}
$temp = sys_get_temp_dir().'/yanji-render-matrix-'.bin2hex(random_bytes(12));
mkdir($temp, 0700);
foreach ([
    'APP_ENV' => 'testing', 'YANJI_ENV_PATH' => $temp, 'LARAVEL_STORAGE_PATH' => $temp.'/storage',
    'APP_CONFIG_CACHE' => $temp.'/config.php', 'APP_PACKAGES_CACHE' => $temp.'/packages.php',
    'APP_SERVICES_CACHE' => $temp.'/services.php', 'APP_EVENTS_CACHE' => $temp.'/events.php',
    'APP_ROUTES_CACHE' => $temp.'/routes.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
    'LOG_CHANNEL' => 'single', 'PYTHON_BIN' => $python, 'SKILLS_REPO_PATH' => $skills,
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $loader = app(SkillAssetLoader::class);
    $bridge = app(RenderBridgeService::class);
    $count = 0;
    foreach ($lock['approved_skill_ids'] as $id) {
        $assets = $loader->loadAll($id);
        if (! $assets->renderScript) {
            continue;
        }
        $count++;
        $data = $loader->loadExampleJson($id);
        if ($id === 'yao-geo-explainer-builder') {
            // This reviewed fixture deliberately fails content checks even when every format exists.
            $data = json_decode(file_get_contents($root.'/tests/fixtures/skills/'.$id.'/report_input.json'), true, flags: JSON_THROW_ON_ERROR);
        } elseif ($id === 'yao-geo-intent-miner') {
            $files = glob($skills.'/'.$id.'/examples/*/input/report_input.json');
            $data = json_decode(file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        }

        $result = $bridge->render($data, $assets, $count);
        $required = RenderResult::supportedFormats($id);
        if ($required === []) {
            throw new RuntimeException('Unknown renderer contract for '.$id);
        }
        foreach (['markdown', 'html', 'docx', 'pdf'] as $format) {
            $supported = in_array($format, $required, true);
            $present = isset($result->renderedPaths[$format]);
            $status = $result->formatStatus[$format]['status'] ?? null;
            if (! $supported && ($present || $status !== 'unsupported')) {
                throw new RuntimeException($id.' advertised unsupported '.$format);
            }
            if ($supported && ($mode === 'full' || $format !== 'pdf') && ! $present) {
                throw new RuntimeException($id.' lost required '.$format);
            }
            if ($supported && $mode === 'full' && $status !== 'available') {
                throw new RuntimeException($id.' failed its '.$format.' delivery check.');
            }
            if ($supported && ! $present && ! in_array($status, ['unavailable', 'failed'], true)) {
                throw new RuntimeException($id.' has no diagnostic for '.$format);
            }
        }
        if ($id === 'yao-geo-explainer-builder' && ($result->qualityReport['passed'] ?? null) !== false) {
            throw new RuntimeException('Incomplete long-form content unexpectedly passed.');
        }
        echo $id, ' ', json_encode($result->formatStatus, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    }
    if ($count !== 11) {
        throw new RuntimeException("Expected eleven effective renderers, ran $count.");
    }
} finally {
    File::deleteDirectory($temp);
}
