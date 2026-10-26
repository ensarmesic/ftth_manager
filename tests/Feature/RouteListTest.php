<?php

namespace Tests\Feature;

use App\Models\NetworkRoute;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteListTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_list_shows_readable_labels_and_bosnian_numbers(): void
    {
        $project = Project::factory()->create();
        NetworkRoute::factory()->create([
            'project_id' => $project->id,
            'route_type' => 'feeder',
            'installation_type' => 'aerial',
            'duct_length_m' => 4679,
        ]);

        $this->get(route('routes.index', ['project' => $project->id]))
            ->assertOk()
            ->assertSee('Primarni (feeder)')
            ->assertSee('Zračno')
            ->assertSee('4.679');
    }

    public function test_edit_form_keeps_current_value_missing_from_options(): void
    {
        $project = Project::factory()->create();
        // An aerial route without a microduct: the edit select has no empty option.
        NetworkRoute::factory()->create(['project_id' => $project->id, 'microduct_type' => null]);

        $html = $this->get(route('routes.index', ['project' => $project->id]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<select name="microduct_type"[^>]*>\s*<option value=""\s+selected>/', $html);
    }

    public function test_route_without_cable_can_be_edited(): void
    {
        $route = NetworkRoute::factory()->create(['route_type' => 'drop', 'fiber_count' => 0, 'microduct_type' => null]);

        $this->patch(route('routes.update', $route), [
            'name' => 'Drop bez kabla',
            'route_type' => 'drop',
            'fiber_count' => 0,
            'microduct_type' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $route->fresh()->fiber_count);
        $this->assertSame('Drop bez kabla', $route->fresh()->name);
    }
}
