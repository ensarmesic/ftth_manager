<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\House;
use App\Models\Project;
use App\Services\GeometryService;
use App\Services\LargePlanner\CorridorGraphBuilder;
use App\Services\LargePlanner\HouseClusterer;
use App\Services\LargePlanner\OdoPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerOdoPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_proposed_odo_is_on_an_allowed_corridor_and_respects_capacity(): void
    {
        [$project, $corridor] = $this->projectWithCorridor(4, 150);
        for ($index = 0; $index < 7; $index++) {
            House::factory()->create([
                'project_id' => $project->id,
                'latitude' => 43.8501 + ($index * 0.00005),
                'longitude' => 18.4101 + ($index * 0.00005),
            ]);
        }

        $result = $this->propose($project);

        $this->assertCount(2, $result['odos']);
        foreach ($result['odos'] as $odo) {
            $this->assertSame($corridor->id, $odo['corridor_id']);
            $this->assertLessThan(0.2, app(GeometryService::class)->distanceToRoute($odo['point'][0], $odo['point'][1], $corridor->path));
            $this->assertLessThanOrEqual(4, $odo['occupancy']);
            $this->assertLessThanOrEqual($odo['capacity'], $odo['occupancy']);
        }
        $this->assertDatabaseCount('cabinets', 0);
    }

    public function test_placement_reports_when_one_odo_cannot_cover_spread_out_houses(): void
    {
        [$project] = $this->projectWithCorridor(16, 40);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8500, 'longitude' => 18.4100]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8550, 'longitude' => 18.4150]);

        $result = $this->propose($project);

        $this->assertCount(1, $result['odos']);
        $this->assertFalse($result['odos'][0]['within_drop_limit']);
        $this->assertSame('odo_drop_limit_exceeded', $result['warnings'][0]['code']);
    }

    public function test_same_clusters_produce_identical_odo_placement(): void
    {
        [$project] = $this->projectWithCorridor(8, 150);
        House::factory()->count(5)->create(['project_id' => $project->id, 'latitude' => 43.8502, 'longitude' => 18.4102]);

        $this->assertSame($this->propose($project), $this->propose($project));
    }

    private function propose(Project $project): array
    {
        $graph = app(CorridorGraphBuilder::class)->build($project);
        $clusters = app(HouseClusterer::class)->cluster($project, $graph);

        return app(OdoPlacementService::class)->propose($project, $clusters);
    }

    private function projectWithCorridor(int $capacity, int $maxDrop): array
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => $capacity,
            'max_drop_length_m' => $maxDrop,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ]);
        $corridor = GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Sekundarni koridor',
            'source' => 'test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'secondary',
            'length_m' => 2000,
            'path' => [[43.8500, 18.4100], [43.8600, 18.4200]],
        ]);

        return [$project, $corridor];
    }
}
