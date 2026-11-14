<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionLog extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'publishing_channel_id',
        'article_publication_id',
        'article_id',
        'level',
        'event',
        'message',
        'context',
        'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(PublishingChannel::class, 'publishing_channel_id');
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(ArticlePublication::class, 'article_publication_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Convenience factory for writing distribution log entries.
     */
    public static function write(
        string $level,
        string $message,
        ?PublishingChannel $channel = null,
        ?ArticlePublication $publication = null,
        ?Article $article = null,
        ?string $event = null,
        ?array $context = null,
    ): self {
        return self::query()->create([
            'publishing_channel_id' => $channel?->id,
            'article_publication_id' => $publication?->id,
            'article_id' => $article?->id,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
