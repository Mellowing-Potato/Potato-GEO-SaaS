<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiVisibilityStatusSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_column_does_not_truncate_recovery_states(): void
    {
        $this->assertSame('text', strtolower(Schema::getColumnType('ai_visibility_runs', 'status', true)));
    }
}
