<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\House;
use App\Models\NetworkBranch;
use App\Models\NetworkRoute;
use App\Models\Odf;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectScopedNetworkPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_pages_only_show_data_for_the_selected_project(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $selected = Project::factory()->create(['name' => 'Odabrani projekat']);
        $other = Project::factory()->create(['name' => 'Drugi projekat']);

        foreach ([$selected, $other] as $project) {
            Odf::factory()->create(['project_id' => $project->id]);
            Cabinet::factory()->create(['project_id' => $project->id]);
            House::factory()->create(['project_id' => $project->id]);
            NetworkRoute::factory()->create(['project_id' => $project->id, 'route_type' => 'distribution']);
            NetworkBranch::factory()->create(['project_id' => $project->id, 'type' => 'secondary']);
        }

        foreach ([
            ['odfs.index', 'odfs'],
            ['cabinets.index', 'cabinets'],
            ['houses.index', 'houses'],
            ['routes.index', 'routes'],
            ['branches.index', 'branches'],
        ] as [$route, $viewData]) {
            $this->get(route($route, ['project' => $selected->id]))
                ->assertOk()
                ->assertViewHas('selectedProject', fn ($project) => $project->is($selected))
                ->assertViewHas($viewData, fn ($rows) => $rows->total() === 1
                    && (int) $rows->first()->project_id === (int) $selected->id);
        }
    }
}
