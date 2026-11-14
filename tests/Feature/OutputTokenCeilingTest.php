<?php

namespace Tests\Feature;

use App\Services\AI\AiProviderServiceImpl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\UsesWorkspaceModels;
use Tests\TestCase;

/** Caller token budgets are transmitted unchanged to the selected model. */
class OutputTokenCeilingTest extends TestCase
{
    use RefreshDatabase, UsesWorkspaceModels;

    public function test_requested_output_budget_is_not_capped_by_a_platform_tier(): void
    {
        $this->useWorkspaceModels();
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'test']]]])]);
        foreach ([16384, 99999, 999999] as $tokens) {
            $this->assertTrue(app(AiProviderServiceImpl::class)->generate('test', options: ['max_tokens' => $tokens])->success);
        }
        $this->assertSame([16384, 99999, 999999], Http::recorded()->map(fn ($pair) => $pair[0]['max_tokens'])->all());
        Http::assertSentCount(3);
        foreach (config('ai-providers') as $preset) {
            $this->assertArrayNotHasKey('model_max_tokens', $preset);
        }
    }
}
