<?php

namespace App\Services\Ops;

use App\Models\Project;
use App\Models\Tenant;
use App\Services\Projects\ProjectTrashService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 隐私生命周期 · 只读盘点（计划 §9.5；总台账 S11「技术盘点可先做，规则需确认」）。
 *
 * 要回答的问题很具体：**客户/我们删掉一个项目或一个租户之后，后台还剩什么？**
 * 这个问题过去没有答案，因为没人把"删"和"留"摆在一起看过：
 *   - 删除接口（`ProjectController::destroy`）只处理 articles / skill_executions / project_stages，
 *     最后软删 project 本身；
 *   - 另外几张带 `project_id` 的表（tasks / url_imports / knowledge_documents /
 *     model_call_logs / ai_visibility_runs / analytics_* / project_publishing_channels）**一行没动**；
 *   - 报表文件（`storage/app/private/reports/executions/{id}/…`）也不动 ——
 *     而执行记录一删，这些目录就再没有任何行能反查到租户。
 *
 * 所以这个服务**只测不删**：按数据库真实表结构（`Schema::getTableListing` + `getColumns`，
 * 不写死表名清单，免得漏了后来新增的表）统计各表现存行数，再扫一遍报表目录找出"孤儿目录"。
 * 输出是给运营/产品做规则决策用的证据，不是清理工具 —— 清理必须等保留期限定下来。
 *
 * ⚠️ 本服务不做任何写操作。唯一"看到"的数据是行数与文件大小，不读客户正文。
 */
class PrivacyLifecycleAuditService
{
    private readonly ProjectTrashService $projectTrash;

    /**
     * ⚠️ 这里不能用 `= new ProjectTrashService` 当默认值：垃圾桶服务自己有依赖
     * （审计日志），一旦它长出构造参数，直接 new 就会在测试里炸
     * （实测：Too few arguments to function ProjectTrashService::__construct）。
     * 默认值改成"从容器取"，谁有新依赖都自动带上。
     */
    public function __construct(?ProjectTrashService $projectTrash = null)
    {
        $this->projectTrash = $projectTrash ?? app(ProjectTrashService::class);
    }

    /**
     * 报表目录前缀 → 反查用的表。
     *
     * 只有登记过的前缀才判"孤儿"；没登记的前缀原样报出来（`unmapped`），
     * 不猜、不当成正常 —— 猜错的代价是运营据此删掉不该删的东西。
     */
    private const REPORT_DIR_TABLES = [
        'executions' => 'skill_executions',
    ];

    /** @var array<string, list<string>> 列名 → 命中该列的表（同一次运行内缓存） */
    private array $tablesByColumn = [];

    /**
     * 盘点一个项目：还在的项目 = 删除前的占用预览；已软删的项目 = 删除后的残留。
     *
     * @return array<string, mixed>
     */
    public function auditProject(int $projectId, ?int $tenantId = null): array
    {
        $project = Project::withTrashed()->find($projectId);
        if ($project === null) {
            return ['status' => 'project_not_found', 'read_only' => true, 'project_id' => $projectId];
        }

        if ($tenantId !== null && (int) $project->tenant_id !== $tenantId) {
            return [
                'status' => 'tenant_mismatch',
                'read_only' => true,
                'project_id' => $projectId,
                'project_tenant_id' => (int) $project->tenant_id,
                'requested_tenant_id' => $tenantId,
            ];
        }

        $leftovers = [];
        $scanned = 0;
        foreach ($this->tablesWithColumn('project_id') as $table) {
            $scanned++;
            $rows = (int) DB::table($table)->where('project_id', $projectId)->count();
            if ($rows > 0) {
                $leftovers[] = ['table' => $table, 'rows' => $rows, 'note' => self::noteFor($table)];
            }
        }

        return [
            'status' => 'ok',
            'read_only' => true,
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'tenant_id' => (int) $project->tenant_id,
                'deleted' => $project->trashed(),
                'deleted_at' => $project->deleted_at?->toISOString(),
            ],
            'tables_scanned' => $scanned,
            'leftovers' => $leftovers,
            'leftover_row_count' => array_sum(array_column($leftovers, 'rows')),
            'trash' => $this->trashInfo($project),
            'external_publications' => $this->externalPublications($projectId),
            'notes' => $this->projectNotes(),
        ];
    }

    /**
     * 垃圾桶状态（计划 §9.5，用户口径 2026-09-21）。
     *
     * 为什么盘点必须知道这件事：2026-09-21 之前"删项目"是连坐硬删，
     * leftovers 里出现 articles/skill_executions 就是"没删干净"；
     * 之后删除 = 进桶、内容原地保留 7 天可恢复 —— 再看 leftovers 得先分清
     * "这是桶里的可恢复内容"还是"这是桶外漏掉的残留"。同一个数字，两种结论。
     *
     * @return array<string, mixed>
     */
    private function trashInfo(Project $project): array
    {
        $retention = $this->projectTrash->retentionDays();

        if (! $project->trashed()) {
            return [
                'in_trash' => false,
                'retention_days' => $retention,
                'note' => '项目还在用。删除它会先进垃圾桶：内容原地保留 '.$retention.' 天（期内可恢复），期满由 geo:projects-purge 真删。',
            ];
        }

        return [
            'in_trash' => true,
            'retention_days' => $retention,
            'deleted_at' => $project->deleted_at?->toISOString(),
            'purge_at' => $this->projectTrash->purgeAt($project)->toISOString(),
            'days_left' => $this->projectTrash->daysLeft($project),
            'expired' => $this->projectTrash->isExpired($project),
            'note' => '项目在垃圾桶里：articles / skill_executions / project_stages 是**刻意保留的可恢复内容**'
                .'（期内恢复即原样回来），不等于"删得不干净"；桶内公开阅读页一律 404，自动化任务已暂停。'
                .'清理命令默认只演练，--apply 才真删。',
        ];
    }

    /**
     * 外站发布记录：**这是"删项目 ≠ 撤稿"的那条缝**。
     *
     * `article_publications.article_id` 是 `cascadeOnDelete`，文章一硬删，本地发布记录跟着消失，
     * 连 `remote_id` / `remote_url` 都一起没了 —— 而客户 WordPress 上那篇**还在线**。
     * 等客户来问"我项目都删了怎么文章还在",我们连撤回要用的地址都得靠猜。
     *
     * 所以盘点要把"这个项目对外发过几篇"报出来，并且明说：撤回必须在删除之前做。
     *
     * @return array<string, mixed>
     */
    private function externalPublications(int $projectId): array
    {
        $rows = DB::table('article_publications')
            ->join('articles', 'articles.id', '=', 'article_publications.article_id')
            ->where('articles.project_id', $projectId)
            ->get(['article_publications.status', 'article_publications.remote_url']);

        $byStatus = [];
        foreach ($rows as $row) {
            $status = (string) $row->status;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        $withRemoteUrl = $rows->filter(fn (object $row): bool => trim((string) $row->remote_url) !== '')->count();

        return [
            'total' => $rows->count(),
            'by_status' => $byStatus,
            'with_remote_url' => $withRemoteUrl,
            'note' => $rows->count() === 0
                ? '这个项目没有外站发布记录。'
                : '⚠️ 删除项目**不会下架外站内容**：项目进垃圾桶期内本地记录还在（这段窗口是撤稿的最佳时机），'
                    .'但期满清理时 article_publications 随文章级联删除、remote_url 一起丢掉，之后想撤回连地址都找不回。'
                    .'要撤稿必须在删除之前、最迟在桶内做（逐条下线并留痕）。',
        ];
    }

    /**
     * 盘点一个租户：按 `tenant_id` 把每张表的现存行数报出来。
     *
     * @return array<string, mixed>
     */
    public function auditTenant(int $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return ['status' => 'tenant_not_found', 'read_only' => true, 'tenant_id' => $tenantId];
        }

        $tables = [];
        foreach ($this->tablesWithColumn('tenant_id') as $table) {
            $rows = (int) DB::table($table)->where('tenant_id', $tenantId)->count();
            if ($rows > 0) {
                $tables[] = ['table' => $table, 'rows' => $rows];
            }
        }

        $trashedProjects = Project::onlyTrashed()->where('tenant_id', $tenantId)->get(['id', 'name', 'deleted_at']);

        return [
            'status' => 'ok',
            'read_only' => true,
            'tenant' => ['id' => $tenant->id, 'subdomain' => $tenant->subdomain],
            'tables_with_rows' => $tables,
            'row_count' => array_sum(array_column($tables, 'rows')),
            'soft_deleted_projects' => $trashedProjects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'deleted_at' => $project->deleted_at?->toISOString(),
            ])->all(),
            'account_deletion' => $this->accountDeletionFinding(),
            'notes' => $this->tenantNotes(),
        ];
    }

    /**
     * 默认视图：已软删的项目（删除动作用得最多、残留最容易被忽略的那一类）。
     *
     * @return array<string, mixed>
     */
    public function softDeletedProjects(int $limit = 50): array
    {
        $projects = Project::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->limit($limit)
            ->get(['id', 'name', 'tenant_id', 'deleted_at']);

        return [
            'status' => 'ok',
            'read_only' => true,
            'count' => $projects->count(),
            'truncated' => $projects->count() >= $limit,
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'tenant_id' => (int) $project->tenant_id,
                'deleted_at' => $project->deleted_at?->toISOString(),
            ])->all(),
            'hint' => '用 --project={id} 看单个项目删完之后还剩哪些行；--tenant={id} 看账户级残留。',
            'notes' => $this->projectNotes(),
        ];
    }

    /**
     * 报表目录盘点：找出"文件还在、行已经没了"的目录。
     *
     * 这类目录是**隐私上最尴尬的一类残留**：内容在磁盘上，但已经没有任何行能说明它属于谁，
     * 也就没法按租户删除，只能靠目录本身的 id 去找当时的执行记录。
     *
     * @return array<string, mixed>
     */
    public function reportDirectories(int $listingLimit = 50): array
    {
        try {
            $disk = Storage::disk('reports');
            $root = $disk->path('');
        } catch (Throwable $exception) {
            return [
                'status' => 'disk_unavailable',
                'read_only' => true,
                'reason' => $exception->getMessage(),
            ];
        }

        $orphans = [];
        $unmapped = [];
        $total = 0;

        foreach ($disk->directories() as $directory) {
            $prefix = basename($directory);
            $table = self::REPORT_DIR_TABLES[$prefix] ?? null;

            foreach ($disk->directories($directory) as $child) {
                $name = basename($child);
                if (! ctype_digit($name)) {
                    continue;
                }
                $total++;

                if ($table === null) {
                    $unmapped[] = ['path' => $child, 'prefix' => $prefix];

                    continue;
                }

                if (! DB::table($table)->where('id', (int) $name)->exists()) {
                    $orphans[] = [
                        'path' => $child,
                        'prefix' => $prefix,
                        'id' => (int) $name,
                        'table' => $table,
                        'bytes' => $this->directoryBytes($disk, $child),
                    ];
                }
            }
        }

        return [
            'status' => 'ok',
            'read_only' => true,
            'root' => $root,
            'scanned_directories' => $total,
            'orphan_count' => count($orphans),
            'orphan_bytes' => array_sum(array_column($orphans, 'bytes')),
            'orphans' => array_slice($orphans, 0, $listingLimit),
            'orphans_truncated' => count($orphans) > $listingLimit,
            'unmapped' => array_slice($unmapped, 0, $listingLimit),
        ];
    }

    /**
     * 按数据库真实结构找"带这一列的表"，不写死清单。
     *
     * @return list<string>
     */
    private function tablesWithColumn(string $column): array
    {
        if (isset($this->tablesByColumn[$column])) {
            return $this->tablesByColumn[$column];
        }

        $tables = [];
        $schema = Schema::getFacadeRoot();
        foreach ($schema->getTableListing() as $table) {
            $columns = array_column($schema->getColumns($table), 'name');
            if (in_array($column, $columns, true)) {
                $tables[] = self::plainTableName($table);
            }
        }
        sort($tables);

        return $this->tablesByColumn[$column] = $tables;
    }

    /** sqlite 的 `getTableListing()` 带 schema 前缀（`main.articles`），查询要用裸表名。 */
    private static function plainTableName(string $table): string
    {
        $position = strrpos($table, '.');

        return $position === false ? $table : substr($table, $position + 1);
    }

    private function directoryBytes(FilesystemAdapter $disk, string $directory): int
    {
        $bytes = 0;
        foreach ($disk->allFiles($directory) as $file) {
            try {
                $bytes += (int) $disk->size($file);
            } catch (Throwable) {
                // 单个文件读不到大小不该让整次盘点失败。
            }
        }

        return $bytes;
    }

    /** @return array<string, mixed> */
    private function accountDeletionFinding(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/tenants')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'DELETE') {
                    $routes[] = 'DELETE '.$uri;
                }
            }
        }

        return [
            'entry_point_exists' => $routes !== [],
            'routes' => $routes,
            'note' => $routes === []
                ? '本仓没有账户/租户删除入口（平台侧只有 pause / recover）——「客户要求删除账户」目前只能靠人工操作。'
                : '检出账户删除入口，删除后的残留请用 --tenant 盘点核对。',
        ];
    }

    /** @return list<string> */
    private function projectNotes(): array
    {
        return [
            '删除项目 = 进垃圾桶（2026-09-21 口径）：projects 软删、articles/skill_executions/project_stages 原地保留，'
                .'期内可恢复；geo:projects-purge --apply 期满才连坐真删。',
            'model_call_logs 按 2026-09-04 拍板**刻意保留**（与钱有关，必须有据可查）。',
            '其余带 project_id 的表当前一行不动 —— 保留期限与清理口径属规则问题，需产品确认后再定。',
            '行数为原始行数（含软删行），不走租户作用域 —— 盘点要的是库里的真相。',
        ];
    }

    /** @return list<string> */
    private function tenantNotes(): array
    {
        return [
            '这是账户级盘点：客户要求删除账户时，先看这张表能牵出多少行。',
            '报表文件没有租户归属（执行记录删掉后无从反查），要用 --project / 报表目录盘点单独核。',
            '本命令不删除任何行、不移动任何文件。',
        ];
    }

    /** 少数几张表的"为什么留着"是已经拍过板的，单独标注，免得被当成遗漏。 */
    private static function noteFor(string $table): ?string
    {
        return match ($table) {
            'model_call_logs' => '刻意保留：调用流水与钱有关（2026-09-04 拍板），删项目不删流水。',
            'knowledge_documents' => '未级联：项目删除后知识库文档仍在（规则未定，属待确认项）。',
            'tasks', 'url_imports', 'ai_visibility_runs', 'project_publishing_channels' => '未级联：删除接口没处理这张表。',
            'analytics_visit_logs', 'analytics_crawler_hits' => '未级联：访问统计仍在库里。',
            default => null,
        };
    }
}
