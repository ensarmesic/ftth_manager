<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LargeProjectRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_project_dashboard_and_overview_render_without_errors(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        House::factory()->count(300)->create(['project_id' => $project->id]);
        NetworkRoute::factory()->count(120)->create(['project_id' => $project->id]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->get(route('projects.show', $project))->assertOk()->assertSee($project->name);
        $this->get(route('projects.print', $project))->assertOk()->assertSee('Spremnost projekta');
    }
}
