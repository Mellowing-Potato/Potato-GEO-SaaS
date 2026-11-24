<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\SkillAgent\SkillAssetLoader;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * 由 scripts/migrate-console-command.mjs 从 routes/console.php 迁出（计划 §7 第 4 条：
 * 路由文件只留调度声明，命令实现进 app/Console/Commands）。
 *
 * 搬迁口径：只搬位置。签名字符串与 purpose 逐字复制，闭包体原样进 handle()，
 * 输出格式、退出码与用户可见文案没有任何改动。
 */
class GeoHealthCheckCommand extends Command
{
    protected $signature = 'geo:health-check
        {--json : Emit machine-readable JSON}';

    protected $description = 'Verify GEO panorama skill assets and Python render runtime';

    public function handle(): int
    {
        $loader = app(SkillAssetLoader::class);
        $skillId = Project::GEO_ANALYSIS_SKILL_ID;
        $skillsRoot = $loader->getSkillsRoot();
        $skillRoot = "{$skillsRoot}/{$skillId}";

        // ⚠️ 2026-09-21 修（生产实测误报）：这里原先把 required files 拼成"上游仓库的裸路径"
        //    （`{$skillsRoot}/{$skillId}/scripts/render_panorama.py`），于是只要**覆盖层**补的文件
        //    就会被判成缺失 —— 生产上 `geo:health-check` 报 not_ready，而同一台机的
        //    `geo:audit-skill-assets` 报 registered 17 / available 17 / errors 0。
        //    体检命令必须量"应用真正能加载到什么"，不是"某个路径上有没有文件"：
        //    改成走 loader 的**覆盖层感知**接口（与运行时同一条解析路径）。
        $skillMd = $loader->loadSkillMd($skillId);
        $renderScript = $loader->getRenderScript($skillId);
        $exampleJson = $loader->loadExampleJson($skillId);
        $requiredFiles = [
            'skill_md' => ['ok' => trim($skillMd) !== '', 'evidence' => ['bytes' => strlen($skillMd)]],
            'render_script' => [
                'ok' => $renderScript !== null && is_file($renderScript) && is_readable($renderScript),
                // 报出**解析后**的实际路径：运维一眼能看出这份脚本来自覆盖层还是上游
                'evidence' => ['resolved_path' => (string) $renderScript],
            ],
            'example_json' => [
                'ok' => $exampleJson !== null,
                'evidence' => ['keys' => $exampleJson === null ? [] : count($exampleJson)],
            ],
        ];

        $python = new Process([(string) config('geo.python_bin'), '-c', 'import docx']);
        $python->setTimeout(10);
        try {
            $python->run();
            $pythonReady = $python->isSuccessful();
        } catch (\RuntimeException) {
            $pythonReady = false;
        }

        $checks = [
            [
                'key' => 'skills_root_readable',
                'ok' => is_dir($skillsRoot) && is_readable($skillsRoot),
                'message' => 'SKILLS_REPO_PATH points to a readable skills directory.',
                'evidence' => ['skills_root' => $skillsRoot],
            ],
            [
                'key' => 'panorama_skill_exists',
                'ok' => $loader->exists($skillId),
                'message' => 'yao-geo-panorama-audit skill directory exists.',
                'evidence' => ['skill_root' => $skillRoot],
            ],
            [
                'key' => 'python_docx_available',
                'ok' => $pythonReady,
                'message' => 'The configured Python and python-docx are available for render_panorama.py.',
                'evidence' => ['python_docx' => $pythonReady],
            ],
        ];

        foreach ($requiredFiles as $key => $check) {
            $checks[] = [
                'key' => $key,
                'ok' => $check['ok'],
                'message' => "{$key} is present and readable.",
                'evidence' => $check['evidence'] + ['skill_root' => $skillRoot],
            ];
        }

        $failed = collect($checks)->where('ok', false)->values();
        $payload = [
            'object' => 'geo_health_check',
            'status' => $failed->isEmpty() ? 'ready' : 'not_ready',
            'skill_id' => $skillId,
            'skills_root' => $skillsRoot,
            'checks' => $checks,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('GEO SaaS health check');
            $this->line("Skills root: {$skillsRoot}");
            foreach ($checks as $check) {
                $this->line(sprintf('[%s] %s', $check['ok'] ? 'OK' : 'FAIL', $check['message']));
            }
            if ($failed->isNotEmpty()) {
                $this->error("Health check failed: {$failed->count()} issue(s).");
            } else {
                $this->info('Health check passed.');
            }
        }

        return $failed->isEmpty() ? Command::SUCCESS : Command::FAILURE;
    }
}
