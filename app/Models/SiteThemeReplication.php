<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/SiteThemeReplication.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 主题包生成任务（对客文案叫「主题包生成」，原「站点主题复制」已由老板 09-01 拍板改名）
 *
 * 与上游 GEOFlow v2.3.0 的差异，逐条都在交付报告里记了：
 * - 加 tenant_id + BelongsToTenant（多租户隔离，上游是单租户后台）
 * - 去掉 ai_model_id 及其关联（上游空壳字段：存了、加载了、一次都没用，抄过来是误导）
 * - created_by_admin_id → created_by_user_id（geo-saas 用 User，上游用 Admin）
 * - 去掉 canPublish / canBeArchived / canDeleteDrafts（对应端点本单不做）
 *
 * ⚠️ 这个功能不接 AI：不管输入哪个参考站，产出的都是同一套 Blade 骨架，
 * 只是换了配色和字体（ThemeReplicationAgent::generateBlueprint 是纯 PHP 拼装）。
 * 它不会复刻出参考站的版式。
 */
class SiteThemeReplication extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_FETCHING = 'fetching';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_SCANNING = 'scanning';

    public const STATUS_ITERATING = 'iterating';

    public const STATUS_FAILED = 'failed';

    public const STATUS_READY = 'ready';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * ⚠️ tenant_id 故意不在 fillable 里：防批量赋值改租户。
     * 创建时由 BelongsToTenant 的 creating hook 自动填。
     *
     * （注：本仓 KnowledgeDocument 等既有模型是把 tenant_id 放进 fillable 的，
     * 那是旧惯例；新模型按执行书 T2 走更严的做法。）
     */
    protected $fillable = [
        'name',
        'theme_id',
        'base_theme_id',
        'status',
        'home_url',
        'category_url',
        'article_url',
        'style_preference',
        'source_fingerprints',
        'analysis_json',
        'generated_files_json',
        'preview_snapshot_json',
        'current_version',
        'published_theme_path',
        'published_asset_path',
        'compliance_status',
        'compliance_report_json',
        'iteration_count',
        'error_message',
        'created_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'source_fingerprints' => 'array',
            'analysis_json' => 'array',
            'generated_files_json' => 'array',
            'preview_snapshot_json' => 'array',
            'current_version' => 'integer',
            'compliance_report_json' => 'array',
            'iteration_count' => 'integer',
            'created_by_user_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SiteThemeReplicationLog::class, 'replication_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SiteThemeReplicationVersion::class, 'replication_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isPreviewReady(): bool
    {
        return in_array((string) $this->status, [self::STATUS_READY, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true)
            && ! empty($this->preview_snapshot_json);
    }

    public function canPackage(): bool
    {
        $manifest = $this->generated_files_json;
        $files = is_array($manifest) ? ($manifest['files'] ?? null) : null;
        $complianceReport = $this->compliance_report_json;

        return in_array((string) $this->status, [
            self::STATUS_READY,
            self::STATUS_PUBLISHED,
            self::STATUS_ARCHIVED,
        ], true)
            && (int) $this->current_version > 0
            && is_array($manifest)
            && $manifest !== []
            && is_array($files)
            && $files !== []
            && (string) $this->compliance_status === 'passed'
            && is_array($complianceReport)
            && ($complianceReport['passed'] ?? null) === true;
    }

    /**
     * @return array<int, string>
     */
    public function sourceDomains(): array
    {
        $domains = [];
        foreach (['home_url', 'category_url', 'article_url'] as $field) {
            $host = parse_url((string) $this->{$field}, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domains[] = strtolower($host);
            }
        }

        return array_values(array_unique($domains));
    }
}
