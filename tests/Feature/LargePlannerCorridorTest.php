<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerCorridorTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_trenches_can_be_synced_as_allowed_corridors_without_duplicates(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $route = NetworkRoute::create([
            'project_id' => $project->id,
            'name' => 'Glavni rov 5',
            'route_type' => 'trench',
            'duct_length_m' => 200,
            'path' => [[43.85, 18.41], [43.86, 18.42]],
        ]);

        $this->postJson(route('projects.large-planner.corridors.sync-trenches', $project))
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 0)
            ->assertJsonPath('total', 1);

        $this->assertDatabaseHas('gis_segments', [
            'project_id' => $project->id,
            'name' => 'Glavni rov 5',
            'source' => 'large-planner-trench',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'planning_corridor_type' => 'main',
            'length_m' => 200,
        ]);
        $segment = GisSegment::query()->where('source', 'large-planner-trench')->firstOrFail();
        $this->assertSame($route->id, (int) data_get($segment->properties, 'source_route_id'));

        $route->update(['path' => [[43.87, 18.43], [43.88, 18.44]]]);
        $this->postJson(route('projects.large-planner.corridors.sync-trenches', $project))
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 1);

        $this->assertSame(1, GisSegment::query()->where('source', 'large-planner-trench')->count());
        $this->assertSame($route->fresh()->path, $segment->fresh()->path);
    }

    public function test_trench_sync_is_not_available_for_standard_projects(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'standard']);
        NetworkRoute::create([
            'project_id' => $project->id,
            'name' => 'Postojeći rov',
            'route_type' => 'trench',
            'path' => [[43.85, 18.41], [43.86, 18.42]],
        ]);

        $this->postJson(route('projects.large-planner.corridors.sync-trenches', $project))->assertNotFound();
        $this->assertDatabaseCount('gis_segments', 0);
    }

    public function test_trench_sync_requires_a_saved_trench_with_geometry(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);

        $this->postJson(route('projects.large-planner.corridors.sync-trenches', $project))
            ->assertUnprocessable()
            ->assertJsonPath('total', 0);
    }

    public function test_allowed_segments_can_be_classified_for_a_large_project(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $segment = $this->segment($project);

        foreach (GisSegment::PLANNING_CORRIDOR_TYPES as $type) {
            $this->patchJson(route('projects.large-planner.corridors.update', [$project, $segment]), [
                'planning_corridor_type' => $type,
            ])->assertOk()->assertJsonPath('segment.planning_corridor_type', $type);

            $this->assertSame($type, $segment->fresh()->planning_corridor_type);
        }

        $this->getJson(route('api.projects.map-data', $project))
            ->assertOk()
            ->assertJsonPath('gis_segments.0.planning_corridor_type', 'secondary');
    }

    public function test_corridor_classification_is_isolated_from_standard_projects(): void
    {
        $standard = Project::factory()->create(['planning_mode' => 'standard']);
        $segment = $this->segment($standard);

        $this->patchJson(route('projects.large-planner.corridors.update', [$standard, $segment]), [
            'planning_corridor_type' => 'main',
        ])->assertNotFound();

        $this->assertNull($segment->fresh()->planning_corridor_type);
    }

    public function test_segment_from_another_project_cannot_be_classified(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $other = Project::factory()->create(['planning_mode' => 'large_auto']);
        $segment = $this->segment($other);

        $this->patchJson(route('projects.large-planner.corridors.update', [$project, $segment]), [
            'planning_corridor_type' => 'primary',
        ])->assertNotFound();
    }

    public function test_invalid_corridor_type_is_rejected(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $segment = $this->segment($project);

        $this->patchJson(route('projects.large-planner.corridors.update', [$project, $segment]), [
            'planning_corridor_type' => 'drop',
        ])->assertUnprocessable()->assertJsonValidationErrors('planning_corridor_type');
    }

    private function segment(Project $project): GisSegment
    {
        return GisSegment::create([
            'project_id' => $project->id,
            'name' => 'Koridor A',
            'source' => 'test',
            'segment_type' => 'corridor',
            'is_allowed' => true,
            'length_m' => 100,
            'path' => [[43.85, 18.41], [43.86, 18.42]],
        ]);
    }
}
