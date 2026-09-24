<?php

namespace Tests\Feature;

use App\Models\GisSegment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerCorridorTest extends TestCase
{
    use RefreshDatabase;

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
