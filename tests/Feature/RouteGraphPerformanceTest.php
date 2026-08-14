<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\Project;
use App\Services\RouteGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteGraphPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_spatial_grid_connects_nearby_segments_and_finds_the_shortest_path(): void
    {
        $project = Project::factory()->create();
        $this->segment($project, [[44.45, 18.65], [44.4501, 18.65]]);
        $this->segment($project, [[44.450108, 18.65], [44.4502, 18.65]]);

        $result = app(RouteGraphService::class)->shortestPath(
            $project->id,
            [44.45, 18.65],
            [44.4502, 18.65],
        );

        $this->assertNotNull($result);
        $this->assertGreaterThan(20, $result['length_m']);
        $this->assertLessThan(25, $result['length_m']);
        $this->assertLessThanOrEqual(2, $result['graph']['nearby_comparisons']);
    }

    public function test_spatial_grid_avoids_quadratic_comparisons_on_a_large_graph(): void
    {
        $project = Project::factory()->create();
        $path = [];
        for ($index = 0; $index < 2000; $index++) {
            $path[] = [44.4 + ($index * 0.00003), 18.6];
        }
        $this->segment($project, $path);

        $result = app(RouteGraphService::class)->shortestPath(
            $project->id,
            $path[0],
            $path[array_key_last($path)],
        );

        $this->assertNotNull($result);
        $this->assertSame(2004, $result['graph']['nodes']);
        $this->assertLessThan(10000, $result['graph']['nearby_comparisons']);
    }

    private function segment(Project $project, array $path): GisSegment
    {
        return GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Performance segment',
            'segment_type' => 'road',
            'is_allowed' => true,
            'path' => $path,
        ]);
    }
}
