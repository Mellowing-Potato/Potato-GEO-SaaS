<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsCrawlerHit extends Model
{
    use HasFactory;
    use BelongsToTenant;

    protected $table = 'analytics_crawler_hits';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'article_id',
        'publishing_channel_id',
        'bot_name',
        'bot_family',
        'path',
        'ip_address',
        'hit_at',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'article_id' => 'integer',
            'publishing_channel_id' => 'integer',
            'hit_at' => 'immutable_date',
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
}
