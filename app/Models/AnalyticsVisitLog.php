<?php

namespace App\Models;

use App\Support\Analytics\TrafficClassifier;
use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsVisitLog extends Model
{
    use HasFactory;
    use BelongsToTenant;

    protected $table = 'analytics_visit_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'article_id',
        'publishing_channel_id',
        'ip_address',
        'user_agent',
        'bot_name',
        'bot_family',
        'referer',
        'path',
        'method',
        'status_code',
        'traffic_type',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'article_id' => 'integer',
            'publishing_channel_id' => 'integer',
            'status_code' => 'integer',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(PublishingChannel::class, 'publishing_channel_id');
    }

    public function isBot(): bool
    {
        return in_array($this->traffic_type, [
            TrafficClassifier::AI_BOT,
            TrafficClassifier::SEARCH_BOT,
            TrafficClassifier::OTHER_BOT,
        ], true);
    }
}
