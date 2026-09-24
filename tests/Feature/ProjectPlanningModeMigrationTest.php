<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProjectPlanningModeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_have_standard_planning_mode_by_default(): void
    {
        $projectId = DB::table('projects')->insertGetId([
            'name' => 'Postojeci projekat',
            'code' => 'STANDARD-001',
            'location' => 'Sarajevo',
            'status' => 'planning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue(Schema::hasColumn('projects', 'planning_mode'));
        $this->assertSame('standard', Project::findOrFail($projectId)->planning_mode);
    }

    public function test_designer_can_create_and_update_a_large_project_mode(): void
    {
        $this->actingAs(User::factory()->designer()->create());

        $this->post(route('projects.store'), [
            'name' => 'Veliki projekat',
            'code' => 'LARGE-001',
            'location' => 'Sarajevo',
            'status' => 'planning',
            'planning_mode' => 'large_auto',
        ])->assertRedirect();

        $project = Project::where('code', 'LARGE-001')->firstOrFail();
        $this->assertSame('large_auto', $project->planning_mode);

        $this->patch(route('projects.update', $project), [
            'name' => $project->name,
            'code' => $project->code,
            'location' => $project->location,
            'status' => $project->status,
            'planning_mode' => 'standard',
        ])->assertRedirect();

        $this->assertSame('standard', $project->fresh()->planning_mode);
    }

    public function test_invalid_planning_mode_is_rejected(): void
    {
        $this->actingAs(User::factory()->designer()->create());

        $this->post(route('projects.store'), [
            'name' => 'Neispravan projekat',
            'code' => 'INVALID-MODE',
            'location' => 'Sarajevo',
            'status' => 'planning',
            'planning_mode' => 'unsupported',
        ])->assertSessionHasErrors('planning_mode');

        $this->assertDatabaseMissing('projects', ['code' => 'INVALID-MODE']);
    }

    public function test_large_planner_workspace_is_only_rendered_for_large_projects(): void
    {
        $this->actingAs(User::factory()->designer()->create());
        $standard = Project::factory()->create(['planning_mode' => 'standard']);
        $large = Project::factory()->create(['planning_mode' => 'large_auto']);

        $this->get(route('map.dashboard', ['project' => $standard]))
            ->assertOk()
            ->assertDontSee('id="large-planner-workspace"', false);

        $this->get(route('map.dashboard', ['project' => $large]))
            ->assertOk()
            ->assertSee('id="large-planner-workspace"', false)
            ->assertSee('Veliki planer');
    }

    public function test_large_project_planner_settings_are_saved_separately(): void
    {
        $this->actingAs(User::factory()->designer()->create());
        $project = Project::factory()->create(['planning_mode' => 'large_auto']);

        $this->patch(route('projects.large-planner.settings.update', $project), [
            'odo_capacity' => 16,
            'max_drop_length_m' => 120,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ])->assertRedirect();

        $this->assertDatabaseHas('large_planner_settings', [
            'project_id' => $project->id,
            'odo_capacity' => 16,
            'max_drop_length_m' => 120,
            'fiber_reserve_percent' => 20,
            'optimization_goal' => 'weighted',
        ]);
    }

    public function test_standard_project_cannot_store_large_planner_settings(): void
    {
        $this->actingAs(User::factory()->designer()->create());
        $project = Project::factory()->create(['planning_mode' => 'standard']);

        $this->patch(route('projects.large-planner.settings.update', $project), [
            'odo_capacity' => 16,
        ])->assertNotFound();

        $this->assertDatabaseMissing('large_planner_settings', ['project_id' => $project->id]);
    }
}
