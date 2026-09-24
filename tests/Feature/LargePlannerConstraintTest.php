<?php

namespace Tests\Feature;

use App\Models\LargePlannerConstraint;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargePlannerConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_project_stores_required_waypoints_and_restricted_areas(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);

        $this->postJson(route('projects.large-planner.constraints.store', $project), [
            'type' => 'required_waypoint',
            'name' => 'Obavezni prolaz A',
            'geometry' => [43.8563, 18.4131],
        ])->assertCreated()->assertJsonPath('constraint.type', 'required_waypoint');

        $this->postJson(route('projects.large-planner.constraints.store', $project), [
            'type' => 'restricted_area',
            'name' => 'Zabranjena parcela',
            'geometry' => [[43.85, 18.41], [43.86, 18.41], [43.86, 18.42]],
        ])->assertCreated()->assertJsonPath('constraint.type', 'restricted_area');

        $this->assertSame(2, LargePlannerConstraint::where('project_id', $project->id)->count());
        $this->getJson(route('api.projects.map-data', $project))
            ->assertOk()
            ->assertJsonCount(2, 'large_planner_constraints');
    }

    public function test_invalid_constraint_geometry_is_rejected(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);

        $this->postJson(route('projects.large-planner.constraints.store', $project), [
            'type' => 'restricted_area',
            'geometry' => [[43.85, 18.41], [43.86, 18.42]],
        ])->assertUnprocessable()->assertJsonValidationErrors('geometry');
    }

    public function test_standard_project_cannot_store_large_planner_constraints(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'standard']);

        $this->postJson(route('projects.large-planner.constraints.store', $project), [
            'type' => 'required_waypoint',
            'geometry' => [43.8563, 18.4131],
        ])->assertNotFound();

        $this->assertDatabaseCount('large_planner_constraints', 0);
    }

    public function test_constraint_from_another_project_cannot_be_deleted(): void
    {
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);
        $other = Project::factory()->create(['planning_mode' => 'large_auto']);
        $constraint = $other->largePlannerConstraints()->create([
            'type' => 'required_waypoint',
            'geometry' => [43.8563, 18.4131],
        ]);

        $this->deleteJson(route('projects.large-planner.constraints.destroy', [$project, $constraint]))
            ->assertNotFound();

        $this->assertDatabaseHas('large_planner_constraints', ['id' => $constraint->id]);
    }
}
