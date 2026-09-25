<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_versioned_health_endpoint_reports_deployment_metadata(): void
    {
        config([
            'app.version' => 'abc1234',
            'app.deployed_at' => '2026-09-25T20:00:00Z',
        ]);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'version' => 'abc1234',
                'deployed_at' => '2026-09-25T20:00:00Z',
            ]);
    }
}
