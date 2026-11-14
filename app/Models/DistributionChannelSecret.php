<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use App\Models\PublishingChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionChannelSecret extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'publishing_channel_id',
        'key_id',
        'secret_ciphertext',
        'status',
        'scopes',
        'last_used_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = ['secret_ciphertext'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(PublishingChannel::class, 'publishing_channel_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Generate a new key/secret pair for a channel.
     */
    public static function generateFor(PublishingChannel $channel): self
    {
        $keyId = 'gfk_' . bin2hex(random_bytes(16));
        $secret = 'gfsec_' . bin2hex(random_bytes(32));

        // 渠道类型决定 scope：WP 渠道的密钥只用于信标；Agent 包 1.1.0 起本就带 beacon 能力。
        // 端点侧不强制校验 scopes（二期设计），故仅在此定默认值即可。
        $scopes = $channel->type === PublishingChannel::TYPE_WORDPRESS_REST
            ? ['beacon']
            : ['article.publish', 'article.delete', 'health.check', 'beacon'];

        return self::query()->create([
            'publishing_channel_id' => $channel->id,
            'key_id' => $keyId,
            'secret_ciphertext' => encrypt($secret),
            'status' => 'active',
            'scopes' => $scopes,
        ]);
    }

    /**
     * Decrypt and return the raw secret (use sparingly).
     */
    public function decryptSecret(): string
    {
        return decrypt($this->secret_ciphertext);
    }

    /**
     * Revoke this secret (rotate to a new one).
     */
    public function revoke(): void
    {
        $this->update(['status' => 'revoked']);
    }

    /**
     * Record usage timestamp.
     */
    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->save();
    }
}
