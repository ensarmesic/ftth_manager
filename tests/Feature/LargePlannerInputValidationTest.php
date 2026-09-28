<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\House;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LargePlannerInputValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_input_is_ready_for_planning(): void
    {
        $project = $this->largeProject(150);
        $this->corridor($project, [[43.8560, 18.4130], [43.8570, 18.4140]]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8563, 'longitude' => 18.4131]);

        $this->getJson(route('projects.large-planner.input-validation', $project))
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('summary.errors', 0)
            ->assertJsonPath('summary.houses', 1);
    }

    public function test_disconnected_corridors_are_reported(): void
    {
        $project = $this->largeProject(150);
        $this->corridor($project, [[43.8560, 18.4130], [43.8570, 18.4140]]);
        $this->corridor($project, [[44.0000, 19.0000], [44.0010, 19.0010]]);

        $this->getJson(route('projects.large-planner.input-validation', $project))
            ->assertOk()
            ->assertJsonPath('ready', false)
            ->assertJsonPath('issues.0.code', 'disconnected_corridors')
            ->assertJsonCount(2, 'issues.0.details.components');
    }

    public function test_houses_without_coordinates_and_distant_houses_are_reported(): void
    {
        $project = $this->largeProject(50);
        $this->corridor($project, [[43.8560, 18.4130], [43.8570, 18.4140]]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => null, 'longitude' => null]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 44.0000, 'longitude' => 19.0000]);

        $response = $this->getJson(route('projects.large-planner.input-validation', $project))->assertOk();

        $this->assertFalse($response->json('ready'));
        $this->assertSame(
            ['houses_without_coordinates', 'houses_too_far_from_corridor'],
            collect($response->json('issues'))->pluck('code')->all(),
        );
    }

    public function test_corridor_crossing_a_restricted_area_blocks_readiness(): void
    {
        $project = $this->largeProject(150);
        $this->corridor($project, [[43.8560, 18.4130], [43.8580, 18.4150]]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8562, 'longitude' => 18.4132]);
        $project->largePlannerConstraints()->create([
            'type' => 'restricted_area',
            'name' => 'Zabranjena parcela',
            'geometry' => [[43.8568, 18.4138], [43.8572, 18.4138], [43.8572, 18.4142], [43.8568, 18.4142]],
        ]);

        $this->getJson(route('projects.large-planner.input-validation', $project))
            ->assertOk()
            ->assertJsonPath('ready', false)
            ->assertJsonPath('issues.0.code', 'corridors_cross_restricted_areas')
            ->assertJsonPath('issues.0.details.blocked_edges', 1);

        $this->getJson(route('projects.large-planner.readiness', $project))
            ->assertOk()
            ->assertJsonPath('ready', false);
    }

    public function test_required_waypoint_outside_allowed_corridor_blocks_readiness(): void
    {
        $project = $this->largeProject(150);
        $this->corridor($project, [[43.8560, 18.4130], [43.8570, 18.4140]]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8563, 'longitude' => 18.4133]);
        $project->largePlannerConstraints()->create([
            'type' => 'required_waypoint',
            'name' => 'Pogrešno postavljena tačka',
            'geometry' => [44.0, 19.0],
        ]);

        $this->getJson(route('projects.large-planner.input-validation', $project))
            ->assertOk()
            ->assertJsonPath('ready', false)
            ->assertJsonPath('issues.0.code', 'waypoints_outside_corridors');

        $this->getJson(route('projects.large-planner.readiness', $project))
            ->assertOk()
            ->assertJsonPath('ready', false);
    }

    public function test_standard_project_cannot_use_large_input_validation(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'standard']);

        $this->getJson(route('projects.large-planner.input-validation', $project))->assertNotFound();
    }

    public function test_readiness_lists_blocking_items_until_required_input_is_complete(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);

        $response = $this->getJson(route('projects.large-planner.readiness', $project))->assertOk();
        $this->assertFalse($response->json('ready'));
        $this->assertGreaterThan(0, $response->json('summary.blocking'));
        $this->assertContains('houses', collect($response->json('checks'))->where('status', 'blocked')->pluck('code')->all());
        $this->assertContains('odo_capacity', collect($response->json('checks'))->where('status', 'blocked')->pluck('code')->all());
    }

    public function test_project_is_ready_when_required_input_and_rules_are_valid(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create([
            'odo_capacity' => 16,
            'max_drop_length_m' => 150,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ]);
        $this->corridor($project, [[43.8560, 18.4130], [43.8570, 18.4140]]);
        House::factory()->create(['project_id' => $project->id, 'latitude' => 43.8563, 'longitude' => 18.4131]);

        $this->getJson(route('projects.large-planner.readiness', $project))
            ->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('summary.blocking', 0)
            ->assertJsonPath('summary.total', 9);
    }

    public function test_standard_project_cannot_use_large_planner_readiness(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'standard']);

        $this->getJson(route('projects.large-planner.readiness', $project))->assertNotFound();
    }

    public function test_readiness_estimate_uses_previous_project_measurements_when_available(): void
    {
        Storage::fake();
        $project = $this->largeProject(150);
        $path = "background-tasks/{$project->id}/measured.json";
        Storage::put($path, json_encode(['execution' => ['houses' => 100, 'duration_ms' => 5000]], JSON_THROW_ON_ERROR));
        $project->backgroundTasks()->create(['type' => 'large_plan', 'status' => 'completed', 'result_path' => $path]);

        $this->getJson(route('projects.large-planner.readiness', $project))
            ->assertOk()
            ->assertJsonPath('estimate.source', 'project_history')
            ->assertJsonPath('estimate.sample_count', 1)
            ->assertJsonPath('estimate.seconds', 2);
    }

    private function largeProject(int $dropLimit): Project
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $project->largePlannerSetting()->create(['max_drop_length_m' => $dropLimit]);

        return $project;
    }

    private function corridor(Project $project, array $path): GisSegment
    {
        return GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Koridor',
            'source' => 'test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'secondary',
            'length_m' => 100,
            'path' => $path,
        ]);
    }
}
