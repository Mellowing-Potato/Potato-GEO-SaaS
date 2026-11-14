<?php

namespace Tests\Unit;

use App\Jobs\RunAiVisibilityJob;
use App\Services\Geo\AiVisibility\AiVisibilityConfigurationResolver;
use Tests\TestCase;

final class AiVisibilityTimeoutTest extends TestCase
{
    public function test_gateway_budget_fits_http_and_queue_timeout_without_transport_retry(): void
    {
        $resolver = new AiVisibilityConfigurationResolver;
        $job = new RunAiVisibilityJob(1, 1);
        foreach ([10, 120, 250, 255, 999] as $configured) {
            config(['geo.visibility.http_timeout' => $configured]);
            $this->assertGreaterThan(240, $resolver->httpTimeoutSeconds());
            $this->assertLessThan($job->timeout, $resolver->httpTimeoutSeconds());
        }
    }
}
