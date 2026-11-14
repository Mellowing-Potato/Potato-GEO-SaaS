<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Project;
use App\Support\TenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TenantWorkerContextResetTest extends TestCase
{
    public function test_reused_worker_clears_context_before_and_after_jobs_even_on_failure(): void
    {
        $job = new SyncJob(app(), json_encode([
            'displayName' => 'synthetic', 'job' => 'synthetic', 'data' => [],
        ]), 'sync', 'default');
        $error = new \RuntimeException('synthetic failure');
        try {
            $tenantA = new Tenant(['name' => 'synthetic A']);
            $tenantA->id = 101;
            TenantContext::set($tenantA);
            Event::dispatch(new JobProcessing('database', $job));
            $this->assertFalse(TenantContext::has());
            $this->assertSame(-1, TenantContext::id());
            $this->assertContains(-1, Project::query()->getBindings());

            $tenantB = new Tenant(['name' => 'synthetic B']);
            $tenantB->id = 202;
            TenantContext::set($tenantB);
            $this->assertSame(202, TenantContext::id());
            Event::dispatch(new JobProcessed('database', $job));
            $this->assertNull(TenantContext::id());

            Event::dispatch(new JobProcessing('database', $job));
            TenantContext::set($tenantA);
            Event::dispatch(new JobExceptionOccurred('database', $job, $error));
            $this->assertSame(-1, TenantContext::id());
            Event::dispatch(new JobFailed('database', $job, $error));
            $this->assertSame(-1, TenantContext::id());
        } finally {
            TenantContext::endQueueJob();
        }
    }

    public function test_sync_job_events_preserve_the_http_requests_tenant(): void
    {
        $job = new SyncJob(app(), json_encode([
            'displayName' => 'synthetic', 'job' => 'synthetic', 'data' => [],
        ]), 'sync', 'default');
        $tenant = new Tenant(['name' => 'synthetic HTTP tenant']);
        $tenant->id = 303;
        $error = new \RuntimeException('synthetic failure');
        try {
            TenantContext::set($tenant);
            Event::dispatch(new JobProcessing('sync', $job));
            Event::dispatch(new JobExceptionOccurred('sync', $job, $error));
            Event::dispatch(new JobFailed('sync', $job, $error));
            Event::dispatch(new JobProcessed('sync', $job));
            $this->assertSame(303, TenantContext::id());
        } finally {
            TenantContext::endQueueJob();
        }
    }
}
