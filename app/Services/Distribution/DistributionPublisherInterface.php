<?php

namespace App\Services\Distribution;

use App\Models\ArticlePublication;
use App\Models\PublishingChannel;

interface DistributionPublisherInterface
{
    /**
     * Check target site health.
     * @return array{ok: bool, status?: string, message?: string}
     */
    public function health(PublishingChannel $channel): array;

    /**
     * Publish an article to the target site.
     * @return array{ok: bool, remote_id?: string, remote_url?: string, message?: string}
     */
    public function publish(ArticlePublication $publication): array;

    /**
     * Update a previously published article on the target site.
     */
    public function update(ArticlePublication $publication): array;

    /**
     * Delete a previously published article from the target site.
     */
    public function delete(ArticlePublication $publication): array;

    /**
     * Sync site settings to the target site (if supported).
     */
    public function syncSiteSettings(PublishingChannel $channel, array $settings): array;
}
