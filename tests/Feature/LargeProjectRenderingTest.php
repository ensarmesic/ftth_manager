<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkRoute;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LargeProjectRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_project_dashboard_and_overview_render_without_errors(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        Cabinet::factory()->count(200)->create(['project_id' => $project->id]);
        House::factory()->count(300)->create(['project_id' => $project->id]);
        NetworkRoute::factory()->count(120)->create(['project_id' => $project->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $dashboardQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $dashboardQueries, "Dashboard je izvršio {$dashboardQueries} SQL upita.");
        $this->get(route('projects.show', $project))->assertOk()->assertSee($project->name);
        $this->get(route('projects.print', $project))->assertOk()->assertSee('Spremnost projekta');
    }
}
