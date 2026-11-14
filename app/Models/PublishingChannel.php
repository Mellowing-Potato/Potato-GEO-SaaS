<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PublishingChannel extends Model
{
    use BelongsToTenant, HasFactory;

    public const TYPE_WORDPRESS_REST = 'wordpress_rest';
    public const TYPE_HTTP_WEBHOOK = 'http_webhook';
    public const TYPE_GEOFLOW_AGENT = 'geoflow_agent';
    public const TYPE_HOSTED_PAGE = 'hosted_page';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'endpoint_url',
        'domain',
        'auth_type',
        'username',
        'encrypted_secret',
        'headers',
        'settings',
        'front_mode',
        'template_key',
        'site_settings',
        'channel_config',
        'is_active',
        'last_verified_at',
        'last_health_status',
        'last_health_checked_at',
        'last_error_message',
        'consecutive_failures',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_secret' => 'encrypted',
            'headers' => 'array',
            'settings' => 'array',
            'site_settings' => 'array',
            'channel_config' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_verified_at' => 'datetime',
            'last_health_checked_at' => 'datetime',
        ];
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ArticlePublication::class);
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(DistributionChannelSecret::class, 'publishing_channel_id');
    }

    public function activeSecret(): HasOne
    {
        return $this->hasOne(DistributionChannelSecret::class, 'publishing_channel_id')
            ->where('status', 'active');
    }

    public function distributionLogs(): HasMany
    {
        return $this->hasMany(DistributionLog::class, 'publishing_channel_id');
    }

    public function secret(): ?string
    {
        $secret = $this->encrypted_secret;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Normalize the channel type to a supported distribution publisher.
     */
    public function channelType(): string
    {
        return match ($this->type) {
            self::TYPE_HOSTED_PAGE => 'hosted_page',
            self::TYPE_GEOFLOW_AGENT => 'geoflow_agent',
            self::TYPE_WORDPRESS_REST => 'wordpress_rest',
            self::TYPE_HTTP_WEBHOOK => 'generic_http_api',
            default => 'generic_http_api',
        };
    }

    /**
     * Get the resolved channel configuration.
     */
    public function resolvedChannelConfig(): array
    {
        return $this->channel_config ?? [];
    }

    /**
     * Get the resolved site settings.
     */
    public function resolvedSiteSettings(): array
    {
        return $this->site_settings ?? [];
    }
}
