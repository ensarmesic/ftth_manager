<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\House;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerInputSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_captures_an_immutable_large_planner_input_revision(): void
    {
        $project = $this->projectWithInput();

        $first = $this->postJson(route('projects.large-planner.input-snapshots.store', $project), [
            'label' => 'Priprema A',
        ])->assertCreated()->json('snapshot');

        $house = House::where('project_id', $project->id)->firstOrFail();
        $house->update(['latitude' => 43.9000]);

        $second = $this->postJson(route('projects.large-planner.input-snapshots.store', $project))
            ->assertCreated()->json('snapshot');

        $this->assertSame(1, $first['revision']);
        $this->assertSame(2, $second['revision']);
        $this->assertNotSame($first['checksum'], $second['checksum']);
        $this->assertSame(43.8563, $first['payload']['houses'][0]['latitude']);
        $this->assertSame(43.9, $second['payload']['houses'][0]['latitude']);
    }

    public function test_identical_input_produces_the_same_checksum(): void
    {
        $project = $this->projectWithInput();

        $first = $this->postJson(route('projects.large-planner.input-snapshots.store', $project))->assertCreated()->json('snapshot');
        $second = $this->postJson(route('projects.large-planner.input-snapshots.store', $project))->assertCreated()->json('snapshot');

        $this->assertSame($first['checksum'], $second['checksum']);
        $this->getJson(route('projects.large-planner.input-snapshots.index', $project))
            ->assertOk()
            ->assertJsonCount(2, 'snapshots')
            ->assertJsonPath('snapshots.0.revision', 2);
    }

    public function test_standard_project_cannot_create_large_planner_input_snapshot(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'standard']);

        $this->postJson(route('projects.large-planner.input-snapshots.store', $project))->assertNotFound();
        $this->assertDatabaseCount('large_planner_input_snapshots', 0);
    }

    private function projectWithInput(): Project
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => 16,
            'max_drop_length_m' => 120,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ]);
        House::factory()->create([
            'project_id' => $project->id,
            'label' => 'K-001',
            'latitude' => 43.8563,
            'longitude' => 18.4131,
        ]);
        GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Glavni koridor',
            'source' => 'test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'main',
            'length_m' => 100,
            'path' => [[43.8560, 18.4130], [43.8570, 18.4140]],
        ]);

        return $project;
    }
}
