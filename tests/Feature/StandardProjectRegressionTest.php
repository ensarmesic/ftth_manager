<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StandardProjectRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_standard_project_flow_remains_unchanged(): void
    {
        $this->post(route('projects.store'), [
            'name' => 'Standardni projekat',
            'code' => 'LEGACY-STANDARD',
            'location' => 'Sarajevo',
            'status' => 'planning',
        ])->assertRedirect();

        $project = Project::where('code', 'LEGACY-STANDARD')->firstOrFail();
        $this->assertSame('standard', $project->planning_mode);

        House::factory()->count(3)->create([
            'project_id' => $project->id,
            'latitude' => 43.8563,
            'longitude' => 18.4131,
        ]);

        $this->postJson(route('projects.odo-plan.preview', $project))
            ->assertOk()
            ->assertJsonPath('summary.houses_with_coordinates', 3);

        $this->assertSame(0, Cabinet::where('project_id', $project->id)->count());
        $this->assertSame(0, House::where('project_id', $project->id)->whereNotNull('cabinet_id')->count());

        NetworkRoute::create([
            'project_id' => $project->id,
            'name' => 'Postojeći standardni drop',
            'route_type' => 'drop',
            'path' => [[43.8563, 18.4131], [43.8564, 18.4132]],
        ]);
        $this->getJson(route('api.projects.map-data', $project))
            ->assertOk()
            ->assertJsonPath('routes.0.type', 'drop');

        $this->get(route('map.dashboard', ['project' => $project]))
            ->assertOk()
            ->assertSee('Auto raspored FTTH')
            ->assertDontSee('id="large-planner-workspace"', false);
    }
}
