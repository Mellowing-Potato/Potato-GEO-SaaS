<?php

namespace App\Services\Distribution\Publishers;

use App\Models\ArticlePublication;
use App\Models\PublishingChannel;
use App\Services\Distribution\DistributionHttpClient;
use App\Services\Distribution\DistributionPayloadBuilder;
use App\Services\Distribution\DistributionPublisherInterface;

class GeoFlowAgentPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly DistributionHttpClient $httpClient,
        private readonly DistributionPayloadBuilder $payloadBuilder,
    ) {}

    public function health(PublishingChannel $channel): array
    {
        return $this->httpClient->health($channel);
    }

    public function publish(ArticlePublication $publication): array
    {
        $payload = $this->payloadBuilder->build($publication);
        $idempotencyKey = $this->payloadBuilder->idempotencyKey($publication);

        return $this->httpClient->publishArticle($publication->channel, $payload, $idempotencyKey);
    }

    public function update(ArticlePublication $publication): array
    {
        $remoteId = $publication->remote_id;
        if (empty($remoteId)) {
            return ['ok' => false, 'message' => 'No remote_id to update'];
        }

        $payload = $this->payloadBuilder->build($publication);
        $payload['action'] = 'update';

        return $this->httpClient->updateArticle($publication->channel, $remoteId, $payload);
    }

    public function delete(ArticlePublication $publication): array
    {
        $remoteId = $publication->remote_id;
        if (empty($remoteId)) {
            return ['ok' => false, 'message' => 'No remote_id to delete'];
        }

        return $this->httpClient->deleteArticle($publication->channel, $remoteId);
    }

    public function syncSiteSettings(PublishingChannel $channel, array $settings): array
    {
        return $this->httpClient->send(
            $channel,
            'POST',
            '/geoflow-agent/v1/site-settings',
            ['site_settings' => $settings],
            event: 'site_settings.sync',
        );
    }
}
