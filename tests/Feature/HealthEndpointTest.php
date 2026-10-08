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

    public function test_health_falls_back_to_git_revision_when_version_is_unset(): void
    {
        config(['app.version' => 'unknown', 'app.deployed_at' => null]);

        $version = $this->getJson('/api/v1/health')->assertOk()->json('version');

        $this->assertMatchesRegularExpression('/^([0-9a-f]{7}|unknown)$/', $version);
        if (is_dir(base_path('.git'))) {
            $this->assertNotSame('unknown', $version);
        }
    }
}
