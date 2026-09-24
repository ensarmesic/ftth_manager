<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\LargePlanner\CableCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerCableCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_and_shared_segments_are_sized_with_reserve(): void
    {
        $project = $this->project(20);
        $placement = ['odos' => [
            ['key' => 'odo-0001', 'occupancy' => 8],
            ['key' => 'odo-0002', 'occupancy' => 4],
        ]];
        $odfs = ['odfs' => [[
            'key' => 'odf-0001',
            'odo_keys' => ['odo-0001', 'odo-0002'],
        ]], 'primary_routes' => [[
            'key' => 'primary-1', 'to_odf_key' => 'odf-0001',
            'path' => [[43.85, 18.41], [43.851, 18.411]],
        ]]];
        $routes = [
            'secondary_routes' => [
                ['key' => 'secondary-1', 'odo_key' => 'odo-0001', 'path' => [[43.85, 18.41], [43.851, 18.411]]],
                ['key' => 'secondary-2', 'odo_key' => 'odo-0002', 'path' => [[43.851, 18.411], [43.85, 18.41]]],
            ],
            'drop_routes' => [['key' => 'drop-1', 'path' => [[43.851, 18.411], [43.852, 18.412]]]],
        ];

        $result = app(CableCapacityService::class)->calculate($project, $placement, $odfs, $routes);

        $this->assertSame(12, $result['routes']['secondary_routes'][0]['fiber_count']);
        $this->assertSame(2, $result['routes']['secondary_routes'][0]['reserve_fibers']);
        $this->assertSame(4, $result['routes']['drop_routes'][0]['fiber_count']);
        $shared = collect($result['segments'])->first(fn (array $segment) => count($segment['route_keys']) === 3);
        $this->assertNotNull($shared);
        $this->assertSame(24, $shared['load_fibers']);
        $this->assertSame(48, $shared['fiber_count']);
        $this->assertSame(0, $result['summary']['overloaded_segments']);
    }

    public function test_capacity_overflow_is_reported_without_persisting_routes(): void
    {
        $project = $this->project(20);
        $placement = ['odos' => [['key' => 'odo-0001', 'occupancy' => 48]]];
        $result = app(CableCapacityService::class)->calculate($project, $placement, ['odfs' => [], 'primary_routes' => []], [
            'secondary_routes' => [['key' => 'secondary-1', 'odo_key' => 'odo-0001', 'path' => [[43.85, 18.41], [43.851, 18.411]]]],
            'drop_routes' => [],
        ]);

        $this->assertNull($result['segments'][0]['fiber_count']);
        $this->assertTrue($result['segments'][0]['overloaded']);
        $this->assertSame('cable_capacity_exceeded', $result['warnings'][0]['code']);
        $this->assertDatabaseCount('routes', 0);
    }

    private function project(int $reserve): Project
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => 48,
            'max_drop_length_m' => 150,
            'fiber_reserve_percent' => $reserve,
            'optimization_goal' => 'weighted',
        ]);

        return $project;
    }
}
