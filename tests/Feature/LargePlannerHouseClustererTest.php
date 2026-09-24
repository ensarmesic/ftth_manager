<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\House;
use App\Models\Project;
use App\Services\LargePlanner\CorridorGraphBuilder;
use App\Services\LargePlanner\HouseClusterer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LargePlannerHouseClustererTest extends TestCase
{
    use RefreshDatabase;

    public function test_houses_are_grouped_by_capacity_and_geographic_order(): void
    {
        $project = $this->project(4, 100);
        $this->corridor($project);
        for ($index = 0; $index < 10; $index++) {
            House::factory()->create([
                'project_id' => $project->id,
                'label' => 'K-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'latitude' => 43.8501 + ($index * 0.00005),
                'longitude' => 18.4101 + ($index * 0.00005),
            ]);
        }

        $result = $this->cluster($project);

        $this->assertSame(3, $result['summary']['clusters']);
        $this->assertSame(10, $result['summary']['clustered_houses']);
        $this->assertSame([4, 4, 2], array_column($result['clusters'], 'house_count'));
        $this->assertTrue(collect($result['clusters'])->every(fn (array $cluster) => $cluster['max_drop_distance_m'] <= 100));
    }

    public function test_houses_are_not_mixed_between_zones(): void
    {
        $project = $this->project(16, 100);
        $this->corridor($project);
        $zoneA = $project->largePlannerZones()->create(['name' => 'A', 'geometry' => [[43.84, 18.40], [43.86, 18.40], [43.86, 18.42]]]);
        $zoneB = $project->largePlannerZones()->create(['name' => 'B', 'geometry' => [[43.84, 18.42], [43.86, 18.42], [43.86, 18.44]]]);
        House::factory()->count(2)->create(['project_id' => $project->id, 'large_planner_zone_id' => $zoneA->id, 'latitude' => 43.8501, 'longitude' => 18.4101]);
        House::factory()->count(2)->create(['project_id' => $project->id, 'large_planner_zone_id' => $zoneB->id, 'latitude' => 43.8502, 'longitude' => 18.4102]);

        $result = $this->cluster($project);

        $this->assertCount(2, $result['clusters']);
        $this->assertEqualsCanonicalizing([$zoneA->id, $zoneB->id], array_column($result['clusters'], 'zone_id'));
    }

    public function test_house_outside_max_drop_is_reported_without_a_cluster(): void
    {
        $project = $this->project(16, 30);
        $this->corridor($project);
        $house = House::factory()->create(['project_id' => $project->id, 'latitude' => 44.0000, 'longitude' => 19.0000]);

        $result = $this->cluster($project);

        $this->assertSame(0, $result['summary']['clustered_houses']);
        $this->assertSame(1, $result['summary']['unroutable_houses']);
        $this->assertSame($house->id, $result['unroutable_houses'][0]['id']);
        $this->assertSame('outside_max_drop', $result['unroutable_houses'][0]['reason']);
    }

    public function test_same_input_produces_identical_clusters(): void
    {
        $project = $this->project(3, 100);
        $this->corridor($project);
        House::factory()->count(7)->create(['project_id' => $project->id, 'latitude' => 43.8502, 'longitude' => 18.4102]);

        $first = $this->cluster($project);
        $second = $this->cluster($project);

        $this->assertSame($first, $second);
    }

    public function test_large_mode_processes_more_than_three_hundred_houses_in_batches(): void
    {
        $project = $this->project(16, 100);
        $this->corridor($project);
        $now = now();
        foreach (array_chunk(range(1, 620), 200) as $numbers) {
            DB::table('houses')->insert(array_map(fn (int $number) => [
                'project_id' => $project->id,
                'label' => 'B-'.$number,
                'status' => 'planned',
                'latitude' => 43.8501 + ($number * 0.000001),
                'longitude' => 18.4101 + ($number * 0.000001),
                'created_at' => $now,
                'updated_at' => $now,
            ], $numbers));
        }

        $result = $this->cluster($project);

        $this->assertSame(620, $result['summary']['houses']);
        $this->assertSame(620, $result['summary']['clustered_houses']);
        $this->assertSame(39, $result['summary']['clusters']);
        $this->assertSame(500, $result['summary']['batch_size']);
    }

    private function cluster(Project $project): array
    {
        $graph = app(CorridorGraphBuilder::class)->build($project);

        return app(HouseClusterer::class)->cluster($project, $graph);
    }

    private function project(int $capacity, int $maxDrop): Project
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => $capacity,
            'max_drop_length_m' => $maxDrop,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ]);

        return $project;
    }

    private function corridor(Project $project): GisSegment
    {
        return GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Koridor',
            'source' => 'test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'secondary',
            'length_m' => 2000,
            'path' => [[43.8500, 18.4100], [43.8600, 18.4200]],
        ]);
    }
}
