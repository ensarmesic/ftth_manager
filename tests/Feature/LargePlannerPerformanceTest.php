<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\House;
use App\Models\Odf;
use App\Models\Project;
use App\Services\LargePlanner\LargePlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class LargePlannerPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public static function houseCounts(): array
    {
        return [[100], [500], [1000], [2000]];
    }

    #[DataProvider('houseCounts')]
    public function test_planner_processes_large_synthetic_projects_in_batches(int $houseCount): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => 16,
            'max_drop_length_m' => 150,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ]);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Sintetički koridor',
            'source' => 'performance-test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'secondary',
            'length_m' => 1200,
            'path' => [[43.85, 18.41], [43.86, 18.42]],
        ]);
        Odf::factory()->create(['project_id' => $project->id, 'latitude' => 43.85, 'longitude' => 18.41]);
        $this->insertHouses($project, $houseCount);

        $memoryBefore = memory_get_usage(true);
        $startedAt = hrtime(true);
        $result = app(LargePlannerService::class)->prepare($project);
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        $memoryDelta = max(0, memory_get_peak_usage(true) - $memoryBefore);

        $this->assertSame($houseCount, $result['clustering']['summary']['houses']);
        $this->assertSame($houseCount, $result['clustering']['summary']['clustered_houses']);
        $this->assertSame($houseCount, $result['routes']['summary']['drop_routes']);
        $this->assertSame(500, $result['clustering']['summary']['batch_size']);
        $this->assertLessThan(30, $elapsedSeconds, "Planiranje {$houseCount} kuća traje predugo.");
        $this->assertLessThan(256 * 1024 * 1024, $memoryDelta, "Planiranje {$houseCount} kuća koristi previše memorije.");
        $this->assertLessThan(20 * 1024 * 1024, strlen(json_encode($result, JSON_THROW_ON_ERROR)));
    }

    private function insertHouses(Project $project, int $count): void
    {
        $now = now();
        foreach (array_chunk(range(0, $count - 1), 500) as $indexes) {
            House::query()->insert(array_map(function (int $index) use ($project, $count, $now): array {
                $ratio = $count === 1 ? 0.5 : $index / ($count - 1);
                $latitude = 43.85 + (0.01 * $ratio);
                $longitude = 18.41 + (0.01 * $ratio);

                return [
                    'project_id' => $project->id,
                    'label' => 'S-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT),
                    'status' => 'planned',
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $indexes));
        }
    }
}
