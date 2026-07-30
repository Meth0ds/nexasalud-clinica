<?php

namespace Tests\Feature;

use App\Services\ApplicationReadiness;
use Mockery;
use Tests\TestCase;

class ReadinessEndpointTest extends TestCase
{
    public function test_it_reports_readiness_without_exposing_operational_details(): void
    {
        $probe = Mockery::mock(ApplicationReadiness::class);
        $probe->shouldReceive('isReady')->once()->andReturnTrue();
        $this->app->instance(ApplicationReadiness::class, $probe);

        $this->getJson('/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ready'])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Trace-Id')
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_it_does_not_accept_traffic_when_a_required_dependency_is_unavailable(): void
    {
        $probe = Mockery::mock(ApplicationReadiness::class);
        $probe->shouldReceive('isReady')->once()->andReturnFalse();
        $this->app->instance(ApplicationReadiness::class, $probe);

        $this->getJson('/ready')
            ->assertServiceUnavailable()
            ->assertExactJson(['status' => 'unavailable'])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Set-Cookie');
    }
}
