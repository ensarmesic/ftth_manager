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
            $this->assertGreaterThanOrEqual(3, $odo['occupancy']);
            $this->assertLessThanOrEqual($odo['capacity'], $odo['occupancy']);
        }
        $this->assertDatabaseCount('cabinets', 0);
    }

    public function test_placement_does_not_create_cabinets_for_fewer_than_three_houses(): void
    {
        [$project] = $this->projectWithCorridor(16, 40);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8500, 'longitude' => 18.4100]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8550, 'longitude' => 18.4150]);

        $result = $this->propose($project);

        $this->assertCount(0, $result['odos']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('odo_minimum_houses_not_met', $result['warnings'][0]['code']);
    }

    public function test_nine_houses_are_balanced_as_six_plus_three_instead_of_eight_plus_one(): void
    {
        [$project] = $this->projectWithCorridor(8, 150);
        for ($index = 0; $index < 9; $index++) {
            House::factory()->create([
                'project_id' => $project->id,
                'latitude' => 43.8501 + ($index * 0.00002),
                'longitude' => 18.4101 + ($index * 0.00002),
            ]);
        }

        $result = $this->propose($project);

        $this->assertSame([3, 6], collect($result['odos'])->pluck('occupancy')->sort()->values()->all());
        $this->assertTrue(collect($result['odos'])->every(fn (array $odo) => $odo['occupancy'] >= 3));
    }

    public function test_cabinets_prefer_eleven_or_twelve_houses_when_capacity_allows_it(): void
    {
        [$project] = $this->projectWithCorridor(16, 150);
        for ($index = 0; $index < 23; $index++) {
            House::factory()->create([
                'project_id' => $project->id,
                'latitude' => 43.8501 + ($index * 0.00001),
                'longitude' => 18.4101 + ($index * 0.00001),
            ]);
        }

        $result = $this->propose($project);

        $this->assertSame([11, 12], collect($result['odos'])->pluck('occupancy')->sort()->values()->all());
        $this->assertSame([], $result['warnings']);
    }

    public function test_twenty_houses_are_balanced_without_one_weakly_filled_cabinet(): void
    {
        [$project] = $this->projectWithCorridor(16, 150);
        for ($index = 0; $index < 20; $index++) {
            House::factory()->create([
                'project_id' => $project->id,
                'latitude' => 43.8501 + ($index * 0.00001),
                'longitude' => 18.4101 + ($index * 0.00001),
            ]);
        }

        $result = $this->propose($project);

        $this->assertSame([10, 10], collect($result['odos'])->pluck('occupancy')->sort()->values()->all());
        $this->assertCount(2, collect($result['warnings'])->where('code', 'odo_below_preferred_occupancy'));
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

        return app(OdoPlacementService::class)->propose($project, $clusters, $graph);
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
