<?php

namespace Tests\Unit;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\Project;
use App\Services\ManualCabinetHouseAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualCabinetHouseAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_balances_houses_by_nearest_manual_cabinet_without_a_distance_limit(): void
    {
        $project = Project::factory()->create();
        $first = Cabinet::factory()->for($project)->create(['latitude' => 44.0, 'longitude' => 18.0]);
        $second = Cabinet::factory()->for($project)->create(['latitude' => 44.0, 'longitude' => 18.02]);

        foreach (range(1, 6) as $index) {
            House::factory()->for($project)->create(['latitude' => 44.0, 'longitude' => 18.0 + ($index * 0.0001)]);
            House::factory()->for($project)->create(['latitude' => 44.0, 'longitude' => 18.02 - ($index * 0.0001)]);
        }

        app(ManualCabinetHouseAssignmentService::class)->assign(collect([$first, $second]), 6);

        $this->assertSame(6, $first->houses()->count());
        $this->assertSame(6, $second->houses()->count());
        $this->assertTrue($first->houses()->get()->every(fn (House $house) => $house->longitude < 18.01));
        $this->assertTrue($second->houses()->get()->every(fn (House $house) => $house->longitude > 18.01));
    }

    public function test_strict_mode_does_not_pull_houses_away_from_their_nearest_cabinet_to_force_twelve(): void
    {
        $project = Project::factory()->create();
        $first = Cabinet::factory()->for($project)->create(['latitude' => 44.0, 'longitude' => 18.0]);
        $second = Cabinet::factory()->for($project)->create(['latitude' => 44.0, 'longitude' => 18.02]);
        foreach (range(1, 4) as $index) {
            House::factory()->for($project)->create(['latitude' => 44.0, 'longitude' => 18.0 + ($index * 0.0001)]);
        }
        foreach (range(1, 8) as $index) {
            House::factory()->for($project)->create(['latitude' => 44.0, 'longitude' => 18.02 - ($index * 0.0001)]);
        }

        app(ManualCabinetHouseAssignmentService::class)->assignStrictlyNearest(collect([$first, $second]), 12, 3);

        $this->assertSame(4, $first->houses()->count());
        $this->assertSame(8, $second->houses()->count());
    }
}
