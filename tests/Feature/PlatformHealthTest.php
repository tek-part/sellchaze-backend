<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_probe_has_a_small_public_contract(): void
    {
        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertExactJsonStructure(['status', 'service', 'time'])
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'sellchaze-api');
    }

    public function test_readiness_probe_checks_platform_dependencies_without_exposing_details(): void
    {
        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertExactJsonStructure([
                'status',
                'checks' => ['database', 'cache', 'outbox'],
                'time',
            ])
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true)
            ->assertJsonPath('checks.outbox', true);
    }
}
