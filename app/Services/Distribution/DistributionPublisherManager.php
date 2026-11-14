<?php

namespace App\Services\Distribution;

use App\Models\PublishingChannel;
use App\Services\Distribution\Publishers\GeoFlowAgentPublisher;
use App\Services\Distribution\Publishers\GenericHttpPublisher;
use App\Services\Distribution\Publishers\WordPressRestPublisher;
use RuntimeException;

class DistributionPublisherManager
{
    public function __construct(
        private readonly GeoFlowAgentPublisher $agentPublisher,
        private readonly WordPressRestPublisher $wordPressPublisher,
        private readonly GenericHttpPublisher $genericHttpPublisher,
    ) {}

    /**
     * Select the appropriate publisher based on channel type.
     */
    public function publisherFor(PublishingChannel $channel): DistributionPublisherInterface
    {
        return match ($channel->channelType()) {
            'hosted_page' => throw new RuntimeException('站内托管渠道已退役。'),
            'geoflow_agent' => $this->agentPublisher,
            'wordpress_rest' => $this->wordPressPublisher,
            default => $this->genericHttpPublisher,
        };
    }
}
