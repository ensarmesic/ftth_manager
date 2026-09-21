<?php

namespace Tests\Unit;

use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Services\AsBuiltComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AsBuiltComparisonServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_compares_planned_and_built_network_state(): void
    {
        $project = Project::factory()->create();
        NetworkRoute::factory()->create(['project_id' => $project->id, 'status' => 'completed', 'duct_length_m' => 100]);
        NetworkRoute::factory()->create(['project_id' => $project->id, 'status' => 'planned', 'duct_length_m' => 300]);
        House::factory()->create(['project_id' => $project->id, 'status' => 'connected']);
        House::factory()->create(['project_id' => $project->id, 'status' => 'planned']);

        $result = app(AsBuiltComparisonService::class)->build($project);
        $this->assertSame(['planned' => 2, 'built' => 1, 'percent' => 50], $result['routes']);
        $this->assertSame(25, $result['route_length_m']['percent']);
        $this->assertSame(-300.0, $result['route_length_m']['variance']);
        $this->assertSame(['planned' => 2, 'built' => 1, 'percent' => 50], $result['houses']);
    }
}
