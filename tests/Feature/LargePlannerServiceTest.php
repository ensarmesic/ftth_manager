<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\House;
use App\Models\Project;
use App\Services\LargePlanner\CorridorGraphBuilder;
use App\Services\LargePlanner\LargePlannerService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_graph_builder_creates_a_connected_typed_graph(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, 'main', [[43.8500, 18.4100], [43.8510, 18.4110]]);
        $this->corridor($project, 'secondary', [[43.8510, 18.4110], [43.8520, 18.4120]]);

        $graph = app(CorridorGraphBuilder::class)->build($project);

        $this->assertSame(2, $graph['summary']['corridors']);
        $this->assertSame(3, $graph['summary']['nodes']);
        $this->assertSame(2, $graph['summary']['edges']);
        $this->assertSame(1, $graph['summary']['components']);
        $this->assertSame('main', $graph['edges']['43.8500000,18.4100000']['43.8510000,18.4110000']['corridor_type']);
    }

    public function test_graph_builder_reports_disconnected_corridor_components(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $this->corridor($project, 'main', [[43.8500, 18.4100], [43.8510, 18.4110]]);
        $this->corridor($project, 'primary', [[44.0000, 19.0000], [44.0010, 19.0010]]);

        $graph = app(CorridorGraphBuilder::class)->build($project);

        $this->assertSame(2, $graph['summary']['components']);
        $this->assertCount(2, $graph['components']);
    }

    public function test_large_planner_orchestrator_prepares_input_without_persisting_network_elements(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => 16,
            'max_drop_length_m' => 150,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ]);
        $this->corridor($project, 'secondary', [[43.8500, 18.4100], [43.8600, 18.4200]]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8550, 'longitude' => 18.4150]);

        $prepared = app(LargePlannerService::class)->prepare($project);

        $this->assertTrue($prepared['readiness']['ready']);
        $this->assertSame(1, $prepared['graph']['summary']['components']);
        $this->assertSame(1, $prepared['cable_capacity']['summary']['segments']);
        $this->assertSame(4, $prepared['cable_capacity']['segments'][0]['fiber_count']);
        $this->assertDatabaseCount('cabinets', 0);
        $this->assertDatabaseCount('odfs', 0);
        $this->assertDatabaseCount('routes', 0);
    }

    public function test_standard_project_is_rejected_by_new_orchestrator(): void
    {
        $this->expectException(DomainException::class);

        app(LargePlannerService::class)->prepare(Project::factory()->create(['planning_mode' => 'standard']));
    }

    private function corridor(Project $project, string $type, array $path): GisSegment
    {
        return GisSegment::create([
            'project_id' => $project->id,
            'name' => ucfirst($type),
            'source' => 'test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => $type,
            'length_m' => 100,
            'path' => $path,
        ]);
    }
}
